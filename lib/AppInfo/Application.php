<?php

declare(strict_types=1);

namespace OCA\Organization\AppInfo;

use OCA\Organization\Listener\TalkAutoCompleteListener;
use OCA\Organization\Listener\TalkRoomGuardListener;
use OCA\Organization\Listener\UserQuotaListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

use OCP\Collaboration\AutoComplete\AutoCompleteFilterEvent;
use OCP\User\GetQuotaEvent;

use OCA\Organization\Middleware\SubscriptionMiddleware;
use OCA\Organization\Notification\OrganizationNotifier;

use OCA\Talk\Events\BeforeAttendeesAddedEvent;
use OCA\Talk\Events\BeforeUserJoinedRoomEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'organization';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // Register the subscription middleware
        $context->registerMiddleware(SubscriptionMiddleware::class);

        // Register the app's notification provider (bell notifications)
        $context->registerNotifierService(OrganizationNotifier::class);

        $context->registerEventListener(AutoCompleteFilterEvent::class, TalkAutoCompleteListener::class);
        $context->registerEventListener(BeforeAttendeesAddedEvent::class, TalkRoomGuardListener::class);
        $context->registerEventListener(BeforeUserJoinedRoomEvent::class, TalkRoomGuardListener::class);
        $context->registerEventListener(GetQuotaEvent::class, UserQuotaListener::class);
    }

    public function boot(IBootContext $context): void
    {
        // Boot logic if needed
    }
}
