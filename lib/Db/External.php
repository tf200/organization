<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A person who works on single projects without being an organization member.
 * One row per Nextcloud account, shared by every organization that invited them.
 *
 * @method int getId()
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method ?string getCompany()
 * @method void setCompany(?string $company)
 * @method ?string getPhone()
 * @method void setPhone(?string $phone)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method ?\DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method ?\DateTime getActivatedAt()
 * @method void setActivatedAt(?\DateTime $activatedAt)
 * @method ?\DateTime getLastSeenAt()
 * @method void setLastSeenAt(?\DateTime $lastSeenAt)
 * @method ?\DateTime getDisabledAt()
 * @method void setDisabledAt(?\DateTime $disabledAt)
 */
class External extends Entity implements \JsonSerializable
{
    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DISABLED = 'disabled';

    public ?string $userUid = null;
    public ?string $email = null;
    public ?string $displayName = null;
    public ?string $company = null;
    public ?string $phone = null;
    public ?string $status = null;
    public ?string $createdBy = null;
    public ?\DateTime $createdAt = null;
    public ?\DateTime $activatedAt = null;
    public ?\DateTime $lastSeenAt = null;
    public ?\DateTime $disabledAt = null;

    public function __construct()
    {
        $this->addType('userUid', Types::STRING);
        $this->addType('email', Types::STRING);
        $this->addType('displayName', Types::STRING);
        $this->addType('company', Types::STRING);
        $this->addType('phone', Types::STRING);
        $this->addType('status', Types::STRING);
        $this->addType('createdBy', Types::STRING);
        $this->addType('createdAt', Types::DATETIME);
        $this->addType('activatedAt', Types::DATETIME);
        $this->addType('lastSeenAt', Types::DATETIME);
        $this->addType('disabledAt', Types::DATETIME);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'userId' => $this->userUid,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'company' => $this->company,
            'phone' => $this->phone,
            'status' => $this->status,
            'createdAt' => $this->createdAt?->format(DATE_ATOM),
            'activatedAt' => $this->activatedAt?->format(DATE_ATOM),
        ];
    }
}
