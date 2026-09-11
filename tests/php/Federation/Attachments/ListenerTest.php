<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\BackgroundJob\EnsureAttachmentShares;
use OCA\Talk\Config;
use OCA\Talk\Events\AttendeeRemovedEvent;
use OCA\Talk\Events\BeforeRoomDeletedEvent;
use OCA\Talk\Events\SystemMessageSentEvent;
use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Federation\Attachments\Listener;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Room;
use OCA\Talk\Share\Helper\RoomShareLocator;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Comments\IComment;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ListenerTest extends TestCase {
	protected AttachmentSharer&MockObject $sharer;
	protected Manager&MockObject $manager;
	protected RoomShareLocator&MockObject $locator;
	protected IRootFolder&MockObject $rootFolder;
	protected IJobList&MockObject $jobList;
	protected Config&MockObject $config;
	protected Room&MockObject $room;
	protected Listener $listener;

	public function setUp(): void {
		parent::setUp();
		$this->sharer = $this->createMock(AttachmentSharer::class);
		$this->room = $this->createMock(Room::class);
		$this->room->method('getId')->willReturn(12);
		$this->room->method('isFederatedConversation')->willReturn(false);
		$this->manager = $this->createMock(Manager::class);
		$this->manager->method('getRoomByToken')->with('wqhg8fxn')->willReturn($this->room);
		$this->locator = $this->createMock(RoomShareLocator::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->jobList = $this->createMock(IJobList::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);
		$this->config = $this->createMock(Config::class);

		$this->listener = new Listener(
			$this->sharer,
			$this->manager,
			$this->createMock(RoomShareProvider::class),
			$this->locator,
			$this->rootFolder,
			$this->jobList,
			$time,
			$this->config,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function roomShare(): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
		$share->method('getSharedWith')->willReturn('wqhg8fxn');
		$share->method('getId')->willReturn('5');
		return $share;
	}

	public function testRoomShareCreatedSchedulesRetryOnFailure(): void {
		$this->config->method('isFederationEnabled')->willReturn(true);
		$share = $this->roomShare();
		$this->sharer->expects($this->once())->method('shareRoomShare')->with($this->room, $share)->willReturn(false);
		$this->jobList->expects($this->once())
			->method('scheduleAfter')
			->with(EnsureAttachmentShares::class, 1000000 + 300, ['roomId' => 12, 'roomShareId' => '5', 'attempt' => 2]);

		$this->listener->handle(new ShareCreatedEvent($share));
	}

	public function testExceptionsNeverReachTheUsersAction(): void {
		$this->config->method('isFederationEnabled')->willReturn(true);
		$this->sharer->method('shareRoomShare')->willThrowException(new \RuntimeException('ocFederatedSharing provider missing'));
		$this->jobList->expects($this->once())->method('scheduleAfter');

		// Must not throw: this runs inside the user's share creation / upload
		$this->listener->handle(new ShareCreatedEvent($this->roomShare()));
	}

	public function testNoSharingWhileFederationIsDisabled(): void {
		$this->config->method('isFederationEnabled')->willReturn(false);
		$this->sharer->expects($this->never())->method('shareRoomShare');
		$this->listener->handle(new ShareCreatedEvent($this->roomShare()));
	}

	public function testCleanupRunsEvenWhenFederationIsDisabled(): void {
		$this->config->method('isFederationEnabled')->willReturn(false);
		$this->sharer->expects($this->once())->method('unshareRoom')->with($this->room);
		$this->listener->handle(new BeforeRoomDeletedEvent($this->room));
	}

	public function testFileMessageFromConversationFolderIsShared(): void {
		$this->config->method('isFederationEnabled')->willReturn(true);
		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn(json_encode(['message' => 'file_shared', 'parameters' => ['fileId' => '187']]));
		$file = $this->createMock(File::class);
		$this->rootFolder->method('getFirstNodeById')->with(187)->willReturn($file);
		$roomShare = $this->createMock(IShare::class);
		$this->locator->method('findForNode')->with($this->room, $file)->willReturn([$roomShare, 'photo1.jpg']);
		$this->sharer->expects($this->once())->method('shareRoomShare')->with($this->room, $roomShare)->willReturn(true);
		$this->jobList->expects($this->never())->method('scheduleAfter');

		$this->listener->handle(new SystemMessageSentEvent($this->room, $comment));
	}

	public function testHistoryClearedRemovesAllShares(): void {
		$this->config->method('isFederationEnabled')->willReturn(true);
		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn(json_encode(['message' => 'history_cleared', 'parameters' => []]));
		$this->sharer->expects($this->once())->method('unshareRoom')->with($this->room);

		$this->listener->handle(new SystemMessageSentEvent($this->room, $comment));
	}

	public function testFederatedParticipantRemoved(): void {
		$this->config->method('isFederationEnabled')->willReturn(true);
		$attendee = Attendee::fromRow(['actor_type' => Attendee::ACTOR_FEDERATED_USERS, 'actor_id' => 'bill@nc2.test']);
		$this->sharer->expects($this->once())->method('unshareForRecipient')->with($this->room, 'bill@nc2.test');

		$this->listener->handle(new AttendeeRemovedEvent($this->room, $attendee, AttendeeRemovedEvent::REASON_REMOVED, []));
	}

	public function testRemovedFederatedShareIsForgotten(): void {
		$this->config->method('isFederationEnabled')->willReturn(true);
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_REMOTE);
		$share->method('getId')->willReturn('6');
		$this->sharer->expects($this->once())->method('forgetRemoteShare')->with('6');

		$this->listener->handle(new ShareDeletedEvent($share));
	}
}
