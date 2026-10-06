<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One thing that happened to an external collaborator. A null actor means a
 * background job did it.
 *
 * @method ?int getOrganizationId()
 * @method void setOrganizationId(?int $organizationId)
 * @method ?int getProjectId()
 * @method void setProjectId(?int $projectId)
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method ?string getDetails()
 * @method void setDetails(?string $details)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class ExternalAuditEntry extends Entity implements JsonSerializable
{
    public ?int $organizationId = null;
    public ?int $projectId = null;
    public ?string $userUid = null;
    public ?string $actorUid = null;
    public ?string $action = null;
    public ?string $details = null;
    public ?\DateTime $createdAt = null;

    public function __construct()
    {
        $this->addType('organizationId', Types::INTEGER);
        $this->addType('projectId', Types::INTEGER);
        $this->addType('userUid', Types::STRING);
        $this->addType('actorUid', Types::STRING);
        $this->addType('action', Types::STRING);
        $this->addType('details', Types::STRING);
        $this->addType('createdAt', Types::DATETIME);
    }

    /** @return array<string,mixed> */
    public function getDetailMap(): array
    {
        if ($this->details === null || $this->details === '') {
            return [];
        }
        $decoded = json_decode($this->details, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'organizationId' => $this->organizationId,
            'projectId' => $this->projectId,
            'userId' => $this->userUid,
            'actorId' => $this->actorUid,
            'action' => $this->action,
            'details' => $this->getDetailMap(),
            'createdAt' => $this->createdAt?->format(DATE_ATOM),
        ];
    }
}
