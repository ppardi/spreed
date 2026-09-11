<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Federation\Attachments\FeatureSupport;
use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AttachmentSharerTest extends TestCase {
	protected IShareManager&MockObject $shareManager;
	protected RoomShareProvider&MockObject $roomShareProvider;
	protected AttachmentShareMapper&MockObject $mapper;
	protected ParticipantService&MockObject $participantService;
	protected FeatureSupport&MockObject $featureSupport;
	protected ICloudIdManager&MockObject $cloudIdManager;
	protected IRootFolder&MockObject $rootFolder;
	protected Room&MockObject $room;
	protected IShare&MockObject $roomShare;
	protected Folder&MockObject $sharedFolder;
	protected AttachmentSharer $sharer;

	public function setUp(): void {
		parent::setUp();
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->roomShareProvider = $this->createMock(RoomShareProvider::class);
		$this->mapper = $this->createMock(AttachmentShareMapper::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->featureSupport = $this->createMock(FeatureSupport::class);
		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
		$this->cloudIdManager->method('resolveCloudId')->willReturnCallback(function (string $id): ICloudId {
			$cloudId = $this->createMock(ICloudId::class);
			$cloudId->method('getRemote')->willReturn(substr($id, strpos($id, '@') + 1));
			return $cloudId;
		});
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-11 10:00:00'));

		$this->room = $this->createMock(Room::class);
		$this->room->method('getId')->willReturn(12);
		$this->room->method('getToken')->willReturn('wqhg8fxn');

		$this->roomShare = $this->createMock(IShare::class);
		$this->roomShare->method('getId')->willReturn('5');
		$this->roomShare->method('getSharedBy')->willReturn('paul');
		$this->roomShare->method('getNodeId')->willReturn(193);

		$this->sharedFolder = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->with(193)->willReturn($this->sharedFolder);
		$this->rootFolder->method('getUserFolder')->with('paul')->willReturn($userFolder);

		$this->sharer = new AttachmentSharer(
			$this->shareManager,
			$this->roomShareProvider,
			$this->mapper,
			$this->participantService,
			$this->featureSupport,
			$this->cloudIdManager,
			$this->rootFolder,
			$timeFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function federatedParticipant(string $cloudId, int $state): Participant {
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn(Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_FEDERATED_USERS,
			'actor_id' => $cloudId,
			'state' => $state,
		]));
		return $participant;
	}

	private function expectNewRemoteShare(string $cloudId, string $newShareId): void {
		$newShare = $this->createMock(IShare::class);
		$newShare->expects($this->once())->method('setNode')->with($this->sharedFolder)->willReturnSelf();
		$newShare->expects($this->once())->method('setShareType')->with(IShare::TYPE_REMOTE)->willReturnSelf();
		$newShare->expects($this->once())->method('setSharedBy')->with('paul')->willReturnSelf();
		$newShare->expects($this->once())->method('setSharedWith')->with($cloudId)->willReturnSelf();
		$newShare->expects($this->once())->method('setPermissions')->with(Constants::PERMISSION_READ)->willReturnSelf();
		$this->shareManager->method('newShare')->willReturn($newShare);
		$this->shareManager->method('getSharesBy')->with('paul', IShare::TYPE_REMOTE, $this->sharedFolder, false, -1)->willReturn([]);

		$created = $this->createMock(IShare::class);
		$created->method('getId')->willReturn($newShareId);
		$this->shareManager->expects($this->once())->method('createShare')->with($newShare)->willReturn($created);
	}

	public function testSharesWithAcceptedParticipantsOfSupportingServers(): void {
		$this->participantService->method('getParticipantsByActorType')
			->with($this->room, Attendee::ACTOR_FEDERATED_USERS)
			->willReturn([
				$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED),
				$this->federatedParticipant('carol@nc3.test', Invitation::STATE_PENDING),
				$this->federatedParticipant('dave@old.test', Invitation::STATE_ACCEPTED),
			]);
		$this->featureSupport->method('remoteSupports')
			->willReturnCallback(fn (string $remote): bool => $remote === 'nc2.test');
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->expectNewRemoteShare('bill@nc2.test', '6');

		$this->mapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (AttachmentShare $row): bool {
				return $row->getRoomId() === 12
					&& $row->getSourceType() === AttachmentShare::SOURCE_ROOM_SHARE
					&& $row->getSourceId() === '5'
					&& $row->getOwnerServer() === ''
					&& $row->getOwnerActorType() === Attendee::ACTOR_USERS
					&& $row->getOwnerActorId() === 'paul'
					&& $row->getRecipientActorType() === Attendee::ACTOR_FEDERATED_USERS
					&& $row->getRecipientActorId() === 'bill@nc2.test'
					&& $row->getShareId() === '6'
					&& $row->getOrigin() === AttachmentShare::ORIGIN_CREATED;
			}));

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testNoSharesForPublicConversations(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(12);
		$room->method('getToken')->willReturn('wqhg8fxn');
		$room->method('getType')->willReturn(Room::TYPE_PUBLIC);
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->roomShareProvider->method('getShareIdsInRoom')->willReturn(['5']);
		$this->roomShareProvider->method('getSharesByIds')->willReturn([$this->roomShare]);
		$this->shareManager->expects($this->never())->method('createShare');
		$this->mapper->expects($this->never())->method('insert');

		$this->assertTrue($this->sharer->shareRoomShare($room, $this->roomShare));
		$this->assertTrue($this->sharer->shareAllRoomShares($room));
	}

	private function existingRemoteShare(string $cloudId, string $shareId): IShare&MockObject {
		$existing = $this->createMock(IShare::class);
		$existing->method('getSharedWith')->willReturn($cloudId);
		$existing->method('getId')->willReturn($shareId);
		return $existing;
	}

	public function testUsersOwnFederatedShareIsAdopted(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->shareManager->method('getSharesBy')
			->with('paul', IShare::TYPE_REMOTE, $this->sharedFolder, false, -1)
			->willReturn([$this->existingRemoteShare('carol@nc3.test', '2'), $this->existingRemoteShare('bill@nc2.test', '3')]);
		// Paul shared the folder with Bill himself: no Talk row uses it
		$this->mapper->method('countByOwnerShareId')->with('', '3', AttachmentShare::ORIGIN_CREATED)->willReturn(0);
		$this->shareManager->expects($this->never())->method('createShare');
		$this->mapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (AttachmentShare $row): bool => $row->getShareId() === '3'
				&& $row->getOrigin() === AttachmentShare::ORIGIN_ADOPTED));

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testShareTalkCreatedForAnotherSourceStaysCreated(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->shareManager->method('getSharesBy')->willReturn([$this->existingRemoteShare('bill@nc2.test', '3')]);
		// Talk created share 3 for the same file in another conversation
		$this->mapper->method('countByOwnerShareId')->with('', '3', AttachmentShare::ORIGIN_CREATED)->willReturn(1);
		$this->shareManager->expects($this->never())->method('createShare');
		$this->mapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (AttachmentShare $row): bool => $row->getShareId() === '3'
				&& $row->getOrigin() === AttachmentShare::ORIGIN_CREATED));

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testExistingShareIsKept(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$row = new AttachmentShare();
		$row->setShareId('6');
		$this->mapper->method('findForRecipient')->willReturn($row);
		$this->shareManager->expects($this->once())
			->method('getShareById')
			->with('ocFederatedSharing:6')
			->willReturn($this->createMock(IShare::class));
		$this->shareManager->expects($this->never())->method('createShare');

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testSharesAgainWhenRecipientRemovedTheShare(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$row = new AttachmentShare();
		$row->setShareId('6');
		$this->mapper->method('findForRecipient')->willReturn($row);
		$this->shareManager->method('getShareById')->willThrowException(new ShareNotFound());
		$this->mapper->expects($this->once())->method('delete')->with($row);
		$this->expectNewRemoteShare('bill@nc2.test', '10');
		$this->mapper->expects($this->once())->method('insert');

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testFailureIsReported(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->shareManager->method('getSharesBy')->willReturn([]);
		$newShare = $this->createMock(IShare::class);
		$newShare->method($this->anything())->willReturnSelf();
		$this->shareManager->method('newShare')->willReturn($newShare);
		$this->shareManager->method('createShare')->willThrowException(new \Exception('Remote unreachable'));
		$this->mapper->expects($this->never())->method('insert');

		$this->assertFalse($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testRefreshDiscoveryIsPassedOnAndSupportIsRemembered(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->expects($this->once())
			->method('remoteSupports')
			->with('nc2.test', true)
			->willReturn(false);

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare, null, true));
		// Second call in the same process: no new discovery request
		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare, null, true));
	}

	private function row(string $shareId, string $origin): AttachmentShare {
		$row = new AttachmentShare();
		$row->setShareId($shareId);
		$row->setOrigin($origin);
		return $row;
	}

	public function testUnshareForRecipient(): void {
		$row = $this->row('6', AttachmentShare::ORIGIN_CREATED);
		$this->mapper->method('findByRecipient')
			->with(12, Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test')
			->willReturn([$row]);
		$this->mapper->method('countByOwnerShareId')->with('', '6')->willReturn(0);
		$remoteShare = $this->createMock(IShare::class);
		$this->shareManager->method('getShareById')->with('ocFederatedSharing:6')->willReturn($remoteShare);
		$this->shareManager->expects($this->once())->method('deleteShare')->with($remoteShare);
		$this->mapper->expects($this->once())->method('delete')->with($row);

		$this->sharer->unshareForRecipient($this->room, 'bill@nc2.test');
	}

	public function testAdoptedShareIsNeverDeleted(): void {
		$row = $this->row('3', AttachmentShare::ORIGIN_ADOPTED);
		$this->mapper->method('findBySource')
			->with(12, AttachmentShare::SOURCE_ROOM_SHARE, '5')
			->willReturn([$row]);
		$this->mapper->method('countByOwnerShareId')->willReturn(0);
		$this->mapper->expects($this->once())->method('delete')->with($row);
		$this->shareManager->expects($this->never())->method('getShareById');
		$this->shareManager->expects($this->never())->method('deleteShare');

		$this->sharer->unshareRoomShare($this->room, '5');
	}

	public function testCreatedShareStillUsedElsewhereIsKept(): void {
		$row = $this->row('6', AttachmentShare::ORIGIN_CREATED);
		$this->mapper->method('findByRoom')->with(12)->willReturn([$row]);
		// e.g. the same file shared into another conversation with Bill
		$this->mapper->method('countByOwnerShareId')->with('', '6')->willReturn(1);
		$this->mapper->expects($this->once())->method('delete')->with($row);
		$this->shareManager->expects($this->never())->method('deleteShare');

		$this->sharer->unshareRoom($this->room);
	}

	public function testCreatedShareIsDeletedAfterItsLastRow(): void {
		$row = $this->row('6', AttachmentShare::ORIGIN_CREATED);
		$this->mapper->method('findBySource')->willReturn([$row]);
		$rowDeleted = false;
		$this->mapper->expects($this->once())
			->method('delete')
			->with($row)
			->willReturnCallback(function (AttachmentShare $row) use (&$rowDeleted): AttachmentShare {
				$rowDeleted = true;
				return $row;
			});
		// Counted after this row is gone
		$this->mapper->method('countByOwnerShareId')
			->with('', '6')
			->willReturnCallback(function () use (&$rowDeleted): int {
				return $rowDeleted ? 0 : 1;
			});
		$remoteShare = $this->createMock(IShare::class);
		$this->shareManager->method('getShareById')->with('ocFederatedSharing:6')->willReturn($remoteShare);
		$this->shareManager->expects($this->once())->method('deleteShare')->with($remoteShare);

		$this->sharer->unshareRoomShare($this->room, '5');
	}
}
