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
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Gives participants on other servers read-only federated shares of conversation files, and removes them again:
 * - on the host, of each room share (Talk 24 conversation folder or single file) — direction A;
 * - on a federated participant's own server, of their sender folder — direction B (design §6).
 */
class AttachmentSharer {
	private const FEDERATED_SHARE_PREFIX = 'ocFederatedSharing:';

	public function __construct(
		private readonly IShareManager $shareManager,
		private readonly RoomShareProvider $roomShareProvider,
		private readonly AttachmentShareMapper $mapper,
		private readonly ParticipantService $participantService,
		private readonly FeatureSupport $featureSupport,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $timeFactory,
		private readonly IURLGenerator $url,
		private readonly ReceivedShareLookup $receivedShares,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param string|null $onlyCloudId Limit to one federated participant
	 * @param bool $refreshDiscovery Bypass the OCM discovery cache when checking which servers support attachments
	 * @return bool False when a share could not be created and a retry makes sense
	 */
	public function shareRoomShare(Room $room, IShare $roomShare, ?string $onlyCloudId = null, bool $refreshDiscovery = false): bool {
		[$recipients, $allServersKnown] = $this->getRecipients($room, $onlyCloudId, $refreshDiscovery);
		return $this->shareWithRecipients($room, $roomShare, $recipients) && $allServersKnown;
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

		[$recipients, $success] = $this->getRecipients($room, $onlyCloudId, $refreshDiscovery);
		if ($recipients === []) {
			return $success;
		}

		foreach ($this->roomShareProvider->getSharesByIds($ids) as $roomShare) {
			try {
				$success = $this->shareWithRecipients($room, $roomShare, $recipients) && $success;
			} catch (\Throwable $e) {
				// One broken room share must not keep the others from being shared
				$this->logger->warning('Could not share conversation attachment ' . $roomShare->getId() . ', retrying later', ['exception' => $e]);
				$success = false;
			}
		}
		return $success;
	}

	/**
	 * A federated participant's server (direction B): gives the conversation's participants on other servers a
	 * read-only federated share of the user's sender folder, and removes the shares of participants who left
	 *
	 * @param Room $room The local proxy conversation
	 * @param list<string> $participantCloudIds Federated participants as listed by the host (host users included)
	 * @return list<array{recipient: string, shareId: string}> The recipients' shares, reported to the host
	 */
	public function shareSenderFolder(Room $room, string $userId, Folder $folder, array $participantCloudIds): array {
		$sourceId = (string)$folder->getId();
		$shares = [];
		/** @var array<string, ?bool> $remoteSupport */
		$remoteSupport = [];
		foreach (array_unique($participantCloudIds) as $cloudId) {
			try {
				$remote = $this->cloudIdManager->resolveCloudId($cloudId)->getRemote();
			} catch (\InvalidArgumentException) {
				continue;
			}
			if (ServerUrl::equals($remote, $this->url->getAbsoluteURL('/'))) {
				// Participants on this server: not in this phase (design non-goal)
				continue;
			}

			$shareId = $this->findExistingShareId($room, AttachmentShare::SOURCE_SENDER_FOLDER, $sourceId, $cloudId);
			if ($shareId === null) {
				if (!array_key_exists($remote, $remoteSupport)) {
					$remoteSupport[$remote] = $this->featureSupport->remoteSupportsUploads($remote);
				}
				if ($remoteSupport[$remote] !== true) {
					// Can't show files of a participant's server (e.g. build 24.0.5.1, ruling R10), or unknown right now:
					// asked again at the next post (ruling R4)
					continue;
				}
				try {
					$shareId = $this->createShareAndRow($room, AttachmentShare::SOURCE_SENDER_FOLDER, $sourceId, $folder, $userId, $cloudId);
				} catch (\Throwable $e) {
					$this->logger->warning('Could not share the conversation folder of ' . $userId . ' with ' . $cloudId, ['exception' => $e]);
					$shareId = null;
				}
			}
			if ($shareId !== null) {
				$shares[] = ['recipient' => $cloudId, 'shareId' => $shareId];
			}
		}

		// Participants who left the conversation lose their share (design §7.1)
		foreach ($this->mapper->findBySource($room->getId(), AttachmentShare::SOURCE_SENDER_FOLDER, $sourceId) as $row) {
			if (!in_array($row->getRecipientActorId(), $participantCloudIds, true)) {
				$this->removeShareAndRow($row);
			}
		}
		return $shares;
	}

	public function unshareRoomShare(Room $room, string $roomShareId): void {
		foreach ($this->mapper->findBySource($room->getId(), AttachmentShare::SOURCE_ROOM_SHARE, $roomShareId) as $row) {
			$this->removeShareAndRow($row);
		}
	}

	/**
	 * Membership is access to the conversation's files: removes what the participant received
	 * (federated shares of the host's files, a host user's copies of files participants sent from their servers)
	 */
	public function unshareForRecipient(Room $room, string $actorType, string $actorId): void {
		foreach ($this->mapper->findByRecipient($room->getId(), $actorType, $actorId) as $row) {
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
	 * @return array{0: list<string>, 1: bool} Cloud ids of accepted federated participants whose server supports
	 *                                         attachments, and false when a participant's server could not be
	 *                                         reached (so whether to share with them is unknown: retry later)
	 */
	private function getRecipients(Room $room, ?string $onlyCloudId, bool $refreshDiscovery): array {
		if ($room->getType() === Room::TYPE_PUBLIC) {
			// Public conversations keep the behaviour without federated attachments (the renderer skips them too)
			return [[], true];
		}

		$recipients = [];
		$allServersKnown = true;
		/** @var array<string, ?bool> $remoteSupport */
		$remoteSupport = [];
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
			if (!array_key_exists($remote, $remoteSupport)) {
				$remoteSupport[$remote] = $this->featureSupport->remoteSupports($remote, $refreshDiscovery);
			}
			if ($remoteSupport[$remote] === null) {
				$allServersKnown = false;
			} elseif ($remoteSupport[$remote]) {
				$recipients[] = $attendee->getActorId();
			}
		}
		return [$recipients, $allServersKnown];
	}

	/**
	 * @param list<string> $recipients
	 */
	private function shareWithRecipients(Room $room, IShare $roomShare, array $recipients): bool {
		$success = true;
		foreach ($recipients as $cloudId) {
			$success = $this->ensureShare($room, $roomShare, $cloudId) && $success;
		}
		return $success;
	}

	private function ensureShare(Room $room, IShare $roomShare, string $cloudId): bool {
		$roomShareId = $roomShare->getId();
		if ($this->findExistingShareId($room, AttachmentShare::SOURCE_ROOM_SHARE, $roomShareId, $cloudId) !== null) {
			return true;
		}

		$sharedBy = $roomShare->getSharedBy();
		$node = $this->rootFolder->getUserFolder($sharedBy)->getFirstNodeById($roomShare->getNodeId());
		if (!$node instanceof Node) {
			// The file is gone, nothing to share
			return true;
		}
		return $this->createShareAndRow($room, AttachmentShare::SOURCE_ROOM_SHARE, $roomShareId, $node, $sharedBy, $cloudId) !== null;
	}

	/**
	 * Id of the recipient's federated share of the source, when Talk shared it before and the share still exists
	 */
	private function findExistingShareId(Room $room, string $sourceType, string $sourceId, string $cloudId): ?string {
		$row = $this->mapper->findForRecipient($room->getId(), $sourceType, $sourceId, Attendee::ACTOR_FEDERATED_USERS, $cloudId);
		if ($row === null) {
			return null;
		}
		if ($this->federatedShareExists($row->getShareId())) {
			return $row->getShareId();
		}
		// The recipient removed it (files_sharing deletes declined shares without an event): share again (design D4)
		$this->mapper->delete($row);
		return null;
	}

	/**
	 * @return string|null Id of the new (or reused) federated share, null when it could not be created
	 */
	private function createShareAndRow(Room $room, string $sourceType, string $sourceId, Node $node, string $ownerId, string $cloudId): ?string {
		$federatedShare = $this->findOrCreateFederatedShare($node, $ownerId, $cloudId);
		if ($federatedShare === null) {
			return null;
		}
		[$shareId, $origin] = $federatedShare;

		$row = new AttachmentShare();
		$row->setRoomId($room->getId());
		$row->setSourceType($sourceType);
		$row->setSourceId($sourceId);
		$row->setOwnerServer('');
		$row->setOwnerActorType(Attendee::ACTOR_USERS);
		$row->setOwnerActorId($ownerId);
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
		return $shareId;
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
	 * Removes a row and what it stands for, and the federated share when Talk created it and no other row uses it
	 * anymore (the same federated share can serve several sources, e.g. one file shared into two conversations)
	 */
	private function removeShareAndRow(AttachmentShare $row): void {
		$this->mapper->delete($row);
		if ($row->getSourceType() === AttachmentShare::SOURCE_REMOTE_FOLDER) {
			// Host row of a file a participant sent from their own server: the share belongs to that server (ruling R8).
			// A host user's received copy is removed, the sender's server is notified by files_sharing (ruling R9).
			// Recipients on other servers keep theirs until the sender's next post drops them (design §7.1).
			if ($row->getRecipientActorType() === Attendee::ACTOR_USERS) {
				$this->receivedShares->decline($row->getRecipientActorId(), $row->getOwnerServer(), $row->getShareId());
			}
			return;
		}
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
