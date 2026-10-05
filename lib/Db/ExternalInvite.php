<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A one-time link that lets a new external set a password. Only the SHA-256
 * of the token is stored.
 *
 * @method int getId()
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method int getGrantId()
 * @method void setGrantId(int $grantId)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method ?\DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method ?\DateTime getUsedAt()
 * @method void setUsedAt(?\DateTime $usedAt)
 * @method ?\DateTime getLastSentAt()
 * @method void setLastSentAt(?\DateTime $lastSentAt)
 * @method int getSendCount()
 * @method void setSendCount(int $sendCount)
 */
class ExternalInvite extends Entity
{
    public ?string $userUid = null;
    public ?int $grantId = null;
    public ?string $tokenHash = null;
    public ?\DateTime $expiresAt = null;
    public ?\DateTime $usedAt = null;
    public ?\DateTime $lastSentAt = null;
    public int $sendCount = 0;

    public function __construct()
    {
        $this->addType('userUid', Types::STRING);
        $this->addType('grantId', Types::INTEGER);
        $this->addType('tokenHash', Types::STRING);
        $this->addType('expiresAt', Types::DATETIME);
        $this->addType('usedAt', Types::DATETIME);
        $this->addType('lastSentAt', Types::DATETIME);
        $this->addType('sendCount', Types::INTEGER);
    }

    public function isUsable(\DateTimeInterface $now): bool
    {
        return $this->usedAt === null && $this->expiresAt !== null && $this->expiresAt > $now;
    }
}
