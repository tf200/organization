<?php

declare(strict_types=1);

namespace OCA\Organization\Listener;

use OCA\Organization\Service\TalkOrganizationPolicyService;
use OCA\Talk\Events\BeforeAttendeesAddedEvent;
use OCA\Talk\Events\BeforeUserJoinedRoomEvent;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Service\ParticipantService;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * @template-implements IEventListener<Event>
 */
class TalkRoomGuardListener implements IEventListener
{
    public function __construct(
        private TalkOrganizationPolicyService $policyService,
        private ParticipantService $participantService,
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void
    {
        $currentUserId = $this->userSession->getUser()?->getUID();
        if ($this->policyService->isGlobalAdmin($currentUserId)) {
            return;
        }

        if ($event instanceof BeforeUserJoinedRoomEvent) {
            $this->guardUserJoin($event);
            return;
        }

        if ($event instanceof BeforeAttendeesAddedEvent) {
            $this->guardAttendeesAdded($event, $currentUserId);
        }
    }

    private function guardUserJoin(BeforeUserJoinedRoomEvent $event): void
    {
        $userId = $event->getUser()->getUID();
        $roomUserIds = $this->getLocalRoomUserIds($event->getRoom());
        if (!$this->policyService->canUserAccessRoom($userId, $roomUserIds)) {
            throw new RoomNotFoundException('Conversation not found');
        }
    }

    private function guardAttendeesAdded(BeforeAttendeesAddedEvent $event, ?string $currentUserId): void
    {
        if ($currentUserId !== null && !$this->policyService->canUserUseTalk($currentUserId)) {
            throw new RoomNotFoundException('Conversation not found');
        }

        $roomUserIds = $this->getLocalRoomUserIds($event->getRoom());
        foreach ($event->getAttendees() as $attendee) {
            if ($attendee->getActorType() === Attendee::ACTOR_EMAILS) {
                continue;
            }

            if ($attendee->getActorType() !== Attendee::ACTOR_USERS) {
                throw new RoomNotFoundException('Conversation not found');
            }

            $roomUserIds[] = $attendee->getActorId();
        }

        $accessUserId = $currentUserId;
        if ($accessUserId === null) {
            foreach ($roomUserIds as $roomUserId) {
                $accessUserId = $roomUserId;
                break;
            }
        }

        if ($accessUserId !== null && !$this->policyService->canUserAccessRoom($accessUserId, $roomUserIds)) {
            throw new RoomNotFoundException('Conversation not found');
        }
    }

    /**
     * @return string[]
     */
    private function getLocalRoomUserIds(\OCA\Talk\Room $room): array
    {
        $userIds = [];
        foreach ($this->participantService->getParticipantsForRoom($room) as $participant) {
            $attendee = $participant->getAttendee();
            if ($attendee->getActorType() === Attendee::ACTOR_USERS) {
                $userIds[] = $attendee->getActorId();
            }
        }

        // Talk creates a one-to-one room with only its creator and adds the
        // other user later; both are named in the room name from the start.
        if ($room->getType() === \OCA\Talk\Room::TYPE_ONE_TO_ONE) {
            $namedUserIds = json_decode($room->getName(), true);
            if (is_array($namedUserIds)) {
                $userIds = array_merge($userIds, array_filter($namedUserIds, 'is_string'));
            }
        }

        return array_values(array_unique($userIds));
    }
}
