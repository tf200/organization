<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Listener;

use OCA\Organization\Listener\TalkRoomGuardListener;
use OCA\Organization\Service\TalkOrganizationPolicyService;
use OCA\Talk\Events\BeforeAttendeesAddedEvent;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;

use OCP\IUser;
use OCP\IUserSession;

use PHPUnit\Framework\TestCase;

class TalkRoomGuardListenerTest extends TestCase
{
    private TalkRoomGuardListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $policy = $this->createMock(TalkOrganizationPolicyService::class);
        $policy->method('canUserUseTalk')->willReturn(true);
        $policy->method('canUserAccessRoom')->willReturnCallback(
            static fn (string $userId, array $roomUserIds): bool => !in_array('outsider', $roomUserIds, true),
        );

        $participants = $this->createMock(ParticipantService::class);
        $participants->method('getParticipantsForRoom')->willReturn([]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('ext');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $this->listener = new TalkRoomGuardListener($policy, $participants, $session);
    }

    private function addCreatorTo(int $type, string $name): void
    {
        $room = $this->createMock(Room::class);
        $room->method('getType')->willReturn($type);
        $room->method('getName')->willReturn($name);

        $creator = new Attendee();
        $creator->setActorType(Attendee::ACTOR_USERS);
        $creator->setActorId('ext');

        $this->listener->handle(new BeforeAttendeesAddedEvent($room, [$creator]));
    }

    public function testOneToOneWithSomeoneOutsideIsRefusedAtCreation(): void
    {
        $this->expectException(RoomNotFoundException::class);
        $this->addCreatorTo(Room::TYPE_ONE_TO_ONE, json_encode(['ext', 'outsider']));
    }

    public function testOneToOneWithCoMemberIsAllowed(): void
    {
        $this->addCreatorTo(Room::TYPE_ONE_TO_ONE, json_encode(['alice', 'ext']));
        $this->addToAssertionCount(1);
    }

    public function testGroupRoomNameIsNotReadAsUsers(): void
    {
        $this->addCreatorTo(Room::TYPE_GROUP, '["outsider"]');
        $this->addToAssertionCount(1);
    }
}
