<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Search;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\CannotReachRemoteException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Federation\Proxy\TalkV1\Controller\ChatController as ProxyChatController;
use OCA\Talk\Manager as RoomManager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Message;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Search\CurrentMessageSearch;
use OCA\Talk\Search\MessageSearch;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class MessageSearchTest extends TestCase {
	protected RoomManager&MockObject $roomManager;
	protected ParticipantService&MockObject $participantService;
	protected ChatManager&MockObject $chatManager;
	protected MessageParser&MockObject $messageParser;
	protected ITimeFactory&MockObject $timeFactory;
	protected IURLGenerator&MockObject $url;
	protected IL10N&MockObject $l;
	protected Config&MockObject $talkConfig;
	protected IUserSession&MockObject $userSession;
	protected ThreadService&MockObject $threadService;
	protected ProxyChatController&MockObject $proxyChatController;
	protected MessageSearch $search;
	private IUser&MockObject $user;
	private Participant&MockObject $participant;

	public function setUp(): void {
		parent::setUp();

		$this->roomManager = $this->createMock(RoomManager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->chatManager = $this->createMock(ChatManager::class);
		$this->messageParser = $this->createMock(MessageParser::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$link = static fn (string $route, array $parameters = []): string => $route . '?' . http_build_query($parameters);
		$this->url->method('linkToRouteAbsolute')->willReturnCallback($link);
		$this->url->method('linkToOCSRouteAbsolute')->willReturnCallback($link);
		$this->l = $this->createMock(IL10N::class);
		// IL10N::t() takes its parameters as an array or a single string (e.g. t('%s (guest)', $name))
		$this->l->method('t')->willReturnCallback(static fn (string $text, string|array $parameters = []): string => vsprintf($text, (array)$parameters));
		$this->talkConfig = $this->createMock(Config::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->proxyChatController = $this->createMock(ProxyChatController::class);

		$this->search = new MessageSearch(...$this->constructorArgs());

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('bill');
		// Bill and Carol are users of this server in the conversation hosted on nc1
		$this->participant = $this->participant('bill', 'bill@nc2.test');
		$carol = $this->participant('carol', 'carol@nc2.test');
		// Erin is a participant, but was never invited with a cloud id (shouldn't normally happen for a federated
		// conversation, but the "From" filter must stay defensive about it)
		$erin = $this->participant('erin', '');
		$this->participantService->method('getParticipant')->willReturnCallback(
			fn (Room $room, string $userId): Participant => match ($userId) {
				'bill' => $this->participant,
				'carol' => $carol,
				'erin' => $erin,
				default => throw new ParticipantNotFoundException(),
			}
		);
	}

	private function participant(string $userId, string $cloudId, bool $sensitive = false): Participant&MockObject {
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn(Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => $userId,
			'invited_cloud_id' => $cloudId,
			'sensitive' => $sensitive,
		]));
		return $participant;
	}

	private function room(bool $federated, string $token = 'localtok'): Room&MockObject {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(7);
		$room->method('getToken')->willReturn($token);
		$room->method('getType')->willReturn(Room::TYPE_GROUP);
		$room->method('getDisplayName')->willReturn('Paul and Bill');
		$room->method('isFederatedConversation')->willReturn($federated);
		$this->roomManager->method('getRoomForUserByToken')->with($token, 'bill')->willReturn($room);
		return $room;
	}

	/**
	 * The collaborators of the provider under test, in constructor order
	 */
	private function constructorArgs(): array {
		return [
			$this->roomManager,
			$this->participantService,
			$this->chatManager,
			$this->messageParser,
			$this->timeFactory,
			$this->url,
			$this->l,
			$this->talkConfig,
			$this->userSession,
			$this->threadService,
			$this->proxyChatController,
		];
	}

	/**
	 * @param class-string<MessageSearch> $class
	 */
	private function provider(string $class): MessageSearch {
		return new $class(...$this->constructorArgs());
	}

	/**
	 * A search for "essa" (limit 20) from the conversation page of "localtok", unless another route or limit is given
	 */
	private function query(array $filters = [], ?int $cursor = null, string $route = 'spreed.Page.showCall', int $limit = 20): ISearchQuery&MockObject {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getRoute')->willReturn($route);
		$query->method('getRouteParameters')->willReturn(['token' => 'localtok']);
		$query->method('getTerm')->willReturn('essa');
		$query->method('getCursor')->willReturn($cursor);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getFilter')->willReturnCallback(function (string $name) use ($filters): ?IFilter {
			if (!array_key_exists($name, $filters)) {
				return null;
			}
			$filter = $this->createMock(IFilter::class);
			$filter->method('get')->willReturn($filters[$name]);
			return $filter;
		});
		return $query;
	}

	private static function serialize(SearchResult $result): array {
		return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
	}

	public static function dataCutMessageToSearchResult(): array {
		return [
			'short message is kept' => [
				'Hello there', 'there', false, 'Hello there',
			],
			'match at the beginning of a long message is kept' => [
				'Hello there, this is a long message that is not cut off at all', 'Hello', false,
				'Hello there, this is a long message that is not cut off at all',
			],
			'match far in the message cuts the beginning' => [
				'This is a really long message with the needle somewhere at the end', 'needle', false,
				'… with the needle somewhere at the end',
			],
			'sensitive cuts before and after' => [
				'This is a really long message with the needle somewhere at the end', 'needle', true,
				'… with the needle somewhere…',
			],
			'sensitive keeps the beginning when the match is early' => [
				'The needle is somewhere at the beginning', 'needle', true,
				'The needle is somewh…',
			],
			'sensitive without trailing content' => [
				'Nothing but the needle', 'needle', true,
				'…g but the needle',
			],
			'sensitive is case insensitive' => [
				'This is a really long message with the NEEDLE somewhere at the end', 'needle', true,
				'… with the NEEDLE somewhere…',
			],
			'sensitive without a match only shows the beginning' => [
				'This is a really long message without the search term', 'unmatched', true,
				'This is a …',
			],
			'sensitive with multibyte characters' => [
				'äöüäöüäöüäöüäöüäöü nädle äöüäöüäöüäöüäöüäöü', 'nädle', true,
				'…äöüäöüäöü nädle äöüäöüäöü…',
			],
		];
	}

	#[DataProvider('dataCutMessageToSearchResult')]
	public function testCutMessageToSearchResult(string $messageStr, string $term, bool $isSensitive, string $expected): void {
		$this->assertSame($expected, self::invokePrivate($this->search, 'cutMessageToSearchResult', [$messageStr, $term, $isSensitive]));
	}

	public function testSearchInAFederatedConversationIsAnsweredByTheHost(): void {
		$room = $this->room(true);
		$this->proxyChatController->expects($this->once())
			->method('searchMessages')
			->with($room, $this->participant, 'essa', 1700000000, 0, '', '', 10, 20)
			->willReturn([
				[
					'id' => 12,
					'actorType' => Attendee::ACTOR_FEDERATED_USERS,
					'actorId' => 'paul@nc1.test',
					'actorDisplayName' => 'Paul',
					'timestamp' => 1700000100,
					'message' => 'Message for {mention-user1}',
					'messageParameters' => ['mention-user1' => ['type' => 'user', 'id' => 'bill', 'name' => 'Bill']],
				],
				[
					'id' => 11,
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => 'bill',
					'actorDisplayName' => 'Bill',
					'timestamp' => 1700000000,
					'message' => 'Message 1',
					'messageParameters' => [],
				],
				[
					'id' => 10,
					'actorType' => Attendee::ACTOR_GUESTS,
					'actorId' => 'abc123',
					'actorDisplayName' => 'Anna',
					'timestamp' => 1699999900,
					'message' => 'Message from a guest',
					'messageParameters' => [],
				],
			]);
		$this->chatManager->expects($this->never())->method('searchForObjectsWithFilters');

		$result = $this->provider(CurrentMessageSearch::class)->search($this->user, $this->query(['since' => new \DateTimeImmutable('@1700000000')], 10));

		$this->assertSame([
			'name' => 'Messages',
			'isPaginated' => true,
			'entries' => [
				[
					'thumbnailUrl' => 'spreed.Avatar.getUserProxyAvatar?apiVersion=v1&token=localtok&size=512&cloudId=paul%40nc1.test',
					'title' => 'Paul',
					'subline' => 'Message for @Bill',
					'resourceUrl' => 'spreed.Page.showCall?token=localtok&_fragment=message_12',
					'icon' => 'icon-talk',
					'rounded' => true,
					'attributes' => ['conversation' => 'localtok', 'messageId' => '12', 'actorType' => 'federated_users', 'actorId' => 'paul@nc1.test', 'timestamp' => '1700000100'],
				],
				[
					'thumbnailUrl' => 'core.avatar.getAvatar?userId=bill&size=512',
					'title' => 'Bill',
					'subline' => 'Message 1',
					'resourceUrl' => 'spreed.Page.showCall?token=localtok&_fragment=message_11',
					'icon' => 'icon-talk',
					'rounded' => true,
					'attributes' => ['conversation' => 'localtok', 'messageId' => '11', 'actorType' => 'users', 'actorId' => 'bill', 'timestamp' => '1700000000'],
				],
				[
					// Guests get no avatar and are marked as guests, as in local results
					'thumbnailUrl' => '',
					'title' => 'Anna (guest)',
					'subline' => 'Message from a guest',
					'resourceUrl' => 'spreed.Page.showCall?token=localtok&_fragment=message_10',
					'icon' => 'icon-talk',
					'rounded' => true,
					'attributes' => ['conversation' => 'localtok', 'messageId' => '10', 'actorType' => 'guests', 'actorId' => 'abc123', 'timestamp' => '1699999900'],
				],
			],
			'cursor' => 30,
		], self::serialize($result));
	}

	public function testLimitAboveTheHostMaximumIsClampedForRequestAndCursor(): void {
		$room = $this->room(true);
		// The host never returns more than 100 messages, so the next page must start 100 further, not 250
		$this->proxyChatController->expects($this->once())
			->method('searchMessages')
			->with($room, $this->participant, 'essa', 0, 0, '', '', 0, MessageSearch::FEDERATED_SEARCH_MAX_LIMIT)
			->willReturn([]);

		$result = $this->provider(CurrentMessageSearch::class)->search($this->user, $this->query([], null, 'spreed.Page.showCall', 250));

		$this->assertSame(100, self::serialize($result)['cursor']);
	}

	public static function dataFromFilter(): array {
		return [
			'the searching user' => ['bill', 'bill@nc2.test'],
			'another user of this server' => ['carol', 'carol@nc2.test'],
		];
	}

	#[DataProvider('dataFromFilter')]
	public function testFromFilterUsesTheCloudIdTheHostKnows(string $userId, string $cloudId): void {
		$room = $this->room(true);
		$person = $this->createMock(IUser::class);
		$person->method('getUID')->willReturn($userId);
		$this->proxyChatController->expects($this->once())
			->method('searchMessages')
			->with($room, $this->participant, 'essa', 0, 0, Attendee::ACTOR_FEDERATED_USERS, $cloudId, 0, 20)
			->willReturn([]);

		$this->provider(CurrentMessageSearch::class)->search($this->user, $this->query(['person' => $person]));
	}

	public function testFromFilterWithSomeoneOutsideTheConversationFindsNothing(): void {
		$this->room(true);
		$person = $this->createMock(IUser::class);
		$person->method('getUID')->willReturn('dave');
		$this->proxyChatController->expects($this->never())->method('searchMessages');

		$result = $this->provider(CurrentMessageSearch::class)->search($this->user, $this->query(['person' => $person]));

		$this->assertSame([], self::serialize($result)['entries']);
	}

	public function testFromFilterWithAnEmptyCloudIdFindsNothing(): void {
		$this->room(true);
		$person = $this->createMock(IUser::class);
		$person->method('getUID')->willReturn('erin');
		$this->proxyChatController->expects($this->never())->method('searchMessages');

		$result = $this->provider(CurrentMessageSearch::class)->search($this->user, $this->query(['person' => $person]));

		$this->assertSame([], self::serialize($result)['entries']);
	}

	public function testUnreachableHostFindsNothing(): void {
		$this->room(true);
		$this->proxyChatController->method('searchMessages')->willThrowException(new CannotReachRemoteException());

		$result = $this->provider(CurrentMessageSearch::class)->search($this->user, $this->query());

		$this->assertSame([], self::serialize($result)['entries']);
	}

	public function testConversationFilterOnAFederatedConversationIsAnsweredByTheHost(): void {
		$room = $this->room(true, 'fedtok');
		$this->proxyChatController->expects($this->once())
			->method('searchMessages')
			->with($room, $this->participant, 'essa', 0, 0, '', '', 0, 20)
			->willReturn([]);
		$this->chatManager->expects($this->never())->method('searchForObjectsWithFilters');

		// Nextcloud's search outside Talk, with the conversation chip set to the federated conversation
		$result = $this->provider(MessageSearch::class)->search($this->user, $this->query(['conversation' => 'fedtok'], null, 'files.View.index'));

		$this->assertSame('Messages', self::serialize($result)['name']);
	}

	public function testConversationFilterOnAFederatedConversationWhenNotAParticipantFindsNothing(): void {
		// Not built with the room() helper: that ties getRoomForUserByToken() to "bill", which would conflict here
		$room = $this->createMock(Room::class);
		$room->method('isFederatedConversation')->willReturn(true);
		$dave = $this->createMock(IUser::class);
		$dave->method('getUID')->willReturn('dave');
		$this->roomManager->method('getRoomForUserByToken')->with('fedtok', 'dave')->willReturn($room);
		$this->proxyChatController->expects($this->never())->method('searchMessages');

		// Nextcloud's search outside Talk, with the conversation chip set to a federated conversation Dave isn't part of
		$result = $this->provider(MessageSearch::class)->search($dave, $this->query(['conversation' => 'fedtok'], null, 'files.View.index'));

		$this->assertSame([], self::serialize($result)['entries']);
	}

	public function testConversationOfThisServerIsSearchedHere(): void {
		$this->room(false);
		$this->chatManager->expects($this->once())
			->method('searchForObjectsWithFilters')
			->with('essa', [7], [ChatManager::VERB_MESSAGE, ChatManager::VERB_OBJECT_SHARED], null, null, null, null, 0, 20)
			->willReturn([]);
		$this->proxyChatController->expects($this->never())->method('searchMessages');

		$this->provider(CurrentMessageSearch::class)->search($this->user, $this->query());
	}

	public function testLocalResultPassesTheConversationSensitivityThrough(): void {
		$this->participant = $this->participant('bill', '', true);
		// Not federated, so the search runs here and the entry is built by commentToSearchResultEntry()
		$this->room(false);
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('5');
		$comment->method('getObjectId')->willReturn('7');
		$comment->method('getTopmostParentId')->willReturn('0');
		$comment->method('getActorType')->willReturn(Attendee::ACTOR_USERS);
		$comment->method('getActorId')->willReturn('paul');
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('@1700000000'));
		$this->chatManager->method('searchForObjectsWithFilters')->willReturn([$comment]);
		$message = $this->createMock(Message::class);
		$message->method('getComment')->willReturn($comment);
		$message->method('getVisibility')->willReturn(true);
		$message->method('getMessage')->willReturn('Take a look at this very long message where the essa match sits far from the start');
		$message->method('getMessageParameters')->willReturn([]);
		$message->method('getActorType')->willReturn(Attendee::ACTOR_USERS);
		$message->method('getActorDisplayName')->willReturn('Paul');
		$this->messageParser->method('createMessage')->willReturn($message);

		// Only that the flag reaches upstream's method: its clamped output is pinned by dataCutMessageToSearchResult
		$provider = $this->getMockBuilder(CurrentMessageSearch::class)
			->setConstructorArgs($this->constructorArgs())
			->onlyMethods(['cutMessageToSearchResult'])
			->getMock();
		$provider->expects($this->once())
			->method('cutMessageToSearchResult')
			->with($this->anything(), 'essa', true)
			->willReturn('… the essa match …');

		self::serialize($provider->search($this->user, $this->query()));
	}

	public function testFederatedResultPassesTheConversationSensitivityThrough(): void {
		$this->participant = $this->participant('bill', 'bill@nc2.test', true);
		$this->room(true);
		$this->proxyChatController->method('searchMessages')->willReturn([
			[
				'id' => 23,
				'message' => 'Take a look at this very long message where the essa match sits far from the start',
				'messageParameters' => [],
				'timestamp' => 1700000000,
				'actorType' => Attendee::ACTOR_USERS,
				'actorId' => 'paul',
				'actorDisplayName' => 'Paul',
			],
		]);

		// Only that the flag reaches upstream's method: its clamped output is pinned by dataCutMessageToSearchResult
		$provider = $this->getMockBuilder(CurrentMessageSearch::class)
			->setConstructorArgs($this->constructorArgs())
			->onlyMethods(['cutMessageToSearchResult'])
			->getMock();
		$provider->expects($this->once())
			->method('cutMessageToSearchResult')
			->with($this->anything(), 'essa', true)
			->willReturn('… the essa match …');

		self::serialize($provider->search($this->user, $this->query()));
	}
}
