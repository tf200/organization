<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/** @method int getOrganizationId() @method void setOrganizationId(int $value) @method int getContractId() @method void setContractId(int $value) @method int getContractVersionId() @method void setContractVersionId(int $value) @method string getLibresignFileUuid() @method void setLibresignFileUuid(string $value) @method string getStatus() @method void setStatus(string $value) @method string getSignersJson() @method void setSignersJson(string $value) @method string getSourceChecksum() @method void setSourceChecksum(string $value) @method string getCreatedByUid() @method void setCreatedByUid(string $value) @method string getCreatedAt() @method void setCreatedAt(string $value) @method string|null getSentAt() @method void setSentAt(?string $value) @method string|null getCompletedAt() @method void setCompletedAt(?string $value) @method string|null getSignedStorageKey() @method void setSignedStorageKey(?string $value) @method string|null getSignedChecksum() @method void setSignedChecksum(?string $value) @method string getUpdatedAt() @method void setUpdatedAt(string $value) */
class ContractSignRequest extends Entity implements \JsonSerializable
{
    protected ?int $organizationId = null;
    protected ?int $contractId = null;
    protected ?int $contractVersionId = null;
    protected ?string $libresignFileUuid = null;
    protected ?string $status = null;
    protected ?string $signersJson = null;
    protected ?string $sourceChecksum = null;
    protected ?string $createdByUid = null;
    protected ?string $createdAt = null;
    protected ?string $sentAt = null;
    protected ?string $completedAt = null;
    protected ?string $signedStorageKey = null;
    protected ?string $signedChecksum = null;
    protected ?string $updatedAt = null;

    public function __construct()
    {
        foreach (['organization_id', 'contract_id', 'contract_version_id'] as $field) {
            $this->addType($field, Types::INTEGER);
        }
        foreach (['libresign_file_uuid', 'status', 'signers_json', 'source_checksum', 'created_by_uid', 'created_at', 'sent_at', 'completed_at', 'signed_storage_key', 'signed_checksum', 'updated_at'] as $field) {
            $this->addType($field, Types::STRING, in_array($field, ['sent_at', 'completed_at', 'signed_storage_key', 'signed_checksum'], true));
        }
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'organizationId' => $this->organizationId, 'contractId' => $this->contractId, 'contractVersionId' => $this->contractVersionId, 'libresignFileUuid' => $this->libresignFileUuid, 'status' => $this->status, 'signers' => json_decode($this->signersJson ?? '[]', true) ?: [], 'sourceChecksum' => $this->sourceChecksum, 'createdByUid' => $this->createdByUid, 'createdAt' => $this->createdAt, 'sentAt' => $this->sentAt, 'completedAt' => $this->completedAt, 'hasSignedCopy' => $this->signedStorageKey !== null, 'signedChecksum' => $this->signedChecksum, 'updatedAt' => $this->updatedAt];
    }
}
