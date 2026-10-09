<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Notification;

use OCA\Talk\Federation\Proxy\TalkV1\UserConverter;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Message;
use OCA\Talk\Model\ProxyCacheMessage;
use OCA\Talk\Model\Session;
use OCA\Talk\Model\ThreadAttendee;
use OCA\Talk\Notification\FederationChatNotifier;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class FederationChatNotifierTest extends TestCase {
	protected IAppConfig&MockObject $appConfig;
	protected INotificationManager&MockObject $notificationManager;
	protected UserConverter&MockObject $userConverter;
	protected ThreadService&MockObject $threadService;
	protected FederationChatNotifier $notifier;

	public function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->userConverter = $this->createMock(UserConverter::class);
		$this->threadService = $this->createMock(ThreadService::class);

		$this->notifier = new FederationChatNotifier(
			$this->appConfig,
			$this->notificationManager,
			$this->userConverter,
			$this->threadService,
		);
	}

	/**
	 * A plain message from the host, with no mention of the recipient and not a
	 * reply to one of their messages.
	 */
	protected static function plainMessage(): array {
		return [
			'remoteServerUrl' => 'https://host.local',
			'sharedSecret' => 'secret',
			'remoteToken' => 'remotetoken',
			'messageData' => [
				'remoteMessageId' => 42,
				'actorType' => Attendee::ACTOR_FEDERATED_USERS,
				'actorId' => 'bill@host.local',
				'actorDisplayName' => 'Bill',
				'messageType' => 'comment',
				'systemMessage' => '',
				'expirationDatetime' => '',
				'message' => 'Any news?',
				'messageParameter' => '[]',
				'creationDatetime' => '2026-09-17T11:00:00+00:00',
				'metaData' => '[]',
			],
			'unreadInfo' => [
				'unreadMessages' => 1,
				'unreadMention' => false,
				'unreadMentionDirect' => false,
				'lastReadMessage' => 41,
			],
		];
	}

	protected function participantWithLevel(Room $room, int $notificationLevel): Participant {
		$attendee = Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'paul',
			'participant_type' => Participant::USER,
			'notification_level' => $notificationLevel,
		]);

		// No session: the recipient is not actively viewing the conversation
		return new Participant($room, $attendee, null);
	}

	protected function proxyCacheMessage(): ProxyCacheMessage {
		$message = new ProxyCacheMessage();
		$message->setId(1);
		$message->setLocalToken('localtoken');
		$message->setRemoteServerUrl('https://host.local');
		$message->setRemoteToken('remotetoken');
		$message->setRemoteMessageId(42);
		$message->setActorType(Attendee::ACTOR_FEDERATED_USERS);
		$message->setActorId('bill@host.local');
		$message->setActorDisplayName('Bill');
		$message->setMessageType('comment');
		$message->setSystemMessage('');
		$message->setMessage('Any news?');
		$message->setMessageParameters('[]');
		$message->setCreationDatetime(new \DateTime('2026-09-17T11:00:00+00:00'));
		$message->setMetaData('[]');

		return $message;
	}

	public static function dataHandleChatMessageNotificationLevel(): array {
		return [
			// The stored level is NOTIFY_DEFAULT for every conversation the user
			// never explicitly configured. The settings dialog offers no control
			// for it and displays the resolved value, so "All messages" showing
			// as selected does not mean NOTIFY_ALWAYS was ever stored.
			'default level, admin default always => every message' => [Participant::NOTIFY_DEFAULT, Participant::NOTIFY_ALWAYS, Room::TYPE_GROUP, 'chat'],
			'default level, admin default unset => every message' => [Participant::NOTIFY_DEFAULT, Participant::NOTIFY_DEFAULT, Room::TYPE_GROUP, 'chat'],
			'default level, admin default mention => only mentions' => [Participant::NOTIFY_DEFAULT, Participant::NOTIFY_MENTION, Room::TYPE_GROUP, null],
			'default level, admin default never => nothing' => [Participant::NOTIFY_DEFAULT, Participant::NOTIFY_NEVER, Room::TYPE_GROUP, null],
			// One-to-one conversations notify on every message regardless of the
			// admin default, matching how RoomFormatter reports the level.
			'default level, one-to-one, admin default mention => every message' => [Participant::NOTIFY_DEFAULT, Participant::NOTIFY_MENTION, Room::TYPE_ONE_TO_ONE, 'chat'],
			'always => every message' => [Participant::NOTIFY_ALWAYS, Participant::NOTIFY_ALWAYS, Room::TYPE_GROUP, 'chat'],
			'mention => only mentions' => [Participant::NOTIFY_MENTION, Participant::NOTIFY_ALWAYS, Room::TYPE_GROUP, null],
			'never => nothing' => [Participant::NOTIFY_NEVER, Participant::NOTIFY_ALWAYS, Room::TYPE_GROUP, null],
		];
	}

	#[DataProvider('dataHandleChatMessageNotificationLevel')]
	public function testHandleChatMessageNotificationLevel(int $storedLevel, int $adminDefault, int $roomType, ?string $expectedSubject): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('localtoken');
		$room->method('getType')->willReturn($roomType);

		$this->appConfig->method('getAppValueInt')
			->with('default_group_notification', Participant::NOTIFY_ALWAYS)
			->willReturn($adminDefault);

		if ($expectedSubject !== null) {
			$notification = $this->createMock(INotification::class);
			$notification->method('setApp')->willReturnSelf();
			$notification->method('setObject')->willReturnSelf();
			$notification->method('setMessage')->willReturnSelf();
			$notification->method('setDateTime')->willReturnSelf();
			$notification->method('setUser')->willReturnSelf();
			$notification->expects($this->once())
				->method('setSubject')
				->with($expectedSubject, $this->anything())
				->willReturnSelf();

			$this->notificationManager->expects($this->once())
				->method('createNotification')
				->willReturn($notification);
			$this->notificationManager->expects($this->once())
				->method('notify')
				->with($notification);
		} else {
			$this->notificationManager->expects($this->never())
				->method('notify');
		}

		$this->notifier->handleChatMessage(
			$room,
			$this->participantWithLevel($room, $storedLevel),
			$this->proxyCacheMessage(),
			self::plainMessage(),
		);
	}

	/**
	 * A thread the user subscribed to with "All messages" notifies on every message
	 * in that thread, even when the conversation itself is on "@-mentions only".
	 */
	public function testHandleChatMessageThreadLevelOverridesConversationLevel(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('localtoken');
		$room->method('getType')->willReturn(Room::TYPE_GROUP);

		$this->appConfig->method('getAppValueInt')
			->with('default_group_notification', Participant::NOTIFY_ALWAYS)
			->willReturn(Participant::NOTIFY_ALWAYS);

		$threadAttendee = new ThreadAttendee();
		$threadAttendee->setNotificationLevel(Participant::NOTIFY_ALWAYS);
		$this->threadService->method('findAttendeeByThreadIds')
			->willReturn([99 => $threadAttendee]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setMessage')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with('chat', $this->anything())
			->willReturnSelf();

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);
		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$inboundNotification = self::plainMessage();
		$inboundNotification['messageData']['metaData'] = json_encode([Message::METADATA_THREAD_ID => 99]);

		$message = $this->proxyCacheMessage();
		$message->setMetaData($inboundNotification['messageData']['metaData']);

		$this->notifier->handleChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_MENTION),
			$message,
			$inboundNotification,
		);
	}

	/**
	 * The edited message as it looks AFTER syncRemoteMessage() has refreshed it: that rebuild
	 * keeps `silent` and the last_edited_* keys and drops everything else, replyTo* included.
	 */
	protected function editedMessage(array $metaData, string $messageParameters = '[]'): ProxyCacheMessage {
		$message = $this->proxyCacheMessage();
		$message->setMessage('Here is the answer');
		$message->setMessageParameters($messageParameters);
		$message->setMetaData(json_encode($metaData));

		return $message;
	}

	/** The metadata of the PREVIOUS cached copy, which is the only place replyTo* survives. */
	protected static function previousMetaDataReplyingTo(string $cloudId): array {
		return [
			ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_TYPE => Attendee::ACTOR_FEDERATED_USERS,
			ProxyCacheMessage::METADATA_REPLY_TO_ACTOR_ID => $cloudId,
			ProxyCacheMessage::METADATA_REPLY_TO_MESSAGE_ID => 41,
		];
	}

	protected function expectNotificationSubject(?string $subject): void {
		if ($subject === null) {
			$this->notificationManager->expects($this->never())->method('notify');
			return;
		}

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setMessage')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with($subject, $this->anything())
			->willReturnSelf();

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);
		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);
	}

	protected function roomForEdit(): Room {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('localtoken');
		$room->method('getType')->willReturn(Room::TYPE_GROUP);

		$this->appConfig->method('getAppValueInt')
			->with('default_group_notification', Participant::NOTIFY_ALWAYS)
			->willReturn(Participant::NOTIFY_ALWAYS);

		return $room;
	}

	public static function dataEditedMentionDiff(): array {
		return [
			// Change A: the federated equivalent of ChatManager::editMessage()'s $addedMentions.
			'mention added => notify' => [false, true, 'mention'],
			'mention unchanged => quiet' => [true, true, null],
			'mention removed => quiet' => [true, false, null],
			'never mentioned => quiet' => [false, false, null],
		];
	}

	/**
	 * A non-silent message already notified when it was sent, so only a NEWLY added mention
	 * justifies notifying again.
	 */
	#[DataProvider('dataEditedMentionDiff')]
	public function testHandleEditedChatMessageMentionDiff(bool $wasMentionedBefore, bool $isMentionedNow, ?string $expectedSubject): void {
		$room = $this->roomForEdit();
		$this->expectNotificationSubject($expectedSubject);

		$parameters = $isMentionedNow ? json_encode([['type' => 'user', 'id' => 'paul']]) : '[]';

		$this->notifier->handleEditedChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_MENTION),
			$this->editedMessage([Message::METADATA_LAST_EDITED_TIME => 1760000000], $parameters),
			$wasMentionedBefore,
			false,
			[],
		);
	}

	/**
	 * Change B, the placeholder case: a silent message is announced by its FIRST edit, and the
	 * answer edited into it usually mentions nobody - it is merely threaded onto the question, so
	 * isRepliedTo() is what carries the notification. The replyTo metadata has to come from the
	 * PREVIOUS cached copy, because the re-sync drops it.
	 */
	public function testHandleEditedChatMessageAnnouncesSilentMessageOnFirstEditViaReply(): void {
		$room = $this->roomForEdit();

		$this->userConverter->method('convertTypeAndId')
			->with($room, Attendee::ACTOR_FEDERATED_USERS, 'paul@local.test')
			->willReturn(['type' => Attendee::ACTOR_USERS, 'id' => 'paul']);

		$this->expectNotificationSubject('reply');

		$this->notifier->handleEditedChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_MENTION),
			$this->editedMessage([
				Message::METADATA_SILENT => true,
				Message::METADATA_LAST_EDITED_TIME => 1760000000,
			]),
			false,
			true,
			self::previousMetaDataReplyingTo('paul@local.test'),
		);
	}

	/**
	 * Only the first edit announces. The `silent` key never leaves the metadata, so without this
	 * the recipient would be pinged again on every later typo fix.
	 */
	public function testHandleEditedChatMessageDoesNotReAnnounceSilentMessageOnLaterEdit(): void {
		$room = $this->roomForEdit();
		$this->expectNotificationSubject(null);

		$this->notifier->handleEditedChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_MENTION),
			$this->editedMessage([
				Message::METADATA_SILENT => true,
				Message::METADATA_LAST_EDITED_TIME => 1760000000,
			]),
			false,
			false,
			self::previousMetaDataReplyingTo('paul@local.test'),
		);
	}

	/**
	 * At "@-mentions only", a silent message's first edit that neither mentions the recipient nor
	 * replies to them stays quiet.
	 */
	public function testHandleEditedChatMessageSilentFirstEditWithoutMentionOrReplyStaysQuiet(): void {
		$room = $this->roomForEdit();
		$this->expectNotificationSubject(null);

		$this->notifier->handleEditedChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_MENTION),
			$this->editedMessage([
				Message::METADATA_SILENT => true,
				Message::METADATA_LAST_EDITED_TIME => 1760000000,
			]),
			false,
			true,
			[],
		);
	}

	/**
	 * The self-check is against the EDITOR, not the author: a moderator can edit someone else's
	 * message, and the person who made the edit must not be notified about it.
	 */
	public function testHandleEditedChatMessageDoesNotNotifyTheEditor(): void {
		$room = $this->roomForEdit();
		$this->expectNotificationSubject(null);

		$this->notifier->handleEditedChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_ALWAYS),
			$this->editedMessage([
				Message::METADATA_LAST_EDITED_BY_TYPE => Attendee::ACTOR_USERS,
				Message::METADATA_LAST_EDITED_BY_ID => 'paul',
				Message::METADATA_LAST_EDITED_TIME => 1760000000,
			], json_encode([['type' => 'user', 'id' => 'paul']])),
			false,
			false,
			[],
		);
	}

	/** A connected client should not get a push. */
	public function testHandleEditedChatMessageSkipsActiveSession(): void {
		$room = $this->roomForEdit();
		$this->expectNotificationSubject(null);

		$attendee = Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'paul',
			'participant_type' => Participant::USER,
			'notification_level' => Participant::NOTIFY_ALWAYS,
		]);
		$session = new Session();
		$session->setState(Session::STATE_ACTIVE);

		$this->notifier->handleEditedChatMessage(
			$room,
			new Participant($room, $attendee, $session),
			$this->editedMessage([Message::METADATA_LAST_EDITED_TIME => 1760000000], json_encode([['type' => 'user', 'id' => 'paul']])),
			false,
			true,
			[],
		);
	}

	/** An edit of a system message is not something to notify about. */
	public function testHandleEditedChatMessageIgnoresSystemMessages(): void {
		$room = $this->roomForEdit();
		$this->expectNotificationSubject(null);

		$message = $this->editedMessage([Message::METADATA_LAST_EDITED_TIME => 1760000000], json_encode([['type' => 'user', 'id' => 'paul']]));
		$message->setSystemMessage('message_edited');

		$this->notifier->handleEditedChatMessage(
			$room,
			$this->participantWithLevel($room, Participant::NOTIFY_ALWAYS),
			$message,
			false,
			true,
			[],
		);
	}
}
