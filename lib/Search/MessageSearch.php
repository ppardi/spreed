<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Search;

use OCA\Talk\AppInfo\Application;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\CannotReachRemoteException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Exceptions\UnauthorizedException;
use OCA\Talk\Federation\Proxy\TalkV1\Controller\ChatController as ProxyChatController;
use OCA\Talk\Manager as RoomManager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\Webinary;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilter;
use OCP\Search\IFilteringProvider;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * @psalm-import-type TalkChatMessage from ResponseDefinitions
 * @psalm-import-type TalkRichObjectParameter from ResponseDefinitions
 */
class MessageSearch implements IProvider, IFilteringProvider {

	public const CONVERSATION_FILTER = 'conversation';

	/** Most messages a host returns per search request (the host clamps to the same value) */
	public const FEDERATED_SEARCH_MAX_LIMIT = 100;

	protected bool $isConversationFiltered = false;

	public function __construct(
		protected readonly RoomManager $roomManager,
		protected readonly ParticipantService $participantService,
		protected readonly ChatManager $chatManager,
		protected readonly MessageParser $messageParser,
		protected readonly ITimeFactory $timeFactory,
		protected readonly IURLGenerator $url,
		protected readonly IL10N $l,
		protected readonly Config $talkConfig,
		protected readonly IUserSession $userSession,
		protected readonly ThreadService $threadService,
		protected readonly ProxyChatController $proxyChatController,
	) {
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getId(): string {
		return 'talk-message';
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getName(): string {
		return $this->l->t('Messages');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getOrder(string $route, array $routeParameters): ?int {
		$currentUser = $this->userSession->getUser();
		if ($currentUser && $this->talkConfig->isDisabledForUser($currentUser)) {
			return null;
		}

		if (str_starts_with($route, Application::APP_ID . '.')) {
			// Active app, prefer Talk results
			return -2;
		}

		return 15;
	}

	protected function getCurrentConversationToken(ISearchQuery $query): string {
		if ($query->getRoute() === 'spreed.Page.showCall') {
			return $query->getRouteParameters()['token'];
		}
		return '';
	}

	protected function getSublineTemplate(): string {
		if ($this->isConversationFiltered) {
			return $this->l->t('{user}');
		}
		return $this->l->t('{user} in {conversation}');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$title = $this->l->t('Messages');
		$currentToken = $this->getCurrentConversationToken($query);
		if ($currentToken !== '') {
			$title = $this->l->t('Messages in other conversations');
		}

		$filter = $query->getFilter(self::CONVERSATION_FILTER);
		if ($filter && $filter->get() !== $currentToken) {
			$this->isConversationFiltered = true;
			$title = $this->l->t('Messages');

			try {
				$room = $this->roomManager->getRoomForUserByToken(
					$filter->get(),
					$user->getUID()
				);
			} catch (RoomNotFoundException) {
				return SearchResult::complete($title, []);
			}

			if ($room->isFederatedConversation()) {
				try {
					$participant = $this->participantService->getParticipant($room, $user->getUID(), false);
				} catch (ParticipantNotFoundException) {
					return SearchResult::complete($title, []);
				}
				return $this->performFederatedSearch($user, $query, $title, $room, $participant);
			}
			$rooms = [$room];
		} elseif ($filter) {
			// The filter is the "Current conversation" so the CurrentMessageSearch will handle it
			return SearchResult::complete($title, []);
		} else {
			$rooms = $this->roomManager->getRoomsForUser($user->getUID());
		}

		return $this->performSearch($user, $query, $title, $rooms, $this->isConversationFiltered);
	}

	/**
	 * @param Room[] $rooms
	 */
	public function performSearch(IUser $user, ISearchQuery $query, string $title, array $rooms, bool $isCurrentMessageSearch = false): SearchResult {
		$roomMap = [];
		foreach ($rooms as $room) {
			if (!$isCurrentMessageSearch
				&& $room->getType() === Room::TYPE_CHANGELOG) {
				continue;
			}

			if (!$isCurrentMessageSearch
				&& $this->getCurrentConversationToken($query) === $room->getToken()) {
				// No search result from current conversation
				continue;
			}

			if ($room->getLobbyState() !== Webinary::LOBBY_NONE) {
				$participant = $this->participantService->getParticipant($room, $user->getUID(), false);
				if (!($participant->getPermissions() & Attendee::PERMISSIONS_LOBBY_IGNORE)) {
					continue;
				}
			}

			if ($room->isFederatedConversation()) {
				continue;
			}

			$roomMap[(string)$room->getId()] = $room;
		}

		if (empty($roomMap)) {
			return SearchResult::complete($title, []);
		}

		// Apply filters when available
		$lowerTimeBoundary = $upperTimeBoundary = $actorType = $actorId = null;
		if ($since = $query->getFilter(IFilter::BUILTIN_SINCE)?->get()) {
			if ($since instanceof \DateTimeImmutable) {
				$lowerTimeBoundary = $since;
			}
		}

		if ($until = $query->getFilter(IFilter::BUILTIN_UNTIL)?->get()) {
			if ($until instanceof \DateTimeImmutable) {
				$upperTimeBoundary = $until;
			}
		}

		if ($person = $query->getFilter(IFilter::BUILTIN_PERSON)?->get()) {
			if ($person instanceof IUser) {
				$actorType = Attendee::ACTOR_USERS;
				$actorId = $person->getUID();
			}
		}

		$offset = (int)$query->getCursor();
		$comments = $this->chatManager->searchForObjectsWithFilters(
			$query->getTerm(),
			array_keys($roomMap),
			[ChatManager::VERB_MESSAGE, ChatManager::VERB_OBJECT_SHARED],
			$lowerTimeBoundary,
			$upperTimeBoundary,
			$actorType,
			$actorId,
			$offset,
			$query->getLimit()
		);

		$result = [];
		foreach ($comments as $comment) {
			$room = $roomMap[$comment->getObjectId()];
			try {
				$result[] = $this->commentToSearchResultEntry($room, $user, $comment, $query);
			} catch (UnauthorizedException|ParticipantNotFoundException) {
			}
		}

		return SearchResult::paginated(
			$title,
			$result,
			$offset + $query->getLimit()
		);
	}

	/**
	 * @throws ParticipantNotFoundException
	 * @throws UnauthorizedException
	 */
	protected function commentToSearchResultEntry(Room $room, IUser $user, IComment $comment, ISearchQuery $query): SearchResultEntry {
		$participant = $this->participantService->getParticipant($room, $user->getUID(), false);

		$message = $this->messageParser->createMessage($room, $participant, $comment, $this->l);
		$this->messageParser->parseMessage($message);

		$messageStr = $this->formatMessageText($message->getMessage(), $message->getMessageParameters(), $query->getTerm(), $participant->getAttendee()->isSensitive());

		$now = $this->timeFactory->getDateTime();
		$expireDate = $message->getComment()->getExpireDate();
		if ($expireDate instanceof \DateTime && $expireDate < $now) {
			throw new UnauthorizedException('Expired');
		}

		if (!$message->getVisibility()) {
			throw new UnauthorizedException('Not visible');
		}

		$iconUrl = '';
		if ($message->getActorType() === Attendee::ACTOR_USERS) {
			$iconUrl = $this->url->linkToRouteAbsolute('core.avatar.getAvatar', [
				'userId' => $message->getActorId(),
				'size' => 512,
			]);
		}

		$threadId = (int)$comment->getTopmostParentId() ?: (int)$comment->getId();
		try {
			$thread = $this->threadService->findByThreadId($room->getId(), $threadId);
		} catch (DoesNotExistException) {
			$thread = null;
		}

		return $this->createSearchResultEntry(
			$room,
			$user,
			$iconUrl,
			$this->formatActorDisplayName($message->getActorType(), $message->getActorDisplayName()),
			$messageStr,
			$comment->getId(),
			$thread?->getId(),
			$comment->getActorType(),
			$comment->getActorId(),
			$comment->getCreationDateTime()->getTimestamp(),
		);
	}

	/**
	 * Searches a conversation hosted on another server: the host searches, this server shows its answer
	 */
	protected function performFederatedSearch(IUser $user, ISearchQuery $query, string $title, Room $room, Participant $participant): SearchResult {
		$since = $query->getFilter(IFilter::BUILTIN_SINCE)?->get();
		$until = $query->getFilter(IFilter::BUILTIN_UNTIL)?->get();

		$actorType = $actorId = '';
		$person = $query->getFilter(IFilter::BUILTIN_PERSON)?->get();
		if ($person instanceof IUser) {
			// The host knows the users of this server by the cloud id they were invited with
			try {
				$personParticipant = $person->getUID() === $user->getUID()
					? $participant
					: $this->participantService->getParticipant($room, $person->getUID(), false);
			} catch (ParticipantNotFoundException) {
				return SearchResult::complete($title, []);
			}
			$actorType = Attendee::ACTOR_FEDERATED_USERS;
			$actorId = $personParticipant->getAttendee()->getInvitedCloudId();
			if ($actorId === '') {
				return SearchResult::complete($title, []);
			}
		}

		$offset = (int)$query->getCursor();
		// The same limit for the request and the next cursor, so no results are skipped when the host returns fewer
		$limit = min(self::FEDERATED_SEARCH_MAX_LIMIT, $query->getLimit());
		try {
			$messages = $this->proxyChatController->searchMessages(
				$room,
				$participant,
				$query->getTerm(),
				$since instanceof \DateTimeImmutable ? $since->getTimestamp() : 0,
				$until instanceof \DateTimeImmutable ? $until->getTimestamp() : 0,
				$actorType,
				$actorId,
				$offset,
				$limit,
			);
		} catch (CannotReachRemoteException) {
			return SearchResult::complete($title, []);
		}

		// Resolved once here: federatedMessageToSearchResultEntry() has no Participant in scope,
		// and resolving it per message would query the participant for every result row
		$isSensitive = $participant->getAttendee()->isSensitive();

		$result = array_map(
			fn (array $message): SearchResultEntry => $this->federatedMessageToSearchResultEntry($room, $user, $message, $query, $isSensitive),
			$messages,
		);

		return SearchResult::paginated(
			$title,
			$result,
			$offset + $limit
		);
	}

	/**
	 * @param TalkChatMessage $message A message of the host, with actors as this server knows them
	 */
	protected function federatedMessageToSearchResultEntry(Room $room, IUser $user, array $message, ISearchQuery $query, bool $isSensitive): SearchResultEntry {
		$iconUrl = match ($message['actorType']) {
			Attendee::ACTOR_USERS => $this->url->linkToRouteAbsolute('core.avatar.getAvatar', [
				'userId' => $message['actorId'],
				'size' => 512,
			]),
			Attendee::ACTOR_FEDERATED_USERS => $this->url->linkToOCSRouteAbsolute('spreed.Avatar.getUserProxyAvatar', [
				'apiVersion' => 'v1',
				'token' => $room->getToken(),
				'size' => 512,
				'cloudId' => $message['actorId'],
			]),
			default => '',
		};

		// Threads are not available in federated conversations, so results open the message in the main chat
		return $this->createSearchResultEntry(
			$room,
			$user,
			$iconUrl,
			$this->formatActorDisplayName($message['actorType'], $message['actorDisplayName']),
			$this->formatMessageText($message['message'], $message['messageParameters'], $query->getTerm(), $isSensitive),
			(string)$message['id'],
			null,
			$message['actorType'],
			$message['actorId'],
			$message['timestamp'],
		);
	}

	/**
	 * The message with its parameters written out, cut down to the part surrounding the search result
	 *
	 * @param array<string, TalkRichObjectParameter> $messageParameters
	 */
	protected function formatMessageText(string $message, array $messageParameters, string $term, bool $isSensitive): string {
		$search = $replace = [];
		foreach ($messageParameters as $key => $parameter) {
			$search[] = '{' . $key . '}';
			if ($parameter['type'] === 'user') {
				$replace[] = '@' . $parameter['name'];
			} else {
				$replace[] = $parameter['name'];
			}
		}
		$message = str_replace($search, $replace, $message);

		return $this->cutMessageToSearchResult($message, $term, $isSensitive);
	}

	protected function formatActorDisplayName(string $actorType, string $displayName): string {
		if (in_array($actorType, [Attendee::ACTOR_GUESTS, Attendee::ACTOR_EMAILS], true)) {
			if ($displayName === '') {
				return $this->l->t('Guest');
			}
			return $this->l->t('%s (guest)', $displayName);
		}
		return $displayName;
	}

	protected function createSearchResultEntry(
		Room $room,
		IUser $user,
		string $iconUrl,
		string $displayName,
		string $messageStr,
		string $messageId,
		?int $threadId,
		string $actorType,
		string $actorId,
		int $timestamp,
	): SearchResultEntry {
		$subline = $this->getSublineTemplate();
		if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
			$subline = '{user}';
		}

		$urlParams = [
			'token' => $room->getToken(),
			'_fragment' => 'message_' . $messageId,
		];
		if ($threadId !== null) {
			$urlParams['threadId'] = $threadId;
		}

		$entry = new SearchResultEntry(
			$iconUrl,
			str_replace(
				['{user}', '{conversation}'],
				[$displayName, $room->getDisplayName($user->getUID())],
				$subline
			),
			$messageStr,
			$this->url->linkToRouteAbsolute('spreed.Page.showCall', $urlParams),
			'icon-talk', // $iconClass,
			true
		);

		$entry->addAttribute('conversation', $room->getToken());
		$entry->addAttribute('messageId', $messageId);
		if ($threadId !== null) {
			$entry->addAttribute('threadId', (string)$threadId);
		}
		$entry->addAttribute('actorType', $actorType);
		$entry->addAttribute('actorId', $actorId);
		$entry->addAttribute('timestamp', (string)$timestamp);

		return $entry;
	}

	/**
	 * Cut the message down to the part surrounding the search result.
	 *
	 * When the conversation is sensitive for the participant, only 10 characters
	 * before and after the search result are shown, so no additional message
	 * content is exposed in the search results.
	 */
	protected function cutMessageToSearchResult(string $messageStr, string $term, bool $isSensitive): string {
		$matchPosition = mb_stripos($messageStr, $term);

		if ($isSensitive) {
			$matchLength = mb_strlen($term);
			if ($matchPosition === false) {
				// The term is not part of the parsed message (e.g. it only matched
				// a placeholder), so we don't have a result to surround.
				$matchPosition = 0;
				$matchLength = 0;
			}

			$start = max(0, $matchPosition - 10);
			$length = ($matchPosition - $start) + $matchLength + 10;

			return ($start > 0 ? '…' : '')
				. mb_substr($messageStr, $start, $length)
				. (mb_strlen($messageStr) > ($start + $length) ? '…' : '');
		}

		if ($matchPosition > 30 && mb_strlen($messageStr) > 40) {
			// Mostlikely the result is not visible from the beginning,
			// so we cut of the message a bit.
			return '…' . mb_substr($messageStr, $matchPosition - 10);
		}

		return $messageStr;
	}

	#[\Override]
	public function getSupportedFilters(): array {
		return [
			IFilter::BUILTIN_TERM,
			IFilter::BUILTIN_SINCE,
			IFilter::BUILTIN_UNTIL,
			IFilter::BUILTIN_PERSON,
			self::CONVERSATION_FILTER,
		];
	}

	#[\Override]
	public function getAlternateIds(): array {
		return ['talk-message'];
	}

	#[\Override]
	public function getCustomFilters(): array {
		return [
			new FilterDefinition(self::CONVERSATION_FILTER)
		];
	}
}
