<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\BackgroundJob\EnsureAttachmentShares;
use OCA\Talk\Config;
use OCA\Talk\Events\AttendeeRemovedEvent;
use OCA\Talk\Events\AttendeesAddedEvent;
use OCA\Talk\Events\BeforeRoomDeletedEvent;
use OCA\Talk\Events\SystemMessageSentEvent;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Room;
use OCA\Talk\Share\Helper\RoomShareLocator;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Comments\IComment;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Keeps federated attachment shares in sync with the conversation (host side).
 *
 * Runs inside users' actions (uploads, messages, removals), so it never throws.
 *
 * @template-implements IEventListener<Event>
 */
class Listener implements IEventListener {
	public function __construct(
		private readonly AttachmentSharer $sharer,
		private readonly Manager $manager,
		private readonly RoomShareProvider $roomShareProvider,
		private readonly RoomShareLocator $roomShareLocator,
		private readonly IRootFolder $rootFolder,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $timeFactory,
		private readonly Config $talkConfig,
		private readonly LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			$this->dispatch($event);
		} catch (\Throwable $e) {
			$this->logger->error('Federated attachments: could not handle ' . get_class($event), ['exception' => $e]);
		}
	}

	private function dispatch(Event $event): void {
		// Cleanup: also after federation was switched off, so no shares or rows are left behind
		if ($event instanceof ShareDeletedEvent) {
			$this->onShareDeleted($event->getShare());
			return;
		}
		if ($event instanceof AttendeeRemovedEvent) {
			$this->onAttendeeRemoved($event->getRoom(), $event->getAttendee());
			return;
		}
		if ($event instanceof BeforeRoomDeletedEvent) {
			$this->sharer->unshareRoom($event->getRoom());
			return;
		}

		$systemMessage = $event instanceof SystemMessageSentEvent ? $this->getSystemMessage($event->getComment()) : null;
		if ($event instanceof SystemMessageSentEvent && $systemMessage['message'] === 'history_cleared') {
			if (!$event->getRoom()->isFederatedConversation()) {
				// Room shares were removed with plain SQL, without share events
				$this->sharer->unshareRoom($event->getRoom());
			}
			return;
		}

		// Sharing
		if (!$this->talkConfig->isFederationEnabled()) {
			return;
		}
		if ($event instanceof ShareCreatedEvent) {
			$this->onShareCreated($event->getShare());
		} elseif ($event instanceof SystemMessageSentEvent && $systemMessage['message'] === 'file_shared') {
			$this->onFileMessage($event->getRoom(), $systemMessage['parameters']);
		} elseif ($event instanceof AttendeesAddedEvent) {
			$this->onAttendeesAdded($event->getRoom(), $event->getAttendees());
		}
	}

	/**
	 * @return array{message: string, parameters: array}
	 */
	private function getSystemMessage(IComment $comment): array {
		$data = json_decode($comment->getMessage(), true);
		return [
			'message' => is_array($data) ? (string)($data['message'] ?? '') : '',
			'parameters' => is_array($data) && is_array($data['parameters'] ?? null) ? $data['parameters'] : [],
		];
	}

	private function onShareCreated(IShare $share): void {
		if ($share->getShareType() !== IShare::TYPE_ROOM) {
			return;
		}
		$room = $this->getHostedRoom($share->getSharedWith());
		if ($room !== null) {
			$this->share($room, $share);
		}
	}

	private function onShareDeleted(IShare $share): void {
		if ($share->getShareType() === IShare::TYPE_REMOTE) {
			$this->sharer->forgetRemoteShare($share->getId());
			return;
		}
		if ($share->getShareType() !== IShare::TYPE_ROOM) {
			return;
		}
		$room = $this->getHostedRoom($share->getSharedWith());
		if ($room !== null) {
			$this->sharer->unshareRoomShare($room, $share->getId());
		}
	}

	private function onFileMessage(Room $room, array $parameters): void {
		if ($room->isFederatedConversation()) {
			return;
		}
		// Also re-shares when a recipient removed the share (design D4)
		$roomShare = $this->getRoomShareOfMessage($room, $parameters);
		if ($roomShare !== null) {
			$this->share($room, $roomShare);
		}
	}

	/**
	 * @param Attendee[] $attendees
	 */
	private function onAttendeesAdded(Room $room, array $attendees): void {
		foreach ($attendees as $attendee) {
			if ($attendee->getActorType() === Attendee::ACTOR_FEDERATED_USERS
				&& $attendee->getState() === Invitation::STATE_ACCEPTED) {
				// We are inside the remote server's "invitation accepted" request: share the history in the background
				EnsureAttachmentShares::schedule($this->jobList, $this->timeFactory, [
					'roomId' => $room->getId(),
					'cloudId' => $attendee->getActorId(),
					'attempt' => 1,
				]);
			}
		}
	}

	private function onAttendeeRemoved(Room $room, Attendee $attendee): void {
		if ($attendee->getActorType() === Attendee::ACTOR_FEDERATED_USERS) {
			$this->sharer->unshareForRecipient($room, $attendee->getActorId());
		}
	}

	private function share(Room $room, IShare $roomShare): void {
		try {
			$success = $this->sharer->shareRoomShare($room, $roomShare);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not share conversation attachment ' . $roomShare->getId() . ', retrying later', ['exception' => $e]);
			$success = false;
		}

		if (!$success) {
			EnsureAttachmentShares::schedule($this->jobList, $this->timeFactory, [
				'roomId' => $room->getId(),
				'roomShareId' => $roomShare->getId(),
				'attempt' => 2,
			]);
		}
	}

	private function getRoomShareOfMessage(Room $room, array $parameters): ?IShare {
		$roomShare = null;
		if (isset($parameters['share'])) {
			try {
				$roomShare = $this->roomShareProvider->getShareById((string)$parameters['share']);
			} catch (ShareNotFound) {
				return null;
			}
		} elseif (isset($parameters['fileId'])) {
			$node = $this->rootFolder->getFirstNodeById((int)$parameters['fileId']);
			if ($node instanceof Node) {
				$roomShare = $this->roomShareLocator->findForNode($room, $node)[0];
			}
		}

		// Never share another conversation's files because of a message in this one
		if ($roomShare === null || $roomShare->getSharedWith() !== $room->getToken()) {
			return null;
		}
		return $roomShare;
	}

	private function getHostedRoom(string $token): ?Room {
		try {
			$room = $this->manager->getRoomByToken($token);
		} catch (RoomNotFoundException) {
			return null;
		}
		return $room->isFederatedConversation() ? null : $room;
	}
}
