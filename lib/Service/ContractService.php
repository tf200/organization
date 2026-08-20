<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\Contract;
use OCA\Organization\Db\ContractMapper;
use OCA\Organization\Db\ContractVersion;
use OCA\Organization\Db\ContractVersionMapper;
use OCA\Organization\Db\ContractSignRequestMapper;
use OCA\Organization\Db\OrganizationMapper;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;

class ContractService
{
    public const MAX_FILE_SIZE = 25 * 1024 * 1024;
    private const CONTRACTS_FOLDER = 'contracts';

    public function __construct(
        private ContractMapper $contractMapper,
        private OrganizationMapper $organizationMapper,
        private IAppDataFactory $appDataFactory,
        private IMimeTypeDetector $mimeTypeDetector,
        private IDBConnection $db,
        private ContractVersionMapper $contractVersionMapper,
        private ContractSignRequestMapper $contractSignRequestMapper,
    ) {
    }

    /** @return Contract[] */
    public function list(int $organizationId): array
    {
        $this->assertOrganizationExists($organizationId);
        return $this->contractMapper->findAllForOrganization($organizationId);
    }

    /** @param array<string,mixed> $upload */
    public function create(int $organizationId, array $upload, string $displayName, string $userId): Contract
    {
        $this->assertOrganizationExists($organizationId);
        $file = $this->validateUpload($upload);
        $storageKey = bin2hex(random_bytes(16)) . '.pdf';
        $this->store($organizationId, $storageKey, $file['tmpName']);

        $now = $this->now();
        $contract = new Contract();
        $contract->setOrganizationId($organizationId);
        $contract->setDisplayName($this->normalizeDisplayName($displayName, $file['originalFilename']));
        $contract->setOriginalFilename($file['originalFilename']);
        $contract->setStorageKey($storageKey);
        $contract->setMimeType('application/pdf');
        $contract->setFileSize($file['fileSize']);
        $contract->setChecksum($file['checksum']);
        $contract->setUploadedByUid($userId);
        $contract->setCreatedAt($now);
        $contract->setUpdatedAt($now);

        $this->db->beginTransaction();
        try {
            $inserted = $this->contractMapper->insert($contract);
            $this->createVersion($inserted, 1);
            $this->db->commit();
            return $inserted;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->deleteStoredFile($organizationId, $storageKey);
            throw $e;
        }
    }

    public function rename(int $organizationId, int $contractId, string $displayName): Contract
    {
        $contract = $this->get($organizationId, $contractId);
        $contract->setDisplayName($this->normalizeDisplayName($displayName, $contract->getOriginalFilename()));
        $contract->setUpdatedAt($this->now());
        return $this->contractMapper->update($contract);
    }

    /** @param array<string,mixed> $upload */
    public function replace(int $organizationId, int $contractId, array $upload, string $userId): Contract
    {
        $contract = $this->get($organizationId, $contractId);
        $file = $this->validateUpload($upload);
        $newStorageKey = bin2hex(random_bytes(16)) . '.pdf';
        $this->store($organizationId, $newStorageKey, $file['tmpName']);

        $contract->setOriginalFilename($file['originalFilename']);
        $contract->setStorageKey($newStorageKey);
        $contract->setMimeType('application/pdf');
        $contract->setFileSize($file['fileSize']);
        $contract->setChecksum($file['checksum']);
        $contract->setUploadedByUid($userId);
        $contract->setUpdatedAt($this->now());

        $this->db->beginTransaction();
        try {
            $updated = $this->contractMapper->update($contract);
            $latest = $this->contractVersionMapper->findLatest($contractId);
            $this->createVersion($updated, ($latest?->getVersionNumber() ?? 0) + 1);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->deleteStoredFile($organizationId, $newStorageKey);
            throw $e;
        }

        return $updated;
    }

    public function delete(int $organizationId, int $contractId): void
    {
        $contract = $this->get($organizationId, $contractId);
        if ($this->contractSignRequestMapper->existsForContract($organizationId, $contractId)) {
            throw new \InvalidArgumentException('Contracts with signature requests cannot be deleted');
        }
        $versions = $this->contractVersionMapper->findAll($contractId);
        $this->db->beginTransaction();
        try {
            foreach ($versions as $version) {
                $this->contractVersionMapper->delete($version);
            }
            $this->contractMapper->delete($contract);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        foreach ($versions as $version) {
            $this->deleteStoredFile($organizationId, $version->getStorageKey());
        }
        if ($versions === []) {
            $this->deleteStoredFile($organizationId, $contract->getStorageKey());
        }
    }

    public function getFile(int $organizationId, int $contractId): array
    {
        $contract = $this->get($organizationId, $contractId);
        $folder = $this->getOrganizationFolder($organizationId, false);
        if (!$folder->fileExists($contract->getStorageKey())) {
            throw new \RuntimeException('Contract file is missing');
        }

        return ['contract' => $contract, 'file' => $folder->getFile($contract->getStorageKey())];
    }

    public function getCurrentVersion(int $organizationId, int $contractId): ContractVersion
    {
        $contract = $this->get($organizationId, $contractId);
        $version = $this->contractVersionMapper->findLatest($contractId);
        return $version ?? $this->createVersion($contract, 1);
    }

    public function readVersionContent(int $organizationId, ContractVersion $version): string
    {
        $folder = $this->getOrganizationFolder($organizationId, false);
        if (!$folder->fileExists($version->getStorageKey())) {
            throw new \RuntimeException('Contract version file is missing');
        }
        return $folder->getFile($version->getStorageKey())->getContent();
    }

    /** @return array{storageKey:string,checksum:string} */
    public function storeSignedCopy(int $organizationId, string $content): array
    {
        $this->validateSignedPdf($content);
        $storageKey = 'signed-' . bin2hex(random_bytes(16)) . '.pdf';
        $file = $this->getOrganizationFolder($organizationId, true)->newFile($storageKey);
        $file->putContent($content);
        return ['storageKey' => $storageKey, 'checksum' => hash('sha256', $content)];
    }

    public function replaceSignedCopy(int $organizationId, string $storageKey, string $content): string
    {
        $this->validateSignedPdf($content);
        if (!str_starts_with($storageKey, 'signed-')) {
            throw new \InvalidArgumentException('Invalid signed contract storage key');
        }
        $this->getStoredFile($organizationId, $storageKey)->putContent($content);
        return hash('sha256', $content);
    }

    public function getStoredFile(int $organizationId, string $storageKey): \OCP\Files\SimpleFS\ISimpleFile
    {
        $folder = $this->getOrganizationFolder($organizationId, false);
        if (!$folder->fileExists($storageKey)) {
            throw new \RuntimeException('Stored contract file is missing');
        }
        return $folder->getFile($storageKey);
    }

    private function get(int $organizationId, int $contractId): Contract
    {
        $contract = $this->contractMapper->findForOrganization($organizationId, $contractId);
        if ($contract === null) {
            throw new \OutOfBoundsException('Contract not found');
        }
        return $contract;
    }

    private function validateSignedPdf(string $content): void
    {
        if (!str_starts_with($content, '%PDF-')) {
            throw new \RuntimeException('LibreSign returned an invalid signed PDF');
        }
    }

    private function createVersion(Contract $contract, int $versionNumber): ContractVersion
    {
        $version = new ContractVersion();
        $version->setContractId((int) $contract->getId());
        $version->setVersionNumber($versionNumber);
        $version->setOriginalFilename($contract->getOriginalFilename());
        $version->setStorageKey($contract->getStorageKey());
        $version->setMimeType($contract->getMimeType());
        $version->setFileSize($contract->getFileSize());
        $version->setChecksum($contract->getChecksum());
        $version->setUploadedByUid($contract->getUploadedByUid());
        $version->setCreatedAt($contract->getUpdatedAt());
        return $this->contractVersionMapper->insert($version);
    }

    /** @param array<string,mixed> $upload @return array{tmpName:string,originalFilename:string,fileSize:int,checksum:string} */
    private function validateUpload(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('The PDF upload failed');
        }
        $tmpName = (string) ($upload['tmp_name'] ?? '');
        $fileSize = (int) ($upload['size'] ?? 0);
        if ($tmpName === '' || !is_readable($tmpName) || $fileSize < 1) {
            throw new \InvalidArgumentException('The uploaded PDF is empty');
        }
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('The PDF must be 25 MB or smaller');
        }
        if ($this->mimeTypeDetector->detectContent($tmpName) !== 'application/pdf') {
            throw new \InvalidArgumentException('Only PDF files are allowed');
        }
        $header = file_get_contents($tmpName, false, null, 0, 5);
        if ($header !== '%PDF-') {
            throw new \InvalidArgumentException('The uploaded file is not a valid PDF');
        }
        $originalFilename = $this->normalizeFilename((string) ($upload['name'] ?? 'contract.pdf'));
        $checksum = hash_file('sha256', $tmpName);
        if ($checksum === false) {
            throw new \RuntimeException('Could not checksum the uploaded PDF');
        }
        return compact('tmpName', 'originalFilename', 'fileSize', 'checksum');
    }

    private function normalizeDisplayName(string $displayName, string $fallbackFilename): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $displayName) ?? '');
        if ($name === '') {
            $name = pathinfo($fallbackFilename, PATHINFO_FILENAME);
        }
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Contract name must be between 1 and 255 characters');
        }
        return $name;
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = trim(preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $filename))) ?? '');
        if ($filename === '') {
            return 'contract.pdf';
        }
        return mb_substr($filename, 0, 255);
    }

    private function assertOrganizationExists(int $organizationId): void
    {
        if ($this->organizationMapper->find($organizationId) === null) {
            throw new \OutOfBoundsException('Organization not found');
        }
    }

    private function store(int $organizationId, string $storageKey, string $tmpName): void
    {
        $file = $this->getOrganizationFolder($organizationId, true)->newFile($storageKey);
        $stream = fopen($tmpName, 'rb');
        if ($stream === false) {
            $file->delete();
            throw new \RuntimeException('Could not read the uploaded PDF');
        }
        try {
            $file->putContent($stream);
        } catch (\Throwable $e) {
            $file->delete();
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function deleteStoredFile(int $organizationId, string $storageKey): void
    {
        try {
            $folder = $this->getOrganizationFolder($organizationId, false);
            if ($folder->fileExists($storageKey)) {
                $folder->getFile($storageKey)->delete();
            }
        } catch (NotFoundException) {
        }
    }

    private function getOrganizationFolder(int $organizationId, bool $create): ISimpleFolder
    {
        $appData = $this->appDataFactory->get('organization');
        $contracts = $this->getFolder($appData, self::CONTRACTS_FOLDER, $create);
        return $this->getFolder($contracts, 'org-' . $organizationId, $create);
    }

    private function getFolder(IAppData|ISimpleFolder $parent, string $name, bool $create): ISimpleFolder
    {
        try {
            return $parent->getFolder($name);
        } catch (NotFoundException $e) {
            if (!$create) {
                throw $e;
            }
            return $parent->newFolder($name);
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
