<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\ReadStatus;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Federation\ReadStatus\CommonReadStore;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class CommonReadStoreTest extends TestCase {
	protected ParticipantService&MockObject $participantService;
	protected CommonReadStore $store;

	public function setUp(): void {
		parent::setUp();
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->store = new CommonReadStore($this->participantService);
	}

	private function participantWith(int $lastCommonReadMessage): Participant {
		$attendee = new Attendee();
		$attendee->setActorType(Attendee::ACTOR_FEDERATED_USERS);
		$attendee->setActorId('user@remote.test');
		$attendee->setLastCommonReadMessage($lastCommonReadMessage);

		$room = $this->createMock(Room::class);
		return new Participant($room, $attendee, null);
	}

	private function responseWithHeader(string $header): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getHeader')
			->with('X-Chat-Last-Common-Read')
			->willReturn($header);
		return $response;
	}

	public function testHeaderUpdatesAnAttendeeStoredAtZero(): void {
		$participant = $this->participantWith(0);
		$response = $this->responseWithHeader('42');

		$this->participantService->expects($this->once())
			->method('updateLastCommonReadMessage')
			->with($participant, 42);

		$this->store->remember($participant, $response);

		$this->assertSame(42, $participant->getAttendee()->getLastCommonReadMessage());
	}

	public function testSameValueIsANoOp(): void {
		$participant = $this->participantWith(42);
		$response = $this->responseWithHeader('42');

		$this->participantService->expects($this->never())->method('updateLastCommonReadMessage');

		$this->store->remember($participant, $response);

		$this->assertSame(42, $participant->getAttendee()->getLastCommonReadMessage());
	}

	public function testMissingHeaderIsANoOp(): void {
		$participant = $this->participantWith(42);
		$response = $this->responseWithHeader('');

		$this->participantService->expects($this->never())->method('updateLastCommonReadMessage');

		$this->store->remember($participant, $response);

		$this->assertSame(42, $participant->getAttendee()->getLastCommonReadMessage());
	}

	public function testLowerValueMovesTheMarkerBackwards(): void {
		// Marking a conversation unread lowers a participant's read marker
		// (ChatController::markUnread() -> setReadMarker($previousMessage->getId())), so the
		// host's minimum legitimately drops. Refusing the decrease would keep the "read by
		// everyone" tick on messages that are no longer read by everyone.
		$participant = $this->participantWith(42);
		$response = $this->responseWithHeader('10');

		$this->participantService->expects($this->once())
			->method('updateLastCommonReadMessage')
			->with($participant, 10);

		$this->store->remember($participant, $response);

		$this->assertSame(10, $participant->getAttendee()->getLastCommonReadMessage());
	}

	public function testUnreadFirstMessageSentinelIsStoredNotRejected(): void {
		// setReadMarker(0) resolves to ChatManager::UNREAD_FIRST_MESSAGE (-2) on the host; the
		// frontend's `lastCommonReadMessage >= message.id` is then false for every message,
		// which is correct, so the sentinel must be stored as-is.
		$participant = $this->participantWith(42);
		$response = $this->responseWithHeader((string)ChatManager::UNREAD_FIRST_MESSAGE);

		$this->participantService->expects($this->once())
			->method('updateLastCommonReadMessage')
			->with($participant, ChatManager::UNREAD_FIRST_MESSAGE);

		$this->store->remember($participant, $response);

		$this->assertSame(ChatManager::UNREAD_FIRST_MESSAGE, $participant->getAttendee()->getLastCommonReadMessage());
	}
}
