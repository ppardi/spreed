<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Notification;

use OCA\Talk\Federation\Proxy\TalkV1\UserConverter;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Message;
use OCA\Talk\Model\ProxyCacheMessage;
use OCA\Talk\Model\Session;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;

/**
 * @psalm-type TalkFederatedMetaData = array{silent?: bool, last_edited_time?: int, last_edited_by_type?: string, last_edited_by_id?: string, replyToActorType?: string, replyToActorId?: string, replyToMessageId?: int, thread_id?: int}
 */
class FederationChatNotifier {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IManager $notificationManager,
		private readonly UserConverter $userConverter,
		private readonly ThreadService $threadService,
	) {
	}

	/**
	 * @param array{remoteServerUrl: string, sharedSecret: string, remoteToken: string, messageData: array{remoteMessageId: int, actorType: string, actorId: string, actorDisplayName: string, messageType: string, systemMessage: string, expirationDatetime: string, message: string, messageParameter: string, creationDatetime: string, metaData: string}, unreadInfo: array{unreadMessages: int, unreadMention: bool, unreadMentionDirect: bool, lastReadMessage: int}} $inboundNotification
	 */
	public function handleChatMessage(Room $room, Participant $participant, ProxyCacheMessage $message, array $inboundNotification): void {
		if (!empty($inboundNotification['messageData']['systemMessage'])) {
			return;
		}

		if ($participant->getAttendee()->getActorType() === $inboundNotification['messageData']['actorType']
			&& $participant->getAttendee()->getActorId() === $inboundNotification['messageData']['actorId']) {
			return;
		}

		/** @var TalkFederatedMetaData $metaData */
		$metaData = json_decode($inboundNotification['messageData']['metaData'] ?? '', true, flags: JSON_THROW_ON_ERROR);

		if (isset($metaData[Message::METADATA_SILENT])) {
			// Silent message, skip notification handling
			return;
		}

		if ($participant->getSession() instanceof Session && $participant->getSession()->getState() === Session::STATE_ACTIVE) {
			// User has an active session
			return;
		}

		$this->notifyAccordingToLevel($room, $participant, $message, $metaData);
	}

	/**
	 * An edit is delivered as a `message_edited` SYSTEM message, but what we notify about is the
	 * edited message itself, which is an ordinary chat message. That is why this is a separate
	 * entry point rather than a flag on handleChatMessage(): that method is driven by the inbound
	 * payload, and conflating the two is how the system-message guard came to swallow edits.
	 *
	 * The caller has already read the PREVIOUS cached copy, because syncRemoteMessage() rebuilds
	 * the metadata from scratch and keeps only the silent and last_edited_* keys.
	 *
	 * $wasMentionedBefore says whether the participant was mentioned in the previous version: it
	 * is the federated equivalent of ChatManager::editMessage()'s $addedMentions. $isFirstEdit
	 * says whether the previous copy carried no last_edited_time. $previousMetaData carries that
	 * copy's replyTo* and thread_id, which the re-sync drops; it must NOT be taken from the
	 * inbound payload, because for an edit those point at the edited message itself, so
	 * isRepliedTo() would be asking "did I write the message that was edited?" and would notify
	 * the author about their own answer.
	 *
	 * @param TalkFederatedMetaData $previousMetaData
	 */
	public function handleEditedChatMessage(
		Room $room,
		Participant $participant,
		ProxyCacheMessage $message,
		bool $wasMentionedBefore,
		bool $isFirstEdit,
		array $previousMetaData,
	): void {
		if ($message->getSystemMessage()) {
			return;
		}

		$metaData = $message->getParsedMetaData();

		// Do not notify whoever made the edit. This is the EDITOR, who is not necessarily the
		// author: a moderator can edit someone else's message.
		$editedByType = $metaData[Message::METADATA_LAST_EDITED_BY_TYPE] ?? $message->getActorType();
		$editedById = $metaData[Message::METADATA_LAST_EDITED_BY_ID] ?? $message->getActorId();
		if ($participant->getAttendee()->getActorType() === $editedByType
			&& $participant->getAttendee()->getActorId() === $editedById) {
			return;
		}

		if ($participant->getSession() instanceof Session && $participant->getSession()->getState() === Session::STATE_ACTIVE) {
			// User has an active session
			return;
		}

		if (isset($metaData[Message::METADATA_SILENT])) {
			// `silent` means "do not notify on send", not "never notify about this message": a
			// silent message is announced by its FIRST edit, which is the edit that turns a
			// "working on it" placeholder into the answer. Later edits stay quiet, so a typo fix
			// does not notify again. The ordinary rules below then apply, because from the
			// recipient's point of view this message is arriving for the first time - and the
			// answer usually mentions nobody, so isRepliedTo() is what carries it.
			if (!$isFirstEdit) {
				return;
			}
		} elseif ($wasMentionedBefore || !$this->isMentioned($participant, $message)) {
			// A non-silent message already notified when it was sent, so only a NEWLY added
			// mention justifies notifying again. This precondition is also what keeps the
			// NOTIFY_ALWAYS branch below from pinging every always-notify participant on every
			// typo correction.
			return;
		}

		$this->notifyAccordingToLevel($room, $participant, $message, $previousMetaData);
	}

	/**
	 * Applies the recipient's notification level to a message. Shared by handleChatMessage() and
	 * handleEditedChatMessage() so the two can never drift apart.
	 *
	 * $contextMetaData is the metadata carrying replyTo* and thread_id. For a new message that is
	 * the inbound payload's; for an edit it is the previous cached copy's.
	 *
	 * @param TalkFederatedMetaData $contextMetaData
	 */
	protected function notifyAccordingToLevel(Room $room, Participant $participant, ProxyCacheMessage $message, array $contextMetaData): void {
		$threadId = null;
		if (isset($contextMetaData[Message::METADATA_THREAD_ID])) {
			$threadId = (int)$contextMetaData[Message::METADATA_THREAD_ID];
		}

		$notificationLevel = $participant->getAttendee()->getNotificationLevel();
		if ($threadId !== null) {
			$threadAttendees = $this->threadService->findAttendeeByThreadIds($participant->getAttendee(), [$threadId]);
			$threadAttendee = $threadAttendees[$threadId] ?? null;

			if ($threadAttendee !== null
				&& $threadAttendee->getNotificationLevel() !== Participant::NOTIFY_DEFAULT) {
				$notificationLevel = $threadAttendee->getNotificationLevel();
			}
		}

		// Resolve NOTIFY_DEFAULT before comparing, the way Chat\Notifier and RoomFormatter do.
		// Comparing it unresolved made every conversation the user never explicitly configured
		// behave as "@-mentions only": the settings dialog has no control for NOTIFY_DEFAULT and
		// shows the resolved value instead, so such a conversation displays "All messages" while
		// storing NOTIFY_DEFAULT, and the user has no way to see why they are not being notified.
		$defaultLevel = $this->appConfig->getAppValueInt('default_group_notification', Participant::NOTIFY_ALWAYS);
		if ($notificationLevel === Participant::NOTIFY_DEFAULT) {
			if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
				$notificationLevel = Participant::NOTIFY_ALWAYS;
			} elseif ($defaultLevel === Participant::NOTIFY_DEFAULT) {
				$notificationLevel = Participant::NOTIFY_ALWAYS;
			} else {
				$notificationLevel = $defaultLevel;
			}
		}

		if ($notificationLevel === Participant::NOTIFY_MENTION) {
			if ($this->isRepliedTo($room, $participant, $contextMetaData)) {
				$notification = $this->createNotification($room, $message, 'reply', threadId: $threadId);
				$notification->setUser($participant->getAttendee()->getActorId());
				$this->notificationManager->notify($notification);
			} elseif ($this->isMentioned($participant, $message)) {
				$notification = $this->createNotification($room, $message, 'mention', threadId: $threadId);
				$notification->setUser($participant->getAttendee()->getActorId());
				$this->notificationManager->notify($notification);
			} elseif ($this->isMentionedAll($room, $message)) {
				$notification = $this->createNotification($room, $message, 'mention_all', threadId: $threadId);
				$notification->setUser($participant->getAttendee()->getActorId());
				$this->notificationManager->notify($notification);
			}
		} elseif ($notificationLevel === Participant::NOTIFY_ALWAYS) {
			if ($this->isUserMessageOrRelevantSystemMessage($message->getSystemMessage())) {
				$notification = $this->createNotification($room, $message, 'chat', threadId: $threadId);
				$notification->setUser($participant->getAttendee()->getActorId());
				$this->notificationManager->notify($notification);
			}
		}
	}

	/**
	 * @param TalkFederatedMetaData $metaData
	 */
	protected function isRepliedTo(Room $room, Participant $participant, array $metaData): bool {
		if (!isset($metaData[ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_TYPE])
			|| !isset($metaData[ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_ID])
			|| $metaData[ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_TYPE] !== Attendee::ACTOR_FEDERATED_USERS) {
			return false;
		}

		$repliedTo = $this->userConverter->convertTypeAndId($room, $metaData[ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_TYPE], $metaData[ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_ID]);
		return $repliedTo['type'] === $participant->getAttendee()->getActorType()
			&& $repliedTo['id'] === $participant->getAttendee()->getActorId();
	}

	public function isMentioned(Participant $participant, ProxyCacheMessage $message): bool {
		if ($participant->getAttendee()->getActorType() !== Attendee::ACTOR_USERS) {
			return false;
		}

		foreach ($message->getParsedMessageParameters() as $parameter) {
			if ($parameter['type'] === 'user' // RichObjectDefinition, not Attendee::ACTOR_USERS
				&& $parameter['id'] === $participant->getAttendee()->getActorId()
				&& empty($parameter['server'])) {
				return true;
			}
		}

		return false;
	}

	protected function isMentionedAll(Room $room, ProxyCacheMessage $message): bool {
		foreach ($message->getParsedMessageParameters() as $parameter) {
			if ($parameter['type'] === 'call' // RichObjectDefinition
				&& $parameter['id'] === $room->getToken()) {
				return true;
			}
		}

		return false;
	}

	protected function isUserMessageOrRelevantSystemMessage(?string $systemMessage): bool {
		return $systemMessage === null
			|| $systemMessage === ''
			|| $systemMessage === 'object_shared'
			|| $systemMessage === 'poll_closed'
			|| $systemMessage === 'file_shared';
	}

	/**
	 * Creates a notification for the given proxy message and mentioned users
	 */
	protected function createNotification(Room $chat, ProxyCacheMessage $message, string $subject, array $subjectData = [], ?int $threadId = null): INotification {
		$subjectData['userType'] = $message->getActorType();
		$subjectData['userId'] = $message->getActorId();

		$notificationParameters = [
			'proxyId' => $message->getId(),
		];
		if ($threadId !== null) {
			$notificationParameters['threadId'] = $threadId;
		}

		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp('spreed')
			->setObject('chat', $chat->getToken())
			->setSubject($subject, $subjectData)
			->setMessage($message->getMessageType(), $notificationParameters)
			->setDateTime($message->getCreationDatetime());

		return $notification;
	}
}
