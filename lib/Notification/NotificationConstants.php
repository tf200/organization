<?php

declare(strict_types=1);

namespace OCA\Organization\Notification;

final class NotificationConstants
{
    public const APP_ID = 'organization';

    public const OBJECT_TYPE_ORGANIZATION = 'organization';
    public const OBJECT_TYPE_STORAGE = 'storage';
    public const OBJECT_TYPE_EXTERNAL_GRANT = 'external_grant';

    public const SUBJECT_SUBSCRIPTION_STATUS_CHANGED = 'subscription_status_changed';
    public const SUBJECT_SUBSCRIPTION_EXTENDED = 'subscription_extended';
    public const SUBJECT_SUBSCRIPTION_EXPIRED = 'subscription_expired';
    public const SUBJECT_ORGANIZATION_MEMBER_ADDED = 'organization_member_added';
    public const SUBJECT_ORGANIZATION_MEMBER_REMOVED = 'organization_member_removed';
    public const SUBJECT_ORGANIZATION_HANDOVER_STARTED = 'organization_handover_started';
    public const SUBJECT_ORGANIZATION_HANDOVER_COMPLETED = 'organization_handover_completed';
    public const SUBJECT_ORGANIZATION_HANDOVER_FAILED = 'organization_handover_failed';
    public const SUBJECT_STORAGE_THRESHOLD = 'storage_threshold';
    public const SUBJECT_EXTERNAL_ACCESS_EXPIRING = 'external_access_expiring';
    public const SUBJECT_EXTERNAL_ACCESS_ENDED = 'external_access_ended';

    private function __construct()
    {
    }
}
