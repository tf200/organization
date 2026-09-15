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
 * @method void setFte(float $fte)
 * @method void setProjectsPerFte(float $projectsPerFte)
 * @method void setCreatedBy(string $createdBy)
 * @method void setCreatedAt(string $createdAt)
 * @method void setUpdatedAt(string $updatedAt)
 */
class Team extends Entity implements \JsonSerializable
{
    public ?int $organizationId = null;
    public ?string $name = null;
    public ?string $description = null;
    public float $fte = 1.0;
    public float $projectsPerFte = 1.0;
    public ?string $createdBy = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    public function __construct()
    {
        $this->addType('organizationId', Types::INTEGER);
        $this->addType('name', Types::STRING);
        $this->addType('description', Types::STRING);
        $this->addType('fte', Types::FLOAT);
        $this->addType('projectsPerFte', Types::FLOAT);
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
            'fte' => $this->fte,
            'projectsPerFte' => $this->projectsPerFte,
            'projectCapacity' => round($this->fte * $this->projectsPerFte, 2),
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
