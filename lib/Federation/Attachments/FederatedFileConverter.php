<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Config;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUserSession;
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
		private readonly Config $talkConfig,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
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

		try {
			$node = $this->resolve($room, $participant, $reference);
			if ($node !== null) {
				$message['messageParameters']['file'] = $this->builder->forLocalNode($node, $reference);
				return $message;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not show federated attachment ' . $reference['name'], ['exception' => $e]);
		}

		return $this->withFallback($message, $reference['name']);
	}

	/**
	 * @param array<string, string> $reference
	 */
	private function resolve(Room $room, Participant $participant, array $reference): ?Node {
		$attendee = $participant->getAttendee();
		if ($attendee->getActorType() !== Attendee::ACTOR_USERS) {
			return null;
		}
		// Plan 1: files are always owned by the conversation's host
		if (!ServerUrl::equals($reference['server'], $room->getRemoteServer())) {
			return null;
		}

		$userId = $attendee->getActorId();
		// Includes the user: the service is shared by everything in the process (cron, occ, OCM requests)
		$key = $userId . '#' . $reference['server'] . '#' . $reference['share-id'] . '#' . $reference['path'];
		if (!array_key_exists($key, $this->resolved)) {
			// Moving the received share needs the user's own session (not the case in OCM notifications)
			$targetFolder = $this->userSession->getUser()?->getUID() === $userId ? $this->getTargetFolder($room, $userId) : null;
			$this->resolved[$key] = $this->resolver->resolve($userId, $reference['server'], $reference['share-id'], $reference['path'], $targetFolder);
		}
		return $this->resolved[$key];
	}

	/**
	 * The viewer's conversation folder, e.g. "Talk/Room-both-64z86muv" (design D3)
	 */
	private function getTargetFolder(Room $room, string $userId): ?string {
		if (!$this->talkConfig->isConversationSubfoldersEnabled()) {
			return null;
		}
		return trim($this->talkConfig->getAttachmentFolder($userId), '/') . '/' . $this->talkConfig->getConversationFolderName($room, $userId);
	}

	private function withFallback(array $message, string $fileName): array {
		unset($message['messageParameters']['file']);
		$text = '*' . $this->l->t('"%s" is not available', [$fileName]) . '*';
		$message['message'] = $message['message'] === '{file}' ? $text : $text . "\n\n" . $message['message'];
		return $message;
	}
}
