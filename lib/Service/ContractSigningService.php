<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Libresign\Service\ContractIntegrationService;
use OCA\Organization\Db\ContractSignRequest;
use OCA\Organization\Db\ContractSignRequestMapper;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ContractSigningService
{
    public function __construct(
        private ContractService $contractService,
        private ContractSignRequestMapper $requestMapper,
        private ContractIntegrationService $libresign,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /** @return ContractSignRequest[] */
    public function list(int $organizationId, int $contractId): array
    {
        $this->contractService->getCurrentVersion($organizationId, $contractId);
        $requests = $this->requestMapper->findAllForContract($organizationId, $contractId);
        foreach ($requests as $request) {
            $this->refresh($request);
        }
        return $requests;
    }

    /** @param list<array{displayName:string,email:string,signingOrder?:int}> $signers */
    public function createDraft(int $organizationId, int $contractId, array $signers, IUser $manager): array
    {
        $normalized = $this->normalizeSigners($signers);
        $version = $this->contractService->getCurrentVersion($organizationId, $contractId);
        $content = $this->contractService->readVersionContent($organizationId, $version);
        $draft = $this->libresign->createDraft($manager, $version->getOriginalFilename(), $content, $normalized);
        $uuid = (string) ($draft['uuid'] ?? '');
        if ($uuid === '') {
            throw new \RuntimeException('LibreSign did not return a file UUID');
        }

        try {
            $now = $this->now();
            $request = new ContractSignRequest();
            $request->setOrganizationId($organizationId);
            $request->setContractId($contractId);
            $request->setContractVersionId((int) $version->getId());
            $request->setLibresignFileUuid($uuid);
            $request->setStatus('draft');
            $request->setSignersJson((string) json_encode($normalized, JSON_THROW_ON_ERROR));
            $request->setSourceChecksum($version->getChecksum());
            $request->setCreatedByUid($manager->getUID());
            $request->setCreatedAt($now);
            $request->setUpdatedAt($now);
            $inserted = $this->requestMapper->insert($request);
        } catch (\Throwable $e) {
            try {
                $this->libresign->deleteDraft($manager, $uuid);
            } catch (\Throwable $cleanupError) {
                $this->logger->error('Could not clean up orphaned LibreSign draft', ['libresignFileUuid' => $uuid, 'exception' => $cleanupError]);
            }
            throw $e;
        }

        return ['request' => $inserted, 'placementUrl' => $this->placementUrl($organizationId, $uuid)];
    }

    public function send(int $organizationId, int $contractId, int $requestId, IUser $manager): ContractSignRequest
    {
        $request = $this->get($organizationId, $contractId, $requestId);
        if ($request->getStatus() !== 'draft') {
            throw new \InvalidArgumentException('Only draft requests can be sent');
        }
        if ($request->getCreatedByUid() !== $manager->getUID()) {
            throw new \InvalidArgumentException('Only the administrator who created this draft can send it');
        }
        $owner = $this->getRequestOwner($request);
        $this->libresign->send($owner, $request->getLibresignFileUuid());
        $now = $this->now();
        $request->setStatus('pending');
        $request->setSentAt($now);
        $request->setUpdatedAt($now);
        return $this->requestMapper->update($request);
    }

    public function placementUrlFor(int $organizationId, int $contractId, int $requestId, IUser $manager): string
    {
        $request = $this->get($organizationId, $contractId, $requestId);
        if ($request->getStatus() !== 'draft') {
            throw new \InvalidArgumentException('Only draft requests can be edited');
        }
        if ($request->getCreatedByUid() !== $manager->getUID()) {
            throw new \InvalidArgumentException('Only the administrator who created this draft can edit its field placement');
        }
        return $this->placementUrl($organizationId, $request->getLibresignFileUuid());
    }

    public function getPlacementData(int $organizationId, int $contractId, int $requestId, IUser $manager): array
    {
        $request = $this->requireEditableDraft($organizationId, $contractId, $requestId, $manager);
        $data = $this->libresign->getDraftPlacementData($manager, $request->getLibresignFileUuid());
        $data['document']['contentUrl'] = $this->urlGenerator->linkToRoute(
            'organization.Contract.signaturePlacementPdf',
            ['organizationId' => $organizationId, 'contractId' => $contractId, 'requestId' => $requestId],
        );
        return $data;
    }

    public function savePlacementElements(int $organizationId, int $contractId, int $requestId, IUser $manager, array $elements): array
    {
        $request = $this->requireEditableDraft($organizationId, $contractId, $requestId, $manager);
        return $this->libresign->replaceDraftSignatureElements($manager, $request->getLibresignFileUuid(), $elements);
    }

    public function getPlacementPdf(int $organizationId, int $contractId, int $requestId, IUser $manager): string
    {
        $request = $this->requireEditableDraft($organizationId, $contractId, $requestId, $manager);
        return $this->libresign->getDraftPdf($manager, $request->getLibresignFileUuid());
    }

    public function getSignedFile(int $organizationId, int $contractId, int $requestId): \OCP\Files\SimpleFS\ISimpleFile
    {
        $request = $this->get($organizationId, $contractId, $requestId);
        $storageKey = $request->getSignedStorageKey();
        if ($storageKey === null) {
            throw new \OutOfBoundsException('Signed contract is not available');
        }
        return $this->contractService->getStoredFile($organizationId, $storageKey);
    }

    private function refresh(ContractSignRequest $request): void
    {
        try {
            $manager = $this->getRequestOwner($request);
            $data = $this->libresign->getStatus($manager, $request->getLibresignFileUuid());
            $status = match ((int) ($data['status'] ?? 0)) {
                1, 2, 5 => 'pending',
                3 => 'completed',
                4 => 'cancelled',
                default => 'draft',
            };
            $signers = $this->signersFromLibreSign($data);
            $signersJson = (string) json_encode($signers, JSON_THROW_ON_ERROR);
            $changed = $status !== $request->getStatus() || $signersJson !== $request->getSignersJson();
            if ($changed) {
                $request->setStatus($status);
                $request->setSignersJson($signersJson);
                $request->setUpdatedAt($this->now());
                if ($status === 'completed') {
                    $request->setCompletedAt($this->now());
                }
                $this->requestMapper->update($request);
            }
            if ($status === 'completed' && $request->getSignedStorageKey() === null) {
                $signedPdf = $this->libresign->getSignedPdf($manager, $request->getLibresignFileUuid());
                $stored = $this->contractService->storeSignedCopy($request->getOrganizationId(), $signedPdf);
                $request->setSignedStorageKey($stored['storageKey']);
                $request->setSignedChecksum($stored['checksum']);
                $request->setUpdatedAt($this->now());
                $this->requestMapper->update($request);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not synchronize contract signature request', [
                'requestId' => $request->getId(),
                'libresignFileUuid' => $request->getLibresignFileUuid(),
                'exception' => $e,
            ]);
        }
    }

    private function getRequestOwner(ContractSignRequest $request): IUser
    {
        $user = $this->userManager->get($request->getCreatedByUid());
        if ($user === null) {
            throw new \RuntimeException('The signing request owner no longer exists');
        }
        return $user;
    }

    /** @param array<string,mixed> $data @return list<array{displayName:string,email:string,signingOrder:int,status:string}> */
    private function signersFromLibreSign(array $data): array
    {
        $result = [];
        foreach (($data['signers'] ?? []) as $signer) {
            $email = '';
            foreach (($signer['identifyMethods'] ?? []) as $method) {
                if (($method['method'] ?? '') === 'email') {
                    $email = (string) ($method['value'] ?? '');
                    break;
                }
            }
            $result[] = [
                'displayName' => (string) ($signer['displayName'] ?? ''),
                'email' => $email,
                'signingOrder' => (int) ($signer['signingOrder'] ?? 0),
                'status' => (string) ($signer['statusText'] ?? 'Draft'),
            ];
        }
        return $result;
    }

    private function get(int $organizationId, int $contractId, int $requestId): ContractSignRequest
    {
        $request = $this->requestMapper->findForContract($organizationId, $contractId, $requestId);
        if ($request === null) {
            throw new \OutOfBoundsException('Signature request not found');
        }
        return $request;
    }

    private function requireEditableDraft(int $organizationId, int $contractId, int $requestId, IUser $manager): ContractSignRequest
    {
        $request = $this->get($organizationId, $contractId, $requestId);
        if ($request->getStatus() !== 'draft') {
            throw new \InvalidArgumentException('Only draft requests can be edited');
        }
        if ($request->getCreatedByUid() !== $manager->getUID()) {
            throw new \InvalidArgumentException('Only the administrator who created this draft can edit its field placement');
        }
        return $request;
    }

    /** @param list<array{displayName:string,email:string,signingOrder?:int}> $signers @return list<array{displayName:string,email:string,signingOrder:int}> */
    private function normalizeSigners(array $signers): array
    {
        if ($signers === []) {
            throw new \InvalidArgumentException('Add at least one signer');
        }
        $normalized = [];
        foreach ($signers as $index => $signer) {
            $email = strtolower(trim((string) ($signer['email'] ?? '')));
            $name = trim((string) ($signer['displayName'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                throw new \InvalidArgumentException('Every signer needs a name and valid email address');
            }
            $normalized[] = ['displayName' => mb_substr($name, 0, 255), 'email' => mb_substr($email, 0, 255), 'signingOrder' => $index + 1];
        }
        return $normalized;
    }

    private function placementUrl(int $organizationId, string $uuid): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('signatures.page.indexFPath', ['path' => 'request/' . $uuid . '/placement'])
            . '?organizationId=' . $organizationId;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
