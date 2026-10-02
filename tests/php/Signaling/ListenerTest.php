<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Signaling;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Config;
use OCA\Talk\Events\ARoomModifiedEvent;
use OCA\Talk\Events\BeforeRoomDeletedEvent;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCA\Talk\Events\GuestsCleanedUpEvent;
use OCA\Talk\Events\LobbyModifiedEvent;
use OCA\Talk\Events\ReactionAddedEvent;
use OCA\Talk\Events\RoomModifiedEvent;
use OCA\Talk\Events\SystemMessageSentEvent;
use OCA\Talk\Events\SystemMessagesMultipleSentEvent;
use OCA\Talk\Federation\Attachments\FederatedFileReference;
use OCA\Talk\Manager;
use OCA\Talk\Model\Message;
use OCA\Talk\Model\Thread;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\PollService;
use OCA\Talk\Service\SessionService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\Signaling\BackendNotifier;
use OCA\Talk\Signaling\Listener;
use OCA\Talk\Signaling\Messages;
use OCA\Talk\Webinary;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

#[Group('DB')]
class ListenerTest extends TestCase {
	protected BackendNotifier&MockObject $backendNotifier;
	protected Manager&MockObject $manager;
	protected ParticipantService&MockObject $participantService;
	protected SessionService&MockObject $sessionService;
	protected ITimeFactory&MockObject $timeFactory;
	protected ?Listener $listener = null;
	protected MessageParser&MockObject $messageParser;
	protected ThreadService&MockObject $threadService;
	protected IFactory $l10nFactory;
	protected MockObject&PollService $pollService;

	public function setUp(): void {
		parent::setUp();

		$this->backendNotifier = $this->createMock(BackendNotifier::class);
		$this->manager = $this->createMock(Manager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->sessionService = $this->createMock(SessionService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->messageParser = $this->createMock(MessageParser::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->pollService = $this->createMock(PollService::class);

		$this->listener = new Listener(
			$this->createMock(Config::class),
			$this->createMock(Messages::class),
			$this->backendNotifier,
			$this->manager,
			$this->participantService,
			$this->sessionService,
			$this->timeFactory,
			$this->messageParser,
			$this->threadService,
			$this->l10nFactory,
			$this->pollService,
		);
	}

	public static function dataRoomModified(): array {
		return [
			[
				ARoomModifiedEvent::PROPERTY_NAME,
				'Test room',
				'name',
			],
			[
				ARoomModifiedEvent::PROPERTY_DESCRIPTION,
				'The description',
				'',
			],
			[
				ARoomModifiedEvent::PROPERTY_PASSWORD,
				'password',
				null,
			],
			[
				ARoomModifiedEvent::PROPERTY_TYPE,
				Room::TYPE_PUBLIC,
				Room::TYPE_GROUP,
			],
			[
				ARoomModifiedEvent::PROPERTY_READ_ONLY,
				Room::READ_ONLY,
				Room::READ_WRITE,
			],
			[
				ARoomModifiedEvent::PROPERTY_LISTABLE,
				Room::LISTABLE_ALL,
				Room::LISTABLE_NONE,
			],
		];
	}

	#[DataProvider('dataRoomModified')]
	public function testRoomModified(string $property, mixed $newValue, mixed $oldValue): void {
		$room = $this->createStub(Room::class);

		$event = new RoomModifiedEvent(
			$room,
			$property,
			$newValue,
			$oldValue,
			null,
		);

		$this->backendNotifier->expects($this->once())
			->method('roomModified')
			->with($room);
		$this->listener->handle($event);
	}

	public function testRecordingStatusChanged(): void {
		$room = $this->createMock(Room::class);
		$room->method('getCallRecording')
			->willReturn(Room::RECORDING_VIDEO);

		$event = new RoomModifiedEvent(
			$room,
			ARoomModifiedEvent::PROPERTY_CALL_RECORDING,
			Room::RECORDING_VIDEO,
			Room::RECORDING_NONE,
			null,
		);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'recording',
				'recording' => [
					'status' => Room::RECORDING_VIDEO,
				],
			]);
		$this->listener->handle($event);
	}

	public static function dataRoomLobbyModified(): array {
		return [
			[
				Webinary::LOBBY_NON_MODERATORS,
				Webinary::LOBBY_NONE,
				null,
				true,
			],
			[
				Webinary::LOBBY_NONE,
				Webinary::LOBBY_NON_MODERATORS,
				null,
				false,
			],
			[
				Webinary::LOBBY_NONE,
				Webinary::LOBBY_NON_MODERATORS,
				new \DateTime(),
				false,
			],
		];
	}

	#[DataProvider('dataRoomLobbyModified')]
	public function testRoomLobbyModified(int $newValue, int $oldValue, ?\DateTime $lobbyTimer, bool $timerReached): void {
		$room = $this->createStub(Room::class);

		$event = new LobbyModifiedEvent(
			$room,
			$newValue,
			$oldValue,
			$lobbyTimer,
			$timerReached,
		);

		$this->backendNotifier->expects($this->once())
			->method('roomModified')
			->with($room);
		$this->listener->handle($event);
	}

	public function testRoomLobbyRemoved(): void {
		$room = $this->createStub(Room::class);

		$event = new LobbyModifiedEvent(
			$room,
			Webinary::LOBBY_NONE,
			Webinary::LOBBY_NON_MODERATORS,
			null,
			true,
		);

		$this->backendNotifier->expects($this->once())
			->method('roomModified')
			->with($room);
		$this->listener->handle($event);
	}

	public function testRoomDelete(): void {
		$room = $this->createStub(Room::class);

		$event = new BeforeRoomDeletedEvent(
			$room
		);

		$this->backendNotifier->expects($this->once())
			->method('roomDeleted')
			->with($room);

		$this->listener->handle($event);
	}

	public function testGuestsCleanedUpEvent(): void {
		$room = $this->createStub(Room::class);

		$event = new GuestsCleanedUpEvent(
			$room
		);

		$this->backendNotifier->expects($this->once())
			->method('participantsModified')
			->with($room, []);

		$this->listener->handle($event);
	}

	public function testChatMessageInvisibleSentEvent(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$comment = $this->createMock(IComment::class);
		$comment->method('getTopmostParentId')->willReturn(null);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_MESSAGE);
		$comment->method('getId')->willReturn(1);

		$event = new ChatMessageSentEvent(
			$room,
			$comment,
		);

		$this->messageParser->expects($this->once())
			->method('createMessage');
		$this->messageParser->expects($this->once())
			->method('parseMessage');
		$this->l10nFactory->expects($this->once())
			->method('get')
			->willReturn($this->createMock(IL10N::class));

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}

	public function testChatMessageSentEvent(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$comment = $this->createMock(IComment::class);
		$comment->method('getTopmostParentId')->willReturn(null);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_MESSAGE);
		$comment->method('getId')->willReturn(1);
		$message = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => [
				'id' => 123,
			],
			'getMessageId' => 123,
		]);
		$l10n = $this->createStub(IL10N::class);

		$event = new ChatMessageSentEvent(
			$room,
			$comment,
		);

		$this->l10nFactory->expects($this->once())
			->method('get')
			->willReturn($l10n);
		$this->messageParser->expects($this->once())
			->method('createMessage')
			->with($room, null, $comment, $l10n)
			->willReturn($message);
		$this->messageParser->expects($this->once())
			->method('parseMessage')
			->with($message);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
					'comment' => [
						'id' => 123,
						'lastCommonRead' => 0,
					],
				],
			]);

		$this->listener->handle($event);
	}

	/**
	 * A relayed payload is one broadcast for every participant, so a federated file message can only
	 * carry the id-less `forDisplay()` reference, which no client can turn into a file: iOS finds no
	 * parameter of type `file` and renders the caption with no attachment at all, and because the
	 * client stores what it is given and never refetches a known message, that is permanent for
	 * whoever had the conversation open. Those recipients are sent to the API instead.
	 */
	public function testChatMessageWithAFederatedFileRelaysRefreshOnly(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$comment = $this->createMock(IComment::class);
		$comment->method('getTopmostParentId')->willReturn(null);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_MESSAGE);
		$comment->method('getId')->willReturn(1);
		$message = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => ['id' => 123],
			'getMessageId' => 123,
			'getMessageParameters' => [
				'actor' => ['type' => 'user', 'id' => 'paulp', 'name' => 'Paul Pardi'],
				'file' => FederatedFileReference::forDisplay(
					['name' => 'photo.jpg', 'size' => '168296', 'mimetype' => 'image/jpeg'],
					'https://nc1.test',
				),
			],
		]);
		$l10n = $this->createStub(IL10N::class);

		$event = new ChatMessageSentEvent($room, $comment);

		$this->l10nFactory->expects($this->once())->method('get')->willReturn($l10n);
		$this->messageParser->expects($this->once())->method('createMessage')
			->with($room, null, $comment, $l10n)->willReturn($message);
		$this->messageParser->expects($this->once())->method('parseMessage')->with($message);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}

	/**
	 * Replying to a federated file message relays the quoted parent as well, which carries the same
	 * unresolvable reference, so the quote is sent to the API too rather than relayed without its file.
	 */
	public function testReplyToAFederatedFileRelaysRefreshOnly(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$comment = $this->createMock(IComment::class);
		$comment->method('getTopmostParentId')->willReturn(null);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_MESSAGE);
		$comment->method('getId')->willReturn(2);
		$parentComment = $this->createMock(IComment::class);
		$parentComment->method('getVerb')->willReturn(ChatManager::VERB_MESSAGE);
		$parentComment->method('getId')->willReturn(1);
		$parentComment->method('getParentId')->willReturn('0');

		$reply = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => ['id' => 124],
			'getMessageId' => 124,
			'getMessageParameters' => [],
		]);
		$quotedFileMessage = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => ['id' => 123],
			'getMessageId' => 123,
			'getMessageParameters' => [
				'file' => FederatedFileReference::forDisplay(['name' => 'photo.jpg'], 'https://nc1.test'),
			],
		]);
		$l10n = $this->createStub(IL10N::class);

		$event = new ChatMessageSentEvent($room, $comment, null, false, $parentComment);

		$this->l10nFactory->method('get')->willReturn($l10n);
		$this->messageParser->method('createMessage')
			->willReturnCallback(static fn (Room $r, $p, IComment $c, $l): Message
				=> $c->getId() === 1 ? $quotedFileMessage : $reply);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}

	/**
	 * Reacting to a message relays the reacted-to message as the reaction's `parent`, rendered with
	 * the same null participant. For a federated file that parent carries the unresolvable id-less
	 * reference, so a client that already held a good copy of the message overwrites it and the
	 * attachment degrades to a bare file name until something forces a refetch.
	 */
	public function testReactionToAFederatedFileRelaysRefreshOnly(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$room->method('getToken')->willReturn('tok');
		$reactedTo = $this->createMock(IComment::class);
		$reactedTo->method('getTopmostParentId')->willReturn(null);
		$reactedTo->method('getId')->willReturn(123);
		$reactedTo->method('getReactions')->willReturn([]);

		$fileMessage = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => ['id' => 123],
			'getMessageId' => 123,
			'getMessageParameters' => [
				'file' => FederatedFileReference::forDisplay(['name' => 'photo.jpg'], 'https://nc1.test'),
			],
		]);
		$l10n = $this->createStub(IL10N::class);

		$event = new ReactionAddedEvent($room, $reactedTo, 'users', 'paulp', 'Paul Pardi', '👍');

		$this->l10nFactory->method('get')->willReturn($l10n);
		$this->messageParser->method('createMessage')->willReturn($fileMessage);
		$this->messageParser->expects($this->once())->method('parseMessage')->with($fileMessage);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}

	public function testChatMessageSentWithThreadEvent(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$comment = $this->createMock(IComment::class);
		$comment->method('getTopmostParentId')->willReturn(null);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_MESSAGE);
		$comment->method('getId')->willReturn(1);
		$message = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => [
				'id' => 123,
			],
			'getMessageId' => 123,
		]);

		$l10n = $this->createStub(IL10N::class);
		$thread = $this->createStub(Thread::class);

		$event = new ChatMessageSentEvent(
			$room,
			$comment,
		);

		$this->l10nFactory->expects($this->once())
			->method('get')
			->willReturn($l10n);
		$this->messageParser->expects($this->once())
			->method('createMessage')
			->with($room, null, $comment, $l10n)
			->willReturn($message);
		$this->messageParser->expects($this->once())
			->method('parseMessage')
			->with($message);
		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->willReturn($thread);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
					'comment' => [
						'id' => 123,
						'lastCommonRead' => 0,
					],
				],
			]);

		$this->listener->handle($event);
	}

	public function testSystemMessageSentEvent(): void {
		$room = $this->createStub(Room::class);
		$comment = $this->createMock(IComment::class);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_SYSTEM);
		$comment->method('getMessage')->willReturn(json_encode(['message' => 'test']));

		$event = new SystemMessageSentEvent(
			$room,
			$comment,
			skipLastActivityUpdate: false
		);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}

	/**
	 * A system message about a federated file message — an edit or a delete, say — relays that
	 * message as its parent, rendered without a viewer, so it carries the same unresolvable
	 * reference and would overwrite a client's good copy.
	 */
	public function testSystemMessageWhoseParentIsAFederatedFileRelaysRefreshOnly(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$comment = $this->createMock(IComment::class);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_SYSTEM);
		$comment->method('getMessage')->willReturn(json_encode(['message' => 'message_edited']));
		$comment->method('getId')->willReturn(124);
		$comment->method('getTopmostParentId')->willReturn(null);
		$parentComment = $this->createMock(IComment::class);
		$parentComment->method('getId')->willReturn(123);

		$systemMessage = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => ['id' => 124],
			'getMessageId' => 124,
			'getMessageType' => 'system',
			'getMessageRaw' => 'message_edited',
			'getMessageParameters' => [],
		]);
		$quotedFileMessage = $this->createConfiguredMock(Message::class, [
			'getVisibility' => true,
			'toArray' => ['id' => 123],
			'getMessageId' => 123,
			'getMessageParameters' => [
				'file' => FederatedFileReference::forDisplay(['name' => 'photo.jpg'], 'https://nc1.test'),
			],
		]);
		$l10n = $this->createStub(IL10N::class);

		$event = new SystemMessageSentEvent($room, $comment, parent: $parentComment, skipLastActivityUpdate: false);

		$this->l10nFactory->method('get')->willReturn($l10n);
		$this->messageParser->method('createMessage')
			->willReturnCallback(static fn (Room $r, $p, IComment $c, $l): Message
				=> $c->getId() === 123 ? $quotedFileMessage : $systemMessage);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}

	public function testSystemMessageSentEventSkippingUpdate(): void {
		$room = $this->createStub(Room::class);
		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn(json_encode(['message' => 'test']));

		$event = new SystemMessageSentEvent(
			$room,
			$comment,
			skipLastActivityUpdate: true
		);

		$this->backendNotifier->expects($this->never())
			->method('sendRoomMessage');

		$this->listener->handle($event);
	}

	public function testSystemMessagesMultipleSentEvent(): void {
		$room = $this->createStub(Room::class);
		$comment = $this->createMock(IComment::class);
		$comment->method('getVerb')->willReturn(ChatManager::VERB_SYSTEM);
		$comment->method('getMessage')->willReturn(json_encode(['message' => 'test']));

		$event = new SystemMessagesMultipleSentEvent(
			$room,
			$comment,
		);

		$this->backendNotifier->expects($this->once())
			->method('sendRoomMessage')
			->with($room, [
				'type' => 'chat',
				'chat' => [
					'refresh' => true,
				],
			]);

		$this->listener->handle($event);
	}
}
