<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\DB\Exception as DBException;
use OCP\Federation\ICloudIdManager;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Host side: gives every federated participant a read-only federated share of each room share
 * (Talk 24 conversation folder or single file) and removes them again.
 */
class AttachmentSharer {
	private const FEDERATED_SHARE_PREFIX = 'ocFederatedSharing:';

	/** @var array<string, bool> Remote server => supports federated attachments (for this process) */
	private array $remoteSupport = [];

	public function __construct(
		private readonly IShareManager $shareManager,
		private readonly RoomShareProvider $roomShareProvider,
		private readonly AttachmentShareMapper $mapper,
		private readonly ParticipantService $participantService,
		private readonly FeatureSupport $featureSupport,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param string|null $onlyCloudId Limit to one federated participant
	 * @param bool $refreshDiscovery Bypass the OCM discovery cache when checking which servers support attachments
	 * @return bool False when a share could not be created and a retry makes sense
	 */
	public function shareRoomShare(Room $room, IShare $roomShare, ?string $onlyCloudId = null, bool $refreshDiscovery = false): bool {
		$success = true;
		foreach ($this->getRecipients($room, $onlyCloudId, $refreshDiscovery) as $cloudId) {
			$success = $this->ensureShare($room, $roomShare, $cloudId) && $success;
		}
		return $success;
	}

	/**
	 * @param string|null $onlyCloudId Limit to one federated participant
	 * @param bool $refreshDiscovery Bypass the OCM discovery cache when checking which servers support attachments
	 * @return bool False when a share could not be created and a retry makes sense
	 */
	public function shareAllRoomShares(Room $room, ?string $onlyCloudId = null, bool $refreshDiscovery = false): bool {
		$ids = $this->roomShareProvider->getShareIdsInRoom($room->getToken());
		if ($ids === []) {
			return true;
		}

		$success = true;
		foreach ($this->roomShareProvider->getSharesByIds($ids) as $roomShare) {
			$success = $this->shareRoomShare($room, $roomShare, $onlyCloudId, $refreshDiscovery) && $success;
		}
		return $success;
	}

	public function unshareRoomShare(Room $room, string $roomShareId): void {
		foreach ($this->mapper->findBySource($room->getId(), AttachmentShare::SOURCE_ROOM_SHARE, $roomShareId) as $row) {
			$this->removeShareAndRow($row);
		}
	}

	public function unshareForRecipient(Room $room, string $cloudId): void {
		foreach ($this->mapper->findByRecipient($room->getId(), Attendee::ACTOR_FEDERATED_USERS, $cloudId) as $row) {
			$this->removeShareAndRow($row);
		}
	}

	public function unshareRoom(Room $room): void {
		foreach ($this->mapper->findByRoom($room->getId()) as $row) {
			$this->removeShareAndRow($row);
		}
	}

	public function forgetRemoteShare(string $shareId): void {
		$this->mapper->deleteByOwnerShareId('', $shareId);
	}

	/**
	 * @return list<string> Cloud ids of accepted federated participants whose server supports attachments
	 */
	private function getRecipients(Room $room, ?string $onlyCloudId, bool $refreshDiscovery): array {
		if ($room->getType() === Room::TYPE_PUBLIC) {
			// Public conversations keep the behaviour without federated attachments (the renderer skips them too)
			return [];
		}

		$recipients = [];
		foreach ($this->participantService->getParticipantsByActorType($room, Attendee::ACTOR_FEDERATED_USERS) as $participant) {
			$attendee = $participant->getAttendee();
			if ($attendee->getState() !== Invitation::STATE_ACCEPTED) {
				continue;
			}
			if ($onlyCloudId !== null && $attendee->getActorId() !== $onlyCloudId) {
				continue;
			}

			try {
				$remote = $this->cloudIdManager->resolveCloudId($attendee->getActorId())->getRemote();
			} catch (\InvalidArgumentException) {
				continue;
			}
			$this->remoteSupport[$remote] ??= $this->featureSupport->remoteSupports($remote, $refreshDiscovery);
			if ($this->remoteSupport[$remote]) {
				$recipients[] = $attendee->getActorId();
			}
		}
		return $recipients;
	}

	private function ensureShare(Room $room, IShare $roomShare, string $cloudId): bool {
		$roomShareId = $roomShare->getId();
		$row = $this->mapper->findForRecipient($room->getId(), AttachmentShare::SOURCE_ROOM_SHARE, $roomShareId, Attendee::ACTOR_FEDERATED_USERS, $cloudId);
		if ($row !== null) {
			if ($this->federatedShareExists($row->getShareId())) {
				return true;
			}
			// The recipient removed it (files_sharing deletes declined shares without an event): share again (design D4)
			$this->mapper->delete($row);
		}

		$sharedBy = $roomShare->getSharedBy();
		$node = $this->rootFolder->getUserFolder($sharedBy)->getFirstNodeById($roomShare->getNodeId());
		if (!$node instanceof Node) {
			// The file is gone, nothing to share
			return true;
		}

		$federatedShare = $this->findOrCreateFederatedShare($node, $sharedBy, $cloudId);
		if ($federatedShare === null) {
			return false;
		}
		[$shareId, $origin] = $federatedShare;

		$row = new AttachmentShare();
		$row->setRoomId($room->getId());
		$row->setSourceType(AttachmentShare::SOURCE_ROOM_SHARE);
		$row->setSourceId($roomShareId);
		$row->setOwnerServer('');
		$row->setOwnerActorType(Attendee::ACTOR_USERS);
		$row->setOwnerActorId($sharedBy);
		$row->setRecipientActorType(Attendee::ACTOR_FEDERATED_USERS);
		$row->setRecipientActorId($cloudId);
		$row->setShareId($shareId);
		$row->setOrigin($origin);
		$row->setCreatedAt($this->timeFactory->getDateTime());
		try {
			$this->mapper->insert($row);
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// A parallel request stored it already
		}
		return true;
	}

	private function federatedShareExists(string $shareId): bool {
		try {
			$this->shareManager->getShareById(self::FEDERATED_SHARE_PREFIX . $shareId);
			return true;
		} catch (ShareNotFound) {
			return false;
		}
	}

	/**
	 * @return array{0: string, 1: string}|null Share id and origin (AttachmentShare::ORIGIN_*), null on failure
	 */
	private function findOrCreateFederatedShare(Node $node, string $sharedBy, string $cloudId): ?array {
		foreach ($this->shareManager->getSharesBy($sharedBy, IShare::TYPE_REMOTE, $node, false, -1) as $existing) {
			if ($existing->getSharedWith() === $cloudId) {
				$shareId = $existing->getId();
				// Reusing a share Talk created for another source (the same file in another conversation)
				// keeps it Talk's, anything else (e.g. the user's own share) must never be removed by Talk
				$createdByTalk = $this->mapper->countByOwnerShareId('', $shareId, AttachmentShare::ORIGIN_CREATED) > 0;
				return [$shareId, $createdByTalk ? AttachmentShare::ORIGIN_CREATED : AttachmentShare::ORIGIN_ADOPTED];
			}
		}

		$share = $this->shareManager->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_REMOTE)
			->setSharedBy($sharedBy)
			->setSharedWith($cloudId)
			->setPermissions(Constants::PERMISSION_READ);

		try {
			return [$this->shareManager->createShare($share)->getId(), AttachmentShare::ORIGIN_CREATED];
		} catch (\Exception $e) {
			$this->logger->warning('Could not share conversation attachment with ' . $cloudId, ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Removes the row, and the federated share when Talk created it and no other row uses it anymore
	 * (the same federated share can serve several sources, e.g. one file shared into two conversations)
	 */
	private function removeShareAndRow(AttachmentShare $row): void {
		$this->mapper->delete($row);
		if ($row->getOrigin() !== AttachmentShare::ORIGIN_CREATED
			|| $this->mapper->countByOwnerShareId('', $row->getShareId()) > 0) {
			return;
		}

		try {
			$share = $this->shareManager->getShareById(self::FEDERATED_SHARE_PREFIX . $row->getShareId());
			$this->shareManager->deleteShare($share);
		} catch (ShareNotFound) {
			// Already gone
		} catch (\Exception $e) {
			$this->logger->warning('Could not remove federated attachment share ' . $row->getShareId(), ['exception' => $e]);
		}
	}
}
