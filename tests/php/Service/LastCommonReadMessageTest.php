<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

#[Group('DB')]
class LastCommonReadMessageTest extends TestCase {
	protected IDBConnection $db;
	protected int $roomId;

	public function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		// A room id of our own, so the fixtures cannot collide with anything else in the test database
		$this->roomId = 800000000 + random_int(1, 9999999);
	}

	public function tearDown(): void {
		$delete = $this->db->getQueryBuilder();
		$delete->delete('talk_attendees')
			->where($delete->expr()->eq('room_id', $delete->createNamedParameter($this->roomId, IQueryBuilder::PARAM_INT)));
		$delete->executeStatement();
		parent::tearDown();
	}

	protected function addAttendee(string $actorType, string $actorId, int $readPrivacy, int $lastReadMessage): void {
		$insert = $this->db->getQueryBuilder();
		$insert->insert('talk_attendees')
			->values([
				'room_id' => $insert->createNamedParameter($this->roomId, IQueryBuilder::PARAM_INT),
				'actor_type' => $insert->createNamedParameter($actorType),
				'actor_id' => $insert->createNamedParameter($actorId),
				'read_privacy' => $insert->createNamedParameter($readPrivacy, IQueryBuilder::PARAM_INT),
				'last_read_message' => $insert->createNamedParameter($lastReadMessage, IQueryBuilder::PARAM_INT),
			]);
		$insert->executeStatement();
	}

	protected function room(): Room {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn($this->roomId);
		return $room;
	}

	public function testFederatedAttendeeSharingItsStatusLowersTheMarker(): void {
		$this->addAttendee(Attendee::ACTOR_USERS, 'local', Participant::PRIVACY_PUBLIC, 20);
		$this->addAttendee(Attendee::ACTOR_FEDERATED_USERS, 'remote@example.tld', Participant::PRIVACY_PUBLIC, 10);

		$this->assertSame(10, Server::get(ParticipantService::class)->getLastCommonReadChatMessage($this->room()));
	}

	public function testFederatedAttendeeKeepingItsStatusPrivateIsIgnored(): void {
		$this->addAttendee(Attendee::ACTOR_USERS, 'local', Participant::PRIVACY_PUBLIC, 20);
		// The default for federated attendees (ParticipantService::addUsers): must not hold the marker back
		$this->addAttendee(Attendee::ACTOR_FEDERATED_USERS, 'remote@example.tld', Participant::PRIVACY_PRIVATE, 5);

		$this->assertSame(20, Server::get(ParticipantService::class)->getLastCommonReadChatMessage($this->room()));
	}

	public function testMultipleRoomsVariantAgreesWithTheSingleRoomOne(): void {
		$this->addAttendee(Attendee::ACTOR_USERS, 'local', Participant::PRIVACY_PUBLIC, 20);
		$this->addAttendee(Attendee::ACTOR_FEDERATED_USERS, 'remote@example.tld', Participant::PRIVACY_PUBLIC, 10);

		$service = Server::get(ParticipantService::class);
		$this->assertSame(
			[$this->roomId => 10],
			$service->getLastCommonReadChatMessageForMultipleRooms([$this->roomId]),
		);
	}
}
