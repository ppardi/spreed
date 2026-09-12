<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\Federation\ICloudIdManager;
use OCP\IURLGenerator;

/**
 * Host side of files posted by federated participants (design §6.1): remembers which federated share of the
 * sender folder each recipient received, so the file messages can be shown to them
 */
class RemoteShareRegistry {
	/** More entries than a conversation can have participants on other servers are ignored */
	private const MAX_SHARES = 1000;

	public function __construct(
		private readonly AttachmentShareMapper $mapper,
		private readonly ParticipantService $participantService,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IURLGenerator $url,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param string $owner Cloud id of the sender, from the authenticated request
	 * @param string $folderId Id of the sender folder on the sender's server
	 * @param array<array-key, mixed> $shares Entries {recipient: cloud id, shareId: id of the share on the sender's
	 *                                        server}; recipients that are not in the conversation are ignored
	 */
	public function record(Room $room, string $owner, string $folderId, array $shares): void {
		$ownerServer = ServerUrl::normalize($this->cloudIdManager->resolveCloudId($owner)->getRemote());
		$sourceId = RemoteFile::sourceId($owner, $folderId);

		foreach (array_slice($shares, 0, self::MAX_SHARES) as $share) {
			if (!is_array($share)) {
				continue;
			}
			$recipient = $share['recipient'] ?? null;
			$shareId = $share['shareId'] ?? null;
			if (!is_string($recipient) || !RemoteFile::isId($shareId)) {
				continue;
			}
			$actor = $this->findRecipient($room, $recipient, $owner);
			if ($actor === null) {
				continue;
			}

			$this->saveRow($room, $sourceId, $ownerServer, $owner, $actor[0], $actor[1], $shareId);
		}
		// Recipients missing from the list keep their row (ruling R11): participants who left are handled by
		// AttendeeRemovedEvent, and a row whose share is gone gets the new share id at the next post
	}

	/**
	 * @return array{0: string, 1: string}|null Actor type and id of the recipient in the conversation
	 */
	private function findRecipient(Room $room, string $recipient, string $owner): ?array {
		try {
			$cloudId = $this->cloudIdManager->resolveCloudId($recipient);
		} catch (\InvalidArgumentException) {
			return null;
		}
		if ($cloudId->getId() === $owner) {
			return null;
		}

		$actor = ServerUrl::equals($cloudId->getRemote(), $this->url->getAbsoluteURL('/'))
			? [Attendee::ACTOR_USERS, $cloudId->getUser()]
			: [Attendee::ACTOR_FEDERATED_USERS, $cloudId->getId()];
		try {
			$this->participantService->getParticipantByActor($room, $actor[0], $actor[1]);
		} catch (ParticipantNotFoundException) {
			return null;
		}
		return $actor;
	}

	private function saveRow(Room $room, string $sourceId, string $ownerServer, string $owner, string $actorType, string $actorId, string $shareId): void {
		$row = $this->mapper->findForRecipient($room->getId(), AttachmentShare::SOURCE_REMOTE_FOLDER, $sourceId, $actorType, $actorId);
		if ($row !== null) {
			if ($row->getShareId() !== $shareId) {
				// Shared again, e.g. after the recipient removed the share (design D4)
				$row->setShareId($shareId);
				$this->mapper->update($row);
			}
			return;
		}

		$row = new AttachmentShare();
		$row->setRoomId($room->getId());
		$row->setSourceType(AttachmentShare::SOURCE_REMOTE_FOLDER);
		$row->setSourceId($sourceId);
		$row->setOwnerServer($ownerServer);
		$row->setOwnerActorType(Attendee::ACTOR_FEDERATED_USERS);
		$row->setOwnerActorId($owner);
		$row->setRecipientActorType($actorType);
		$row->setRecipientActorId($actorId);
		$row->setShareId($shareId);
		// The share belongs to the sender's server: never delete a local share with this id (ruling R8)
		$row->setOrigin(AttachmentShare::ORIGIN_ADOPTED);
		$row->setCreatedAt($this->timeFactory->getDateTime());
		try {
			$this->mapper->insert($row);
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// A parallel post of the same sender stored it already
		}
	}
}
