<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
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
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Search\CurrentMessageSearch;
use OCA\Talk\Search\MessageSearch;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
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
	private RoomManager&MockObject $roomManager;
	private ParticipantService&MockObject $participantService;
	private ChatManager&MockObject $chatManager;
	private IURLGenerator&MockObject $url;
	private IL10N&MockObject $l;
	private ProxyChatController&MockObject $proxyChatController;
	private IUser&MockObject $user;
	private Participant&MockObject $participant;

	public function setUp(): void {
		parent::setUp();
		$this->roomManager = $this->createMock(RoomManager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->chatManager = $this->createMock(ChatManager::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$link = static fn (string $route, array $parameters = []): string => $route . '?' . http_build_query($parameters);
		$this->url->method('linkToRouteAbsolute')->willReturnCallback($link);
		$this->url->method('linkToOCSRouteAbsolute')->willReturnCallback($link);
		$this->l = $this->createMock(IL10N::class);
		// IL10N::t() takes its parameters as an array or a single string (e.g. t('%s (guest)', $name))
		$this->l->method('t')->willReturnCallback(static fn (string $text, string|array $parameters = []): string => vsprintf($text, (array)$parameters));
		$this->proxyChatController = $this->createMock(ProxyChatController::class);

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

	private function participant(string $userId, string $cloudId): Participant&MockObject {
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn(Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => $userId,
			'invited_cloud_id' => $cloudId,
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
	 * @param class-string<MessageSearch> $class
	 */
	private function provider(string $class): MessageSearch {
		return new $class(
			$this->roomManager,
			$this->participantService,
			$this->chatManager,
			$this->createMock(MessageParser::class),
			$this->createMock(ITimeFactory::class),
			$this->url,
			$this->l,
			$this->createMock(Config::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ThreadService::class),
			$this->proxyChatController,
		);
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
}
