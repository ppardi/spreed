<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Authenticator;
use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Federation\ICloudIdManager;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IPreview;

/**
 * Host: the `file` parameter, per viewer, of a message whose file a federated participant shared from their own
 * server (stored as `federatedFile`, design §6)
 *
 * @psalm-import-type TalkRemoteFile from RemoteFile
 */
class RemoteFileRenderer {
	/** @var array<string, ?Node> Files resolved in this request */
	private array $resolved = [];

	public function __construct(
		private readonly AttachmentShareMapper $mapper,
		private readonly LocalFileResolver $resolver,
		private readonly ConversationFolder $conversationFolder,
		private readonly FileParameterBuilder $builder,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IPreview $previewManager,
		private readonly Authenticator $federationAuthenticator,
	) {
	}

	/**
	 * @param Participant|null $participant The viewer, null for the single render sent to all federated recipients
	 * @return array<string, string>
	 * @throws \InvalidArgumentException When the stored parameter is broken
	 * @throws NotFoundException When the viewer can't see the file (not shared with them, not accepted, gone)
	 */
	public function render(Room $room, ?Participant $participant, mixed $federatedFile): array {
		if (!RemoteFile::isValid($federatedFile)) {
			throw new \InvalidArgumentException('Invalid federatedFile parameter');
		}
		$ownerServer = $this->cloudIdManager->resolveCloudId($federatedFile['owner'])->getRemote();
		$display = $this->getDisplayData($federatedFile);

		if ($participant === null) {
			// Per-room cached copies on remote servers and the signaling relay: no ids, only the name is kept (ruling R6)
			return FederatedFileReference::forDisplay($display, $ownerServer);
		}

		$attendee = $participant->getAttendee();
		if ($attendee->getActorType() === Attendee::ACTOR_USERS) {
			// A user of this server: their received copy of the sender folder (design §6.5)
			$node = $this->resolveForUser($room, $attendee->getActorId(), $ownerServer, $federatedFile);
			return $this->builder->forLocalNode($node, $display);
		}

		if ($attendee->getActorType() !== Attendee::ACTOR_FEDERATED_USERS
			|| $room->getType() === Room::TYPE_PUBLIC
			|| !$this->federationAuthenticator->supportsFederatedAttachments()) {
			throw new NotFoundException('Federated attachments are not available to this viewer');
		}

		if ($attendee->getActorId() === $federatedFile['owner']) {
			// The sender: their server finds the file in their own storage (design §6.6)
			return FederatedFileReference::forOwnFile($display, $ownerServer, $federatedFile['fileId']);
		}

		// A participant on another server: their copy, received from the sender's server (design §6.7)
		$row = $this->findRow($room, $federatedFile, Attendee::ACTOR_FEDERATED_USERS, $attendee->getActorId());
		return FederatedFileReference::forShare($display, $ownerServer, $row->getShareId(), $federatedFile['path']);
	}

	/**
	 * @param TalkRemoteFile $federatedFile
	 * @throws NotFoundException
	 */
	private function resolveForUser(Room $room, string $userId, string $ownerServer, array $federatedFile): Node {
		$row = $this->findRow($room, $federatedFile, Attendee::ACTOR_USERS, $userId);
		$key = $userId . '#' . $ownerServer . '#' . $row->getShareId() . '#' . $federatedFile['path'];
		if (!array_key_exists($key, $this->resolved)) {
			$this->resolved[$key] = $this->resolver->resolve(
				$userId,
				$ownerServer,
				$row->getShareId(),
				$federatedFile['path'],
				$this->conversationFolder->targetForViewer($room, $userId),
			);
		}

		$node = $this->resolved[$key];
		if ($node === null) {
			throw new NotFoundException('The received share is not available (yet)');
		}
		return $node;
	}

	/**
	 * @param TalkRemoteFile $federatedFile
	 * @throws NotFoundException
	 */
	private function findRow(Room $room, array $federatedFile, string $actorType, string $actorId): AttachmentShare {
		$row = $this->mapper->findForRecipient(
			$room->getId(),
			AttachmentShare::SOURCE_REMOTE_FOLDER,
			RemoteFile::sourceId($federatedFile['owner'], $federatedFile['folderId']),
			$actorType,
			$actorId,
		);
		if ($row === null) {
			throw new NotFoundException('The sender\'s server did not share the file with this viewer');
		}
		return $row;
	}

	/**
	 * @param TalkRemoteFile $federatedFile
	 * @return array<string, string>
	 */
	private function getDisplayData(array $federatedFile): array {
		$data = [
			'name' => $federatedFile['name'],
			'size' => (string)$federatedFile['size'],
			'mimetype' => $federatedFile['mimetype'],
			'etag' => $federatedFile['etag'],
			'preview-available' => $federatedFile['size'] > 0 && $this->previewManager->isMimeSupported($federatedFile['mimetype']) ? 'yes' : 'no',
		];
		foreach (['width', 'height', 'blurhash'] as $key) {
			if (isset($federatedFile[$key])) {
				$data[$key] = (string)$federatedFile[$key];
			}
		}
		return $data;
	}
}
