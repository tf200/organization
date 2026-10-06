<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One external's access to one project.
 *
 * @method int getId()
 * @method int getOrganizationId()
 * @method void setOrganizationId(int $organizationId)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getFunctionalRoleKeys()
 * @method void setFunctionalRoleKeys(?string $functionalRoleKeys)
 * @method ?string getDrasciRoles()
 * @method void setDrasciRoles(?string $drasciRoles)
 * @method string getInvitedBy()
 * @method void setInvitedBy(string $invitedBy)
 * @method ?string getRevokedBy()
 * @method void setRevokedBy(?string $revokedBy)
 * @method ?\DateTime getInvitedAt()
 * @method void setInvitedAt(\DateTime $invitedAt)
 * @method ?\DateTime getAcceptedAt()
 * @method void setAcceptedAt(?\DateTime $acceptedAt)
 * @method ?\DateTime getExpiresAt()
 * @method void setExpiresAt(?\DateTime $expiresAt)
 * @method ?\DateTime getRevokedAt()
 * @method void setRevokedAt(?\DateTime $revokedAt)
 * @method ?\DateTime getWarnedAt()
 * @method void setWarnedAt(?\DateTime $warnedAt)
 * @method ?\DateTime getFolderReleasedAt()
 * @method void setFolderReleasedAt(?\DateTime $folderReleasedAt)
 */
class ExternalGrant extends Entity implements \JsonSerializable
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public ?int $organizationId = null;
    public ?int $projectId = null;
    public ?string $userUid = null;
    public ?string $status = null;
    public ?string $functionalRoleKeys = null;
    public ?string $drasciRoles = null;
    public ?string $invitedBy = null;
    public ?string $revokedBy = null;
    public ?\DateTime $invitedAt = null;
    public ?\DateTime $acceptedAt = null;
    public ?\DateTime $expiresAt = null;
    public ?\DateTime $revokedAt = null;
    public ?\DateTime $warnedAt = null;
    public ?\DateTime $folderReleasedAt = null;

    public function __construct()
    {
        $this->addType('organizationId', Types::INTEGER);
        $this->addType('projectId', Types::INTEGER);
        $this->addType('userUid', Types::STRING);
        $this->addType('status', Types::STRING);
        $this->addType('functionalRoleKeys', Types::STRING);
        $this->addType('drasciRoles', Types::STRING);
        $this->addType('invitedBy', Types::STRING);
        $this->addType('revokedBy', Types::STRING);
        $this->addType('invitedAt', Types::DATETIME);
        $this->addType('acceptedAt', Types::DATETIME);
        $this->addType('expiresAt', Types::DATETIME);
        $this->addType('revokedAt', Types::DATETIME);
        $this->addType('warnedAt', Types::DATETIME);
        $this->addType('folderReleasedAt', Types::DATETIME);
    }

    /** @return string[] */
    public function getFunctionalRoleKeyList(): array
    {
        return self::decodeList($this->functionalRoleKeys);
    }

    /** @param string[] $keys */
    public function setFunctionalRoleKeyList(array $keys): void
    {
        $this->setFunctionalRoleKeys(json_encode(array_values($keys), JSON_THROW_ON_ERROR));
    }

    /** @return string[] */
    public function getDrasciRoleList(): array
    {
        return self::decodeList($this->drasciRoles);
    }

    /** @param string[] $roles */
    public function setDrasciRoleList(array $roles): void
    {
        $this->setDrasciRoles(json_encode(array_values($roles), JSON_THROW_ON_ERROR));
    }

    /**
     * Active and not past its end date.
     */
    public function isUsable(\DateTimeInterface $now): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'organizationId' => $this->organizationId,
            'projectId' => $this->projectId,
            'userId' => $this->userUid,
            'status' => $this->status,
            'functionalRoleKeys' => $this->getFunctionalRoleKeyList(),
            'drascivsRoles' => $this->getDrasciRoleList(),
            'invitedBy' => $this->invitedBy,
            'invitedAt' => $this->invitedAt?->format(DATE_ATOM),
            'acceptedAt' => $this->acceptedAt?->format(DATE_ATOM),
            'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
            'revokedAt' => $this->revokedAt?->format(DATE_ATOM),
        ];
    }

    /** @return string[] */
    private static function decodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
