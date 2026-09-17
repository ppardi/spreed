<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Federation\Attachments\FeatureSupport;
use OCA\Talk\Federation\Attachments\ReceivedShareLookup;
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
use OCP\IURLGenerator;
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
	protected IURLGenerator&MockObject $url;
	protected ReceivedShareLookup&MockObject $receivedShares;
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

		$this->url = $this->createMock(IURLGenerator::class);
		$this->url->method('getAbsoluteURL')->with('/')->willReturn('https://nc2.test/');
		$this->receivedShares = $this->createMock(ReceivedShareLookup::class);

		$this->sharer = new AttachmentSharer(
			$this->shareManager,
			$this->roomShareProvider,
			$this->mapper,
			$this->participantService,
			$this->featureSupport,
			$this->cloudIdManager,
			$this->rootFolder,
			$timeFactory,
			$this->url,
			$this->receivedShares,
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

	public function testRefreshDiscoveryIsPassedOn(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->expects($this->once())
			->method('remoteSupports')
			->with('nc2.test', true)
			->willReturn(false);

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare, null, true));
	}

	public function testEachServerIsCheckedOncePerCall(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([
				$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED),
				$this->federatedParticipant('ben@nc2.test', Invitation::STATE_ACCEPTED),
			]);
		$this->featureSupport->expects($this->once())
			->method('remoteSupports')
			->with('nc2.test', false)
			->willReturn(false);

		$this->assertTrue($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testUnreachableServerMeansRetryWithoutTryingToShare(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([
				$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED),
				$this->federatedParticipant('dave@old.test', Invitation::STATE_ACCEPTED),
			]);
		// nc2.test could not be reached, old.test definitely doesn't support attachments
		$this->featureSupport->method('remoteSupports')
			->willReturnCallback(fn (string $remote): ?bool => $remote === 'nc2.test' ? null : false);
		$this->shareManager->expects($this->never())->method('getSharesBy');
		$this->shareManager->expects($this->never())->method('createShare');
		$this->mapper->expects($this->never())->method('insert');

		$this->assertFalse($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testOtherRecipientsAreSharedWithWhileOneServerIsUnreachable(): void {
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([
				$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED),
				$this->federatedParticipant('carol@nc3.test', Invitation::STATE_ACCEPTED),
			]);
		$this->featureSupport->method('remoteSupports')
			->willReturnCallback(fn (string $remote): ?bool => $remote === 'nc2.test' ? null : true);
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->expectNewRemoteShare('carol@nc3.test', '7');
		$this->mapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (AttachmentShare $row): bool => $row->getRecipientActorId() === 'carol@nc3.test'));

		$this->assertFalse($this->sharer->shareRoomShare($this->room, $this->roomShare));
	}

	public function testRecipientsAreFoundOnceForAllRoomShares(): void {
		$otherRoomShare = $this->createMock(IShare::class);
		$otherRoomShare->method('getId')->willReturn('8');
		$otherRoomShare->method('getSharedBy')->willReturn('paul');
		$otherRoomShare->method('getNodeId')->willReturn(193);
		$this->roomShareProvider->method('getShareIdsInRoom')->with('wqhg8fxn')->willReturn(['5', '8']);
		$this->roomShareProvider->method('getSharesByIds')->with(['5', '8'])->willReturn([$this->roomShare, $otherRoomShare]);

		$this->participantService->expects($this->once())
			->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->expects($this->once())->method('remoteSupports')->willReturn(true);
		$row = new AttachmentShare();
		$row->setShareId('6');
		$this->mapper->expects($this->exactly(2))->method('findForRecipient')->willReturn($row);
		$this->shareManager->method('getShareById')->willReturn($this->createMock(IShare::class));

		$this->assertTrue($this->sharer->shareAllRoomShares($this->room));
	}

	public function testOneFailingRoomShareDoesNotStopTheOthers(): void {
		$otherRoomShare = $this->createMock(IShare::class);
		$otherRoomShare->method('getId')->willReturn('8');
		$this->roomShareProvider->method('getShareIdsInRoom')->willReturn(['5', '8']);
		$this->roomShareProvider->method('getSharesByIds')->willReturn([$this->roomShare, $otherRoomShare]);
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(true);

		$row = new AttachmentShare();
		$row->setShareId('9');
		$handled = [];
		$this->mapper->expects($this->exactly(2))
			->method('findForRecipient')
			->willReturnCallback(function (int $roomId, string $sourceType, string $sourceId) use (&$handled, $row): AttachmentShare {
				$handled[] = $sourceId;
				if ($sourceId === '5') {
					throw new \RuntimeException('Broken room share');
				}
				return $row;
			});
		$this->shareManager->method('getShareById')->with('ocFederatedSharing:9')->willReturn($this->createMock(IShare::class));

		$this->assertFalse($this->sharer->shareAllRoomShares($this->room));
		$this->assertSame(['5', '8'], $handled);
	}

	public function testUnreachableServerMakesShareAllRoomSharesRetry(): void {
		$this->roomShareProvider->method('getShareIdsInRoom')->willReturn(['5']);
		$this->roomShareProvider->method('getSharesByIds')->willReturn([$this->roomShare]);
		$this->participantService->method('getParticipantsByActorType')
			->willReturn([$this->federatedParticipant('bill@nc2.test', Invitation::STATE_ACCEPTED)]);
		$this->featureSupport->method('remoteSupports')->willReturn(null);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->assertFalse($this->sharer->shareAllRoomShares($this->room));
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

		$this->sharer->unshareForRecipient($this->room, Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test');
	}

	private function remoteFolderRow(string $recipientType, string $recipientId): AttachmentShare {
		$row = $this->row('31', AttachmentShare::ORIGIN_ADOPTED);
		$row->setSourceType(AttachmentShare::SOURCE_REMOTE_FOLDER);
		$row->setOwnerServer('https://nc2.test');
		$row->setRecipientActorType($recipientType);
		$row->setRecipientActorId($recipientId);
		return $row;
	}

	public function testRemovingAHostUserDeclinesTheirReceivedCopy(): void {
		$row = $this->remoteFolderRow(Attendee::ACTOR_USERS, 'paul');
		$this->mapper->method('findByRecipient')->with(12, Attendee::ACTOR_USERS, 'paul')->willReturn([$row]);
		$this->mapper->expects($this->once())->method('delete')->with($row);
		$this->receivedShares->expects($this->once())->method('decline')->with('paul', 'https://nc2.test', '31');
		// The share belongs to the sender's server: never a local share with the same id
		$this->shareManager->expects($this->never())->method('getShareById');
		$this->shareManager->expects($this->never())->method('deleteShare');

		$this->sharer->unshareForRecipient($this->room, Attendee::ACTOR_USERS, 'paul');
	}

	public function testThirdServerRecipientsKeepTheirShareUntilTheSendersNextPost(): void {
		$row = $this->remoteFolderRow(Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test');
		$this->mapper->method('findByRoom')->with(12)->willReturn([$row]);
		$this->mapper->expects($this->once())->method('delete')->with($row);
		$this->receivedShares->expects($this->never())->method('decline');
		$this->shareManager->expects($this->never())->method('deleteShare');

		$this->sharer->unshareRoom($this->room);
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

	private function senderFolder(): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(42);
		return $folder;
	}

	/**
	 * New federated shares of Bill's sender folder get the ids 21, 22, … in creation order
	 *
	 * @return \ArrayObject<int, string> Cloud ids the shares are created for, filled while the test runs
	 */
	private function expectSenderFolderShares(Folder $folder, int $count): \ArrayObject {
		$sharedWith = new \ArrayObject();
		$this->shareManager->method('getSharesBy')->with('bill', IShare::TYPE_REMOTE, $folder, false, -1)->willReturn([]);
		$this->shareManager->method('newShare')->willReturnCallback(function () use ($folder, $sharedWith): IShare {
			$share = $this->createMock(IShare::class);
			$share->method('setNode')->with($folder)->willReturnSelf();
			$share->method('setShareType')->with(IShare::TYPE_REMOTE)->willReturnSelf();
			$share->method('setSharedBy')->with('bill')->willReturnSelf();
			$share->method('setSharedWith')->willReturnCallback(function (string $cloudId) use ($share, $sharedWith): IShare {
				$sharedWith[] = $cloudId;
				return $share;
			});
			$share->method('setPermissions')->with(Constants::PERMISSION_READ)->willReturnSelf();
			return $share;
		});
		$this->shareManager->expects($this->exactly($count))->method('createShare')->willReturnCallback(function () use ($sharedWith): IShare {
			$created = $this->createMock(IShare::class);
			$created->method('getId')->willReturn((string)(20 + count($sharedWith)));
			return $created;
		});
		return $sharedWith;
	}

	public function testSenderFolderIsSharedWithParticipantsOnOtherServers(): void {
		$folder = $this->senderFolder();
		$this->featureSupport->method('remoteSupportsUploads')->willReturn(true);
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->mapper->method('findBySource')->willReturn([]);
		$sharedWith = $this->expectSenderFolderShares($folder, 2);
		$inserted = [];
		$this->mapper->method('insert')->willReturnCallback(function (AttachmentShare $row) use (&$inserted): AttachmentShare {
			$inserted[] = [$row->getRoomId(), $row->getSourceType(), $row->getSourceId(), $row->getOwnerServer(),
				$row->getOwnerActorType(), $row->getOwnerActorId(), $row->getRecipientActorType(), $row->getRecipientActorId(), $row->getShareId()];
			return $row;
		});

		$shares = $this->sharer->shareSenderFolder($this->room, 'bill', $folder, ['paul@nc1.test', 'dave@nc2.test', 'carol@nc3.test']);

		$this->assertSame([
			['recipient' => 'paul@nc1.test', 'shareId' => '21'],
			['recipient' => 'carol@nc3.test', 'shareId' => '22'],
		], $shares);
		// Dave is on Bill's own server: not in this phase
		$this->assertSame(['paul@nc1.test', 'carol@nc3.test'], $sharedWith->getArrayCopy());
		$this->assertSame([
			[12, AttachmentShare::SOURCE_SENDER_FOLDER, '42', '', Attendee::ACTOR_USERS, 'bill', Attendee::ACTOR_FEDERATED_USERS, 'paul@nc1.test', '21'],
			[12, AttachmentShare::SOURCE_SENDER_FOLDER, '42', '', Attendee::ACTOR_USERS, 'bill', Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test', '22'],
		], $inserted);
	}

	public function testExistingSenderFolderShareIsReusedWithoutAskingTheServer(): void {
		$row = $this->row('21', AttachmentShare::ORIGIN_CREATED);
		$row->setRecipientActorId('paul@nc1.test');
		$this->mapper->method('findForRecipient')
			->with(12, AttachmentShare::SOURCE_SENDER_FOLDER, '42', Attendee::ACTOR_FEDERATED_USERS, 'paul@nc1.test')
			->willReturn($row);
		$this->shareManager->method('getShareById')->with('ocFederatedSharing:21')->willReturn($this->createMock(IShare::class));
		$this->mapper->method('findBySource')->willReturn([$row]);
		$this->featureSupport->expects($this->never())->method('remoteSupportsUploads');
		$this->shareManager->expects($this->never())->method('createShare');
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame(
			[['recipient' => 'paul@nc1.test', 'shareId' => '21']],
			$this->sharer->shareSenderFolder($this->room, 'bill', $this->senderFolder(), ['paul@nc1.test']),
		);
	}

	public function testServersWithoutSupportOrUnreachableAreSkipped(): void {
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->mapper->method('findBySource')->willReturn([]);
		$this->featureSupport->method('remoteSupportsUploads')
			->willReturnCallback(fn (string $remote): ?bool => $remote === 'nc1.test' ? false : null);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->assertSame([], $this->sharer->shareSenderFolder($this->room, 'bill', $this->senderFolder(), ['paul@nc1.test', 'carol@nc3.test']));
	}

	public function testFailedShareOnlySkipsThatRecipient(): void {
		$this->featureSupport->method('remoteSupportsUploads')->willReturn(true);
		$this->mapper->method('findForRecipient')->willReturn(null);
		$this->mapper->method('findBySource')->willReturn([]);
		$this->shareManager->method('getSharesBy')->willReturn([]);
		$this->shareManager->method('newShare')->willReturnCallback(function (): IShare {
			$share = $this->createMock(IShare::class);
			foreach (['setNode', 'setShareType', 'setSharedBy', 'setSharedWith', 'setPermissions'] as $method) {
				$share->method($method)->willReturnSelf();
			}
			return $share;
		});
		$created = $this->createMock(IShare::class);
		$created->method('getId')->willReturn('22');
		$calls = 0;
		$this->shareManager->method('createShare')->willReturnCallback(function () use (&$calls, $created): IShare {
			if (++$calls === 1) {
				throw new \Exception('Remote server unreachable');
			}
			return $created;
		});

		$this->assertSame(
			[['recipient' => 'carol@nc3.test', 'shareId' => '22']],
			$this->sharer->shareSenderFolder($this->room, 'bill', $this->senderFolder(), ['paul@nc1.test', 'carol@nc3.test']),
		);
	}

	public function testParticipantsWhoLeftLoseTheirShare(): void {
		$row = $this->row('23', AttachmentShare::ORIGIN_CREATED);
		$row->setRecipientActorId('eve@nc4.test');
		$this->mapper->method('findBySource')->with(12, AttachmentShare::SOURCE_SENDER_FOLDER, '42')->willReturn([$row]);
		$this->mapper->expects($this->once())->method('delete')->with($row);
		$this->mapper->method('countByOwnerShareId')->with('', '23')->willReturn(0);
		$remoteShare = $this->createMock(IShare::class);
		$this->shareManager->method('getShareById')->with('ocFederatedSharing:23')->willReturn($remoteShare);
		$this->shareManager->expects($this->once())->method('deleteShare')->with($remoteShare);

		$this->assertSame([], $this->sharer->shareSenderFolder($this->room, 'bill', $this->senderFolder(), []));
	}
}
