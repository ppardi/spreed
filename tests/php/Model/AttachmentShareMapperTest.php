<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Model;

use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

#[Group('DB')]
class AttachmentShareMapperTest extends TestCase {
	private const ROOM_ID = 424242;

	protected AttachmentShareMapper $mapper;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = new AttachmentShareMapper(Server::get(IDBConnection::class));
	}

	public function tearDown(): void {
		foreach ($this->mapper->findByRoom(self::ROOM_ID) as $row) {
			$this->mapper->delete($row);
		}
		parent::tearDown();
	}

	private function addRow(string $sourceId, string $recipient, string $shareId, string $origin = AttachmentShare::ORIGIN_CREATED): AttachmentShare {
		$row = new AttachmentShare();
		$row->setRoomId(self::ROOM_ID);
		$row->setSourceType(AttachmentShare::SOURCE_ROOM_SHARE);
		$row->setSourceId($sourceId);
		$row->setOwnerServer('');
		$row->setOwnerActorType('users');
		$row->setOwnerActorId('paul');
		$row->setRecipientActorType('federated_users');
		$row->setRecipientActorId($recipient);
		$row->setShareId($shareId);
		$row->setOrigin($origin);
		$row->setCreatedAt(new \DateTime());
		return $this->mapper->insert($row);
	}

	public function testFindAndDelete(): void {
		$this->addRow('5', 'bill@nc2.test', '6');
		$this->addRow('5', 'carol@nc3.test', '7');
		$this->addRow('8', 'bill@nc2.test', '9');

		$row = $this->mapper->findForRecipient(self::ROOM_ID, AttachmentShare::SOURCE_ROOM_SHARE, '5', 'federated_users', 'bill@nc2.test');
		$this->assertNotNull($row);
		$this->assertSame('6', $row->getShareId());
		$this->assertNull($this->mapper->findForRecipient(self::ROOM_ID, AttachmentShare::SOURCE_ROOM_SHARE, '5', 'federated_users', 'dave@nc4.test'));

		$this->assertCount(2, $this->mapper->findBySource(self::ROOM_ID, AttachmentShare::SOURCE_ROOM_SHARE, '5'));
		$this->assertCount(2, $this->mapper->findByRecipient(self::ROOM_ID, 'federated_users', 'bill@nc2.test'));
		$this->assertCount(3, $this->mapper->findByRoom(self::ROOM_ID));

		$this->mapper->deleteByOwnerShareId('', '7');
		$this->assertCount(2, $this->mapper->findByRoom(self::ROOM_ID));
	}

	public function testOriginIsStored(): void {
		$this->addRow('5', 'bill@nc2.test', '6', AttachmentShare::ORIGIN_ADOPTED);
		$this->addRow('8', 'bill@nc2.test', '9');

		$adopted = $this->mapper->findForRecipient(self::ROOM_ID, AttachmentShare::SOURCE_ROOM_SHARE, '5', 'federated_users', 'bill@nc2.test');
		$this->assertSame(AttachmentShare::ORIGIN_ADOPTED, $adopted?->getOrigin());
		$created = $this->mapper->findForRecipient(self::ROOM_ID, AttachmentShare::SOURCE_ROOM_SHARE, '8', 'federated_users', 'bill@nc2.test');
		$this->assertSame(AttachmentShare::ORIGIN_CREATED, $created?->getOrigin());
	}

	public function testCountByOwnerShareId(): void {
		// The same federated share used by two sources (e.g. one file shared into two conversations)
		$this->addRow('5', 'bill@nc2.test', '6');
		$this->addRow('8', 'bill@nc2.test', '6', AttachmentShare::ORIGIN_ADOPTED);
		$this->addRow('10', 'bill@nc2.test', '11');

		$this->assertSame(2, $this->mapper->countByOwnerShareId('', '6'));
		$this->assertSame(1, $this->mapper->countByOwnerShareId('', '6', AttachmentShare::ORIGIN_CREATED));
		$this->assertSame(1, $this->mapper->countByOwnerShareId('', '6', AttachmentShare::ORIGIN_ADOPTED));
		$this->assertSame(1, $this->mapper->countByOwnerShareId('', '11'));
		$this->assertSame(0, $this->mapper->countByOwnerShareId('', '12'));
		$this->assertSame(0, $this->mapper->countByOwnerShareId('https://nc2.test', '6'));
	}
}
