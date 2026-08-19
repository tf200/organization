<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getOrganizationId()
 * @method void setOrganizationId(int $organizationId)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string getOriginalFilename()
 * @method void setOriginalFilename(string $originalFilename)
 * @method string getStorageKey()
 * @method void setStorageKey(string $storageKey)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method string getChecksum()
 * @method void setChecksum(string $checksum)
 * @method string getUploadedByUid()
 * @method void setUploadedByUid(string $uploadedByUid)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Contract extends Entity implements \JsonSerializable
{
    protected ?int $organizationId = null;
    protected ?string $displayName = null;
    protected ?string $originalFilename = null;
    protected ?string $storageKey = null;
    protected ?string $mimeType = null;
    protected ?int $fileSize = null;
    protected ?string $checksum = null;
    protected ?string $uploadedByUid = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    public function __construct()
    {
        $this->addType('organization_id', Types::INTEGER);
        $this->addType('display_name', Types::STRING);
        $this->addType('original_filename', Types::STRING);
        $this->addType('storage_key', Types::STRING);
        $this->addType('mime_type', Types::STRING);
        $this->addType('file_size', Types::INTEGER);
        $this->addType('checksum', Types::STRING);
        $this->addType('uploaded_by_uid', Types::STRING);
        $this->addType('created_at', Types::STRING);
        $this->addType('updated_at', Types::STRING);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organizationId,
            'displayName' => $this->displayName,
            'originalFilename' => $this->originalFilename,
            'mimeType' => $this->mimeType,
            'fileSize' => $this->fileSize,
            'checksum' => $this->checksum,
            'uploadedByUid' => $this->uploadedByUid,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
