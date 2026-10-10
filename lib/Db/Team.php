<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method void setOrganizationId(int $organizationId)
 * @method void setName(string $name)
 * @method void setDescription(?string $description)
 * @method void setCreatedBy(string $createdBy)
 * @method void setCreatedAt(string|\DateTime $createdAt)
 * @method void setUpdatedAt(string|\DateTime $updatedAt)
 */
class Team extends Entity implements \JsonSerializable
{
    public ?int $organizationId = null;
    public ?string $name = null;
    public ?string $description = null;
    public ?string $createdBy = null;
    public ?\DateTime $createdAt = null;
    public ?\DateTime $updatedAt = null;

    public function __construct()
    {
        $this->addType('organizationId', Types::INTEGER);
        $this->addType('name', Types::STRING);
        $this->addType('description', Types::STRING);
        $this->addType('createdBy', Types::STRING);
        $this->addType('createdAt', Types::DATETIME);
        $this->addType('updatedAt', Types::DATETIME);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'organizationId' => $this->organizationId,
            'name' => $this->name,
            'description' => $this->description,
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
