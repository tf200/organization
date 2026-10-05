<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\External;
use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\ExternalGrantMapper;
use OCA\Organization\Db\ExternalInvite;
use OCA\Organization\Db\ExternalInviteMapper;
use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Event\ExternalGrantActivatedEvent;
use OCA\Organization\Event\ExternalGrantRevokedEvent;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * External collaborators: real Nextcloud accounts that work on single projects
 * through per-project grants, without an organization_members row.
 *
 * Callers check who may invite or revoke; this service checks the rules that
 * belong to the organization (seats, subscription, one organization per user).
 */
class ExternalCollaboratorService
{
    public const EXTERNALS_GROUP = 'externals';
    private const INVITE_VALID_DAYS = 7;
    private const STATUS_CONFLICT = 409;

    public function __construct(
        private ExternalMapper $externalMapper,
        private ExternalGrantMapper $grantMapper,
        private ExternalInviteMapper $inviteMapper,
        private UserMapper $userMapper,
        private OrganizationMapper $organizationMapper,
        private SubscriptionMapper $subscriptionMapper,
        private PlanMapper $planMapper,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private ISecureRandom $secureRandom,
        private IMailer $mailer,
        private IURLGenerator $urlGenerator,
        private IEventDispatcher $eventDispatcher,
        private IDBConnection $db,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Invites someone to a project of the organization. A new email gets an
     * account and a one-time link; a known, active external gets access at once.
     *
     * @param string[] $functionalRoleKeys
     * @param string[] $drasciRoles
     * @return array{external: External, grant: ExternalGrant, activated: bool, emailSent: bool, inviteUrl: ?string}
     */
    public function invite(
        int $organizationId,
        int $projectId,
        string $inviterUid,
        string $email,
        string $displayName,
        ?string $company,
        ?string $phone,
        ?\DateTime $expiresAt,
        array $functionalRoleKeys,
        array $drasciRoles,
    ): array {
        $email = mb_strtolower(trim($email));
        $displayName = trim($displayName);
        if (!$this->mailer->validateMailAddress($email)) {
            throw new OCSBadRequestException('A valid email address is required.');
        }
        if ($displayName === '') {
            throw new OCSBadRequestException('A name is required.');
        }
        $now = $this->now();
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new OCSBadRequestException('The end date must be in the future.');
        }

        $this->assertSubscriptionUsable($organizationId);
        $external = $this->findReusableExternal($email, $organizationId);

        $existingGrant = $external === null ? null : $this->grantMapper->findByProjectAndUser($projectId, $external->getUserUid());
        if ($existingGrant !== null && in_array($existingGrant->getStatus(), [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE], true)) {
            throw new OCSException('This person is already invited to the project.', self::STATUS_CONFLICT);
        }
        if ($external === null || !$this->grantMapper->holdsSeat($organizationId, $external->getUserUid())) {
            $this->assertSeatAvailable($organizationId);
        }

        $createdUser = null;
        $token = null;
        $this->db->beginTransaction();
        try {
            if ($external === null) {
                [$external, $createdUser] = $this->createExternal($email, $displayName, $company, $phone, $inviterUid, $now);
            }

            $activateNow = $external->getStatus() === External::STATUS_ACTIVE;
            $grant = $existingGrant ?? new ExternalGrant();
            $grant->setOrganizationId($organizationId);
            $grant->setProjectId($projectId);
            $grant->setUserUid($external->getUserUid());
            $grant->setStatus($activateNow ? ExternalGrant::STATUS_ACTIVE : ExternalGrant::STATUS_PENDING);
            $grant->setFunctionalRoleKeyList($functionalRoleKeys);
            $grant->setDrasciRoleList($drasciRoles);
            $grant->setInvitedBy($inviterUid);
            $grant->setInvitedAt($now);
            $grant->setAcceptedAt($activateNow ? $now : null);
            $grant->setExpiresAt($expiresAt);
            $grant->setRevokedAt(null);
            $grant->setRevokedBy(null);
            $grant->setWarnedAt(null);
            $grant = $existingGrant === null ? $this->grantMapper->insert($grant) : $this->grantMapper->update($grant);

            if (!$activateNow) {
                $token = $this->createInvite($external->getUserUid(), (int) $grant->getId(), $now);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            $createdUser?->delete();
            throw $e;
        }

        if ($activateNow) {
            $this->dispatchActivated($grant);
        }

        $inviteUrl = $token === null ? null : $this->inviteUrl($token);
        $emailSent = $this->sendInviteEmail($external, $organizationId, $projectId, $inviterUid, $inviteUrl);

        return [
            'external' => $external,
            'grant' => $grant,
            'activated' => $activateNow,
            'emailSent' => $emailSent,
            // The link goes back to the inviter only when the email could not be
            // sent, so they can pass it on another way.
            'inviteUrl' => $emailSent ? null : $inviteUrl,
        ];
    }

    /**
     * @return array{external: External, grants: ExternalGrant[]}|null
     */
    public function findOpenInvite(string $token): ?array
    {
        $invite = $this->inviteMapper->findByTokenHash($this->hashToken($token));
        if ($invite === null || !$invite->isUsable($this->now())) {
            return null;
        }

        $external = $this->externalMapper->findByUserUid($invite->getUserUid());
        if ($external === null || $external->getStatus() !== External::STATUS_INVITED) {
            return null;
        }

        return [
            'external' => $external,
            'grants' => $this->grantMapper->findByUser($external->getUserUid(), [ExternalGrant::STATUS_PENDING]),
        ];
    }

    /**
     * The invited person sets a password. Every pending grant of the account
     * activates, not only the one in this link.
     *
     * @throws OCSNotFoundException when the link is unknown, used or expired
     * @throws OCSBadRequestException when the password is rejected
     */
    public function acceptInvite(string $token, string $password): External
    {
        $invite = $this->inviteMapper->findByTokenHash($this->hashToken($token));
        $now = $this->now();
        if ($invite === null || !$invite->isUsable($now)) {
            throw new OCSNotFoundException('This invitation link is no longer valid.');
        }

        $external = $this->externalMapper->findByUserUid($invite->getUserUid());
        $user = $this->userManager->get($invite->getUserUid());
        if ($external === null || $user === null || $external->getStatus() !== External::STATUS_INVITED) {
            throw new OCSNotFoundException('This invitation link is no longer valid.');
        }

        try {
            if (!$user->setPassword($password)) {
                throw new OCSBadRequestException('The password could not be set.');
            }
        } catch (HintException $e) {
            throw new OCSBadRequestException($e->getHint());
        }

        $this->db->beginTransaction();
        try {
            $this->inviteMapper->invalidateOpenForUser($external->getUserUid(), $now);
            $external->setStatus(External::STATUS_ACTIVE);
            $external->setActivatedAt($now);
            $this->externalMapper->update($external);

            $activated = [];
            foreach ($this->grantMapper->findByUser($external->getUserUid(), [ExternalGrant::STATUS_PENDING]) as $grant) {
                if ($grant->getExpiresAt() !== null && $grant->getExpiresAt() <= $now) {
                    continue;
                }
                $grant->setStatus(ExternalGrant::STATUS_ACTIVE);
                $grant->setAcceptedAt($now);
                $activated[] = $this->grantMapper->update($grant);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach ($activated as $grant) {
            $this->dispatchActivated($grant);
        }

        return $external;
    }

    public function revoke(int $organizationId, int $projectId, string $userUid, string $revokedBy): ExternalGrant
    {
        $grant = $this->grantMapper->findByProjectAndUser($projectId, $userUid);
        if ($grant === null || $grant->getOrganizationId() !== $organizationId) {
            throw new OCSNotFoundException('External collaborator not found on this project.');
        }
        if (!in_array($grant->getStatus(), [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE], true)) {
            return $grant;
        }

        $wasActive = $grant->getStatus() === ExternalGrant::STATUS_ACTIVE;
        $now = $this->now();
        $grant->setStatus(ExternalGrant::STATUS_REVOKED);
        $grant->setRevokedAt($now);
        $grant->setRevokedBy($revokedBy);
        $grant = $this->grantMapper->update($grant);

        if ($this->grantMapper->findByUser($userUid, [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE]) === []) {
            $this->inviteMapper->invalidateOpenForUser($userUid, $now);
        }

        if ($wasActive) {
            $this->eventDispatcher->dispatchTyped(new ExternalGrantRevokedEvent(
                $organizationId,
                $projectId,
                $userUid,
                ExternalGrantRevokedEvent::REASON_REVOKED,
            ));
        }

        return $grant;
    }

    /**
     * @return array<int,array<string,mixed>> grants of the project with the external's profile
     */
    public function listForProject(int $projectId): array
    {
        $grants = $this->grantMapper->findByProject($projectId);
        $externals = $this->externalMapper->findByUserUids(array_map(
            static fn (ExternalGrant $grant): string => $grant->getUserUid(),
            $grants,
        ));

        return array_map(static function (ExternalGrant $grant) use ($externals): array {
            $external = $externals[$grant->getUserUid()] ?? null;
            return $grant->jsonSerialize() + [
                'displayName' => $external?->getDisplayName(),
                'email' => $external?->getEmail(),
                'company' => $external?->getCompany(),
                'accountStatus' => $external?->getStatus(),
            ];
        }, $grants);
    }

    public function isExternal(string $userUid): bool
    {
        return $this->externalMapper->findByUserUid($userUid) !== null;
    }

    /**
     * Grants that open a project right now: active, not past their end date,
     * and from an organization whose subscription is running.
     *
     * @return ExternalGrant[]
     */
    public function getUsableGrants(string $userUid): array
    {
        $now = $this->now();
        $subscriptionUsable = [];
        $usable = [];
        foreach ($this->grantMapper->findByUser($userUid, [ExternalGrant::STATUS_ACTIVE]) as $grant) {
            if (!$grant->isUsable($now)) {
                continue;
            }
            $organizationId = $grant->getOrganizationId();
            $subscriptionUsable[$organizationId] ??= $this->subscriptionProblem($organizationId) === null;
            if ($subscriptionUsable[$organizationId]) {
                $usable[] = $grant;
            }
        }
        return $usable;
    }

    /**
     * Members plus distinct externals with a pending or active grant.
     */
    public function countUsedSeats(int $organizationId): int
    {
        return $this->userMapper->countUsersInOrganization($organizationId)
            + $this->grantMapper->countSeatHolders($organizationId);
    }

    public function assertSeatAvailable(int $organizationId): void
    {
        $subscription = $this->subscriptionMapper->findByOrganizationId($organizationId);
        if ($subscription === null) {
            throw new OCSNotFoundException('No subscription found for this organization');
        }

        $plan = $this->planMapper->find($subscription->getPlanId());
        if ($plan === null) {
            throw new OCSNotFoundException('No plan found for this organization');
        }

        $maxMembers = (int) $plan->getMaxMembers();
        $usedSeats = $this->countUsedSeats($organizationId);
        if ($usedSeats >= $maxMembers) {
            throw new OCSForbiddenException(sprintf(
                'Organization member limit reached for current subscription plan (%d of %d seats used, external collaborators included)',
                $usedSeats,
                $maxMembers,
            ));
        }
    }

    private function assertSubscriptionUsable(int $organizationId): void
    {
        $problem = $this->subscriptionProblem($organizationId);
        if ($problem !== null) {
            throw new OCSForbiddenException($problem);
        }
    }

    /**
     * Same rules as SubscriptionMiddleware: running until the end date, and
     * active or cancelled.
     */
    private function subscriptionProblem(int $organizationId): ?string
    {
        $subscription = $this->subscriptionMapper->findByOrganizationId($organizationId);
        if ($subscription === null) {
            return 'The organization has no subscription.';
        }

        $endedAt = $subscription->getEndedAt();
        if ($endedAt === null || new \DateTime($endedAt, new \DateTimeZone('UTC')) < $this->now()) {
            return 'The organization subscription has expired.';
        }

        if (!in_array($subscription->getStatus(), ['active', 'cancelled'], true)) {
            return 'The organization subscription is not active.';
        }

        return null;
    }

    /**
     * The external that owns this email, or null when a new account is needed.
     * Emails of organization members and of other accounts are refused.
     */
    private function findReusableExternal(string $email, int $organizationId): ?External
    {
        $external = $this->externalMapper->findByEmail($email);
        if ($external !== null) {
            if (in_array($external->getStatus(), [External::STATUS_SUSPENDED, External::STATUS_DISABLED], true)) {
                throw new OCSForbiddenException('This external account is disabled.');
            }
            return $external;
        }

        foreach ($this->userManager->getByEmail($email) as $user) {
            $membership = $this->userMapper->getOrganizationMembership($user->getUID());
            if ($membership !== null && $membership['organization_id'] === $organizationId) {
                throw new OCSException('This person is already a member of the organization. Add them to the project as a member.', self::STATUS_CONFLICT);
            }
            if ($membership !== null) {
                throw new OCSException('This email belongs to a member of another organization.', self::STATUS_CONFLICT);
            }
            throw new OCSException('An account with this email already exists.', self::STATUS_CONFLICT);
        }

        return null;
    }

    /**
     * @return array{0: External, 1: IUser}
     */
    private function createExternal(string $email, string $displayName, ?string $company, ?string $phone, string $createdBy, \DateTime $now): array
    {
        $userUid = $this->generateUserUid();
        // The person never learns this password; they set their own when accepting.
        $password = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC . ISecureRandom::CHAR_SYMBOLS);
        $user = $this->userManager->createUser($userUid, $password);
        if ($user === false) {
            throw new OCSException('The external account could not be created.', 500);
        }

        try {
            $user->setDisplayName($company !== null && trim($company) !== '' ? sprintf('%s (%s)', $displayName, trim($company)) : $displayName);
            $user->setSystemEMailAddress($email);

            $group = $this->groupManager->get(self::EXTERNALS_GROUP) ?? $this->groupManager->createGroup(self::EXTERNALS_GROUP);
            $group?->addUser($user);

            $external = new External();
            $external->setUserUid($userUid);
            $external->setEmail($email);
            $external->setDisplayName($displayName);
            $external->setCompany($this->nullIfBlank($company));
            $external->setPhone($this->nullIfBlank($phone));
            $external->setStatus(External::STATUS_INVITED);
            $external->setCreatedBy($createdBy);
            $external->setCreatedAt($now);
            $external = $this->externalMapper->insert($external);
        } catch (Throwable $e) {
            $user->delete();
            throw $e;
        }

        return [$external, $user];
    }

    private function createInvite(string $userUid, int $grantId, \DateTime $now): string
    {
        $this->inviteMapper->invalidateOpenForUser($userUid, $now);

        $token = $this->secureRandom->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
        $invite = new ExternalInvite();
        $invite->setUserUid($userUid);
        $invite->setGrantId($grantId);
        $invite->setTokenHash($this->hashToken($token));
        $invite->setExpiresAt((clone $now)->modify('+' . self::INVITE_VALID_DAYS . ' days'));
        $invite->setLastSentAt($now);
        $invite->setSendCount(1);
        $this->inviteMapper->insert($invite);

        return $token;
    }

    private function sendInviteEmail(External $external, int $organizationId, int $projectId, string $inviterUid, ?string $inviteUrl): bool
    {
        $organizationName = $this->organizationMapper->find($organizationId)?->getName() ?? '';
        $projectName = $this->projectName($projectId);
        $inviterName = $this->userManager->getDisplayName($inviterUid) ?? $inviterUid;
        $link = $inviteUrl ?? $this->urlGenerator->getAbsoluteURL('/');

        try {
            $template = $this->mailer->createEMailTemplate('organization.ExternalInvite', [
                'organizationId' => $organizationId,
                'projectId' => $projectId,
            ]);
            $template->setSubject(sprintf('%s invited you to the project %s', $inviterName, $projectName));
            $template->addHeader();
            $template->addHeading(sprintf('You were invited to %s', $projectName));
            $template->addBodyText(sprintf('Hello %s,', $external->getDisplayName()));
            $template->addBodyText(sprintf(
                '%s from %s invited you to work on the project %s.',
                $inviterName,
                $organizationName,
                $projectName,
            ));
            if ($inviteUrl !== null) {
                $template->addBodyText(sprintf('Set your password to get started. The link is valid for %d days.', self::INVITE_VALID_DAYS));
                $template->addBodyButton('Accept invitation', $link);
            } else {
                $template->addBodyButton('Open the project', $link);
            }
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$external->getEmail() => $external->getDisplayName()]);
            $message->useTemplate($template);
            $message->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);
            $failed = $this->mailer->send($message);
            return $failed === [];
        } catch (Throwable $e) {
            $this->logger->warning('Could not send the external collaborator invitation', [
                'userId' => $external->getUserUid(),
                'projectId' => $projectId,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function dispatchActivated(ExternalGrant $grant): void
    {
        $this->eventDispatcher->dispatchTyped(new ExternalGrantActivatedEvent(
            $grant->getOrganizationId(),
            $grant->getProjectId(),
            $grant->getUserUid(),
            $grant->getFunctionalRoleKeyList(),
            $grant->getDrasciRoleList(),
        ));
    }

    public function projectName(int $projectId): string
    {
        if (!$this->db->tableExists('custom_projects')) {
            return '#' . $projectId;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('name')->from('custom_projects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($projectId, \PDO::PARAM_INT)));
        $result = $qb->executeQuery();
        $name = $result->fetchOne();
        $result->closeCursor();

        return is_string($name) && $name !== '' ? $name : '#' . $projectId;
    }

    private function inviteUrl(string $token): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('organization.Invite.show', ['token' => $token]);
    }

    private function generateUserUid(): string
    {
        do {
            $userUid = 'ext_' . $this->secureRandom->generate(10, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        } while ($this->userManager->userExists($userUid));

        return $userUid;
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }

    private function now(): \DateTime
    {
        return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'));
    }
}
