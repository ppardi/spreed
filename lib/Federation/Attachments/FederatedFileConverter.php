<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Federation\ICloudIdManager;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Replaces `federated-file` references in proxied messages with the viewer's local file
 * (or a text fallback), so clients only ever see normal `file` parameters.
 */
class FederatedFileConverter {
	/** @var array<string, ?Node> Resolved references of this request */
	private array $resolved = [];

	public function __construct(
		private readonly LocalFileResolver $resolver,
		private readonly FileParameterBuilder $builder,
		private readonly ConversationFolder $conversationFolder,
		private readonly IRootFolder $rootFolder,
		private readonly IURLGenerator $url,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<array-key, array> $messages
	 * @return array<array-key, array>
	 */
	public function convertMessages(Room $room, Participant $participant, array $messages): array {
		return array_map(
			fn (array $message): array => $this->convertMessage($room, $participant, $message),
			$messages,
		);
	}

	/**
	 * Thread infos carry the thread's first and last message
	 */
	public function convertThreadInfo(Room $room, Participant $participant, array $threadInfo): array {
		foreach (['first', 'last'] as $key) {
			if (isset($threadInfo[$key]) && is_array($threadInfo[$key])) {
				$threadInfo[$key] = $this->convertMessage($room, $participant, $threadInfo[$key]);
			}
		}
		return $threadInfo;
	}

	/**
	 * @param list<array> $threadInfos
	 * @return list<array>
	 */
	public function convertThreadInfos(Room $room, Participant $participant, array $threadInfos): array {
		return array_map(
			fn (array $threadInfo): array => $this->convertThreadInfo($room, $participant, $threadInfo),
			$threadInfos,
		);
	}

	public function convertMessage(Room $room, Participant $participant, array $message): array {
		if (isset($message['parent']) && is_array($message['parent'])) {
			$message['parent'] = $this->convertMessage($room, $participant, $message['parent']);
		}

		$reference = $message['messageParameters']['file'] ?? null;
		if (!FederatedFileReference::isReference($reference)) {
			return $message;
		}

		// Comes from the host: don't trust the types
		$name = is_string($reference['name']) ? $reference['name'] : '';
		try {
			$node = $this->resolve($room, $participant, $reference);
			if ($node !== null) {
				$message['messageParameters']['file'] = $this->builder->forLocalNode($node, $reference);
				return $message;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not show federated attachment ' . $name, ['exception' => $e]);
		}

		return $this->withFallback($message, $name);
	}

	/**
	 * @param array<string, string> $reference
	 */
	private function resolve(Room $room, Participant $participant, array $reference): ?Node {
		$attendee = $participant->getAttendee();
		if ($attendee->getActorType() !== Attendee::ACTOR_USERS) {
			return null;
		}

		$userId = $attendee->getActorId();
		$ownFile = isset($reference['file-id']);
		// Includes the user: the service is shared by everything in the process (cron, occ, OCM requests)
		$key = $userId . '#' . $reference['server'] . '#'
			. ($ownFile ? 'file:' . $reference['file-id'] : 'share:' . $reference['share-id'] . '#' . $reference['path']);
		if (!array_key_exists($key, $this->resolved)) {
			$this->resolved[$key] = $ownFile
				? $this->resolveOwnFile($attendee, $reference['server'], $reference['file-id'])
				// A share the user received: of the host's files, or of files a participant sent from their own
				// server (design §6). The lookup only finds shares this user accepted from that server (ruling R5).
				: $this->resolver->resolve($userId, $reference['server'], $reference['share-id'], $reference['path'], $this->conversationFolder->targetForViewer($room, $userId));
		}
		return $this->resolved[$key];
	}

	/**
	 * The viewer sent the file from this server (design §5.1): only their own storage is searched
	 */
	private function resolveOwnFile(Attendee $attendee, string $server, string $fileId): ?Node {
		if (!ctype_digit($fileId) || !ServerUrl::equals($server, $this->serverAsTheHostKnowsIt($attendee))) {
			return null;
		}
		return $this->rootFolder->getUserFolder($attendee->getActorId())->getFirstNodeById((int)$fileId);
	}

	/**
	 * The host computes the reference's server from the sender's cloud id: this server's own URL can differ from it
	 * (an alias host, a proxy without `overwriteprotocol`), the remote of the cloud id the host invited can't
	 */
	private function serverAsTheHostKnowsIt(Attendee $attendee): string {
		$invitedCloudId = (string)$attendee->getInvitedCloudId();
		if ($invitedCloudId !== '') {
			try {
				return $this->cloudIdManager->resolveCloudId($invitedCloudId)->getRemote();
			} catch (\InvalidArgumentException) {
				// Fall back to this server's URL
			}
		}
		return $this->url->getAbsoluteURL('/');
	}

	private function withFallback(array $message, string $fileName): array {
		unset($message['messageParameters']['file']);
		$text = '*' . $this->l->t('"%s" is not available', [$fileName]) . '*';
		$message['message'] = $message['message'] === '{file}' ? $text : $text . "\n\n" . $message['message'];
		return $message;
	}
}
