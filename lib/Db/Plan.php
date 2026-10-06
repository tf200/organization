<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method int getMaxProjects()
 * @method void setMaxProjects(int $maxProjects)
 * @method int getMaxMembers()
 * @method void setMaxMembers(int $maxMembers)
 * @method int getSharedStoragePerProject()
 * @method void setSharedStoragePerProject(int $storage)
 * @method int getPrivateStoragePerUser()
 * @method void setPrivateStoragePerUser(int $storage)
 * @method float|null getPrice()
 * @method void setPrice(float $price)
 * @method string|null getCurrency()
 * @method void setCurrency(string $currency)
 * @method bool|null getIsPublic()
 * @method void setIsPublic(?bool $isPublic)
 * @method ?int getExternalStorageQuota()
 * @method void setExternalStorageQuota(?int $externalStorageQuota)
 */
class Plan extends Entity implements \JsonSerializable
{
    /** @var string The public name of the plan. */
    protected string|null $name = null;

    /** @var int The number of allowed projects (Group Folders). */
    protected int|null $maxProjects = null;

    /** @var int The total number of users allowed in the organization. */
    protected int|null $maxMembers = null;

    /** @var int The storage limit for each shared project in bytes. */
    protected int|null $sharedStoragePerProject = null;

    /** @var int The storage limit for each private user in bytes. */
    protected int|null $privateStoragePerUser = null;

    /** @var int|null Storage per external collaborator in bytes; null uses the instance default. */
    protected int|null $externalStorageQuota = null;

    /** @var float|null The price of the plan. */
    protected float|null $price = null;

    /** @var string The currency code for the plan price. */
    protected string $currency = 'EUR';

    /** @var bool|null Whether to show this plan on the public pricing page. */
    protected ?bool $isPublic = false;

    public function __construct()
    {
        $this->addType('name', Types::STRING);
        $this->addType('max_projects', Types::INTEGER);
        $this->addType('max_members', Types::INTEGER);
        $this->addType('shared_storage_per_project', Types::INTEGER);
        $this->addType('private_storage_per_user', Types::INTEGER);
        $this->addType('external_storage_quota', Types::INTEGER);
        $this->addType('price', Types::FLOAT, true);
        $this->addType('currency', Types::STRING, true);
        $this->addType('is_public', Types::BOOLEAN, true);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'maxProjects' => $this->maxProjects,
            'maxMembers' => $this->maxMembers,
            'sharedStoragePerProject' => $this->sharedStoragePerProject,
            'privateStoragePerUser' => $this->privateStoragePerUser,
            'externalStorageQuota' => $this->externalStorageQuota,
            'price' => $this->price,
            'isPublic' => $this->isPublic,
            'currency' => $this->currency,
        ];
    }
}
