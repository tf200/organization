<?php

declare(strict_types=1);

namespace OCA\Organization\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\GetQuotaEvent;

use OCA\Organization\Service\UserQuotaService;

use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<GetQuotaEvent>
 */
class UserQuotaListener implements IEventListener
{
    public function __construct(
        private UserQuotaService $userQuotaService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof GetQuotaEvent) {
            return;
        }

        $userId = $event->getUser()->getUID();

        try {
            $quota = $this->userQuotaService->getEffectiveQuota($userId);
            if ($quota !== null) {
                $event->setQuota((string) $quota);
            }
        } catch (\Throwable $e) {
            // A transient organization failure must not turn into a zero-byte quota.
            $this->logger->warning('Failed to resolve organization user quota', [
                'userId' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
