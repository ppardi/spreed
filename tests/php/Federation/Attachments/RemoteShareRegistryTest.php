<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Federation\Attachments\RemoteFile;
use OCA\Talk\Federation\Attachments\RemoteShareRegistry;
use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RemoteShareRegistryTest extends TestCase {
	protected AttachmentShareMapper&MockObject $mapper;
	protected ParticipantService&MockObject $participantService;
	protected Room&MockObject $room;
	protected RemoteShareRegistry $registry;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(AttachmentShareMapper::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		// Participants: paul (user of this server) and carol (federated)
		$this->participantService->method('getParticipantByActor')
			->willReturnCallback(function (Room $room, string $actorType, string $actorId): Participant {
				if ([$actorType, $actorId] === [Attendee::ACTOR_USERS, 'paul']
					|| [$actorType, $actorId] === [Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test']) {
					return $this->createMock(Participant::class);
				}
				throw new ParticipantNotFoundException();
			});
		$cloudIdManager = $this->createMock(ICloudIdManager::class);
		$cloudIdManager->method('resolveCloudId')->willReturnCallback(function (string $id): ICloudId {
			if (!str_contains($id, '@')) {
				throw new \InvalidArgumentException('Invalid cloud id');
			}
			[$user, $remote] = explode('@', $id, 2);
			$cloudId = $this->createMock(ICloudId::class);
			$cloudId->method('getId')->willReturn($id);
			$cloudId->method('getUser')->willReturn($user);
			$cloudId->method('getRemote')->willReturn('https://' . $remote);
			return $cloudId;
		});
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->with('/')->willReturn('https://nc1.test/');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-12 10:00:00'));

		$this->room = $this->createMock(Room::class);
		$this->room->method('getId')->willReturn(12);

		$this->registry = new RemoteShareRegistry($this->mapper, $this->participantService, $cloudIdManager, $url, $timeFactory);
	}

	private function row(string $actorType, string $actorId, string $shareId): AttachmentShare {
		$row = new AttachmentShare();
		$row->setRecipientActorType($actorType);
		$row->setRecipientActorId($actorId);
		$row->setShareId($shareId);
		return $row;
	}

	public function testRecordsTheSharesOfParticipants(): void {
		$this->mapper->method('findForRecipient')->willReturn(null);
		$inserted = [];
		$this->mapper->method('insert')->willReturnCallback(function (AttachmentShare $row) use (&$inserted): AttachmentShare {
			$inserted[] = [$row->getRoomId(), $row->getSourceType(), $row->getSourceId(), $row->getOwnerServer(), $row->getOwnerActorType(),
				$row->getOwnerActorId(), $row->getRecipientActorType(), $row->getRecipientActorId(), $row->getShareId(), $row->getOrigin()];
			return $row;
		});

		$this->registry->record($this->room, 'bill@nc2.test', '42', [
			['recipient' => 'paul@nc1.test', 'shareId' => '21'],
			['recipient' => 'carol@nc3.test', 'shareId' => '22'],
			['recipient' => 'mallory@nc9.test', 'shareId' => '23'], // not in the conversation
			['recipient' => 'bill@nc2.test', 'shareId' => '24'], // the sender
			['recipient' => 'paul@nc1.test'], // no share id
			['recipient' => 'no-cloud-id', 'shareId' => '25'],
			'garbage',
		]);

		$sourceId = RemoteFile::sourceId('bill@nc2.test', '42');
		$this->assertSame([
			[12, AttachmentShare::SOURCE_REMOTE_FOLDER, $sourceId, 'https://nc2.test', Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test', Attendee::ACTOR_USERS, 'paul', '21', AttachmentShare::ORIGIN_ADOPTED],
			[12, AttachmentShare::SOURCE_REMOTE_FOLDER, $sourceId, 'https://nc2.test', Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test', Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test', '22', AttachmentShare::ORIGIN_ADOPTED],
		], $inserted);
	}

	public function testChangedShareIdIsUpdated(): void {
		$row = $this->row(Attendee::ACTOR_USERS, 'paul', '11');
		$this->mapper->method('findForRecipient')
			->with(12, AttachmentShare::SOURCE_REMOTE_FOLDER, RemoteFile::sourceId('bill@nc2.test', '42'), Attendee::ACTOR_USERS, 'paul')
			->willReturn($row);
		$this->mapper->expects($this->once())->method('update')->with($row);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('delete');

		$this->registry->record($this->room, 'bill@nc2.test', '42', [['recipient' => 'paul@nc1.test', 'shareId' => '21']]);
		$this->assertSame('21', $row->getShareId());
	}

	public function testRecipientsLeftOutKeepTheirRow(): void {
		// e.g. two posts racing to create Carol's first share: a one-off gap must not hide all earlier files (ruling R11)
		$this->mapper->method('findForRecipient')->willReturn($this->row(Attendee::ACTOR_USERS, 'paul', '21'));
		$this->mapper->expects($this->never())->method('findBySource');
		$this->mapper->expects($this->never())->method('delete');

		$this->registry->record($this->room, 'bill@nc2.test', '42', [['recipient' => 'paul@nc1.test', 'shareId' => '21']]);
	}
}
