<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/** @method int getContractId() @method void setContractId(int $value) @method int getVersionNumber() @method void setVersionNumber(int $value) @method string getOriginalFilename() @method void setOriginalFilename(string $value) @method string getStorageKey() @method void setStorageKey(string $value) @method string getMimeType() @method void setMimeType(string $value) @method int getFileSize() @method void setFileSize(int $value) @method string getChecksum() @method void setChecksum(string $value) @method string getUploadedByUid() @method void setUploadedByUid(string $value) @method string getCreatedAt() @method void setCreatedAt(string $value) */
class ContractVersion extends Entity implements \JsonSerializable
{
    protected ?int $contractId = null;
    protected ?int $versionNumber = null;
    protected ?string $originalFilename = null;
    protected ?string $storageKey = null;
    protected ?string $mimeType = null;
    protected ?int $fileSize = null;
    protected ?string $checksum = null;
    protected ?string $uploadedByUid = null;
    protected ?string $createdAt = null;

    public function __construct()
    {
        foreach (['contract_id', 'version_number', 'file_size'] as $field) {
            $this->addType($field, Types::INTEGER);
        }
        foreach (['original_filename', 'storage_key', 'mime_type', 'checksum', 'uploaded_by_uid', 'created_at'] as $field) {
            $this->addType($field, Types::STRING);
        }
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'contractId' => $this->contractId, 'versionNumber' => $this->versionNumber, 'originalFilename' => $this->originalFilename, 'fileSize' => $this->fileSize, 'checksum' => $this->checksum, 'uploadedByUid' => $this->uploadedByUid, 'createdAt' => $this->createdAt];
    }
}
