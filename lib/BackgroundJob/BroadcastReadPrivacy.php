<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Exceptions\CannotReachRemoteException;
use OCA\Talk\Federation\Proxy\TalkV1\ProxyRequest;
use OCA\Talk\Manager;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Tells the servers hosting a user's federated conversations that their read privacy changed.
 *
 * Every proxied request already carries the setting in a header, and the host applies it
 * (ReadPrivacySync). Nothing triggers a request when the setting changes, though, so the host keeps
 * what it last heard until that user next does something federated. For a person that is their next
 * page load; for an account with no client - an agent or a bot - it is never, and the host goes on
 * counting them in the common read marker. One participant who never reads pins that marker for
 * everyone in the room.
 *
 * So after the setting changes, make one cheap request per federated conversation purely to carry
 * the header. Queued rather than inline: a settings toggle must not wait on, or fail because of,
 * another server being unreachable.
 */
class BroadcastReadPrivacy extends QueuedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly Manager $manager,
		private readonly ParticipantService $participantService,
		private readonly ProxyRequest $proxy,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{userId: string} $argument
	 */
	#[\Override]
	protected function run($argument): void {
		$userId = $argument['userId'];

		foreach ($this->manager->getRoomsForUser($userId) as $room) {
			if (!$room->isFederatedConversation()) {
				// A local room's attendee row was already updated directly
				continue;
			}

			try {
				$participant = $this->participantService->getParticipant($room, $userId, false);
			} catch (\Throwable $e) {
				continue;
			}

			$attendee = $participant->getAttendee();
			if ($attendee->getInvitedCloudId() === '' || $attendee->getAccessToken() === '') {
				continue;
			}

			try {
				// The endpoint is irrelevant - this request exists for its header. Capabilities is
				// the cheapest one the host answers and it changes nothing there.
				$this->proxy->get(
					$attendee->getInvitedCloudId(),
					$attendee->getAccessToken(),
					$room->getRemoteServer() . '/ocs/v2.php/apps/spreed/api/v4/room/' . $room->getRemoteToken() . '/capabilities',
					[],
					$userId,
				);
			} catch (CannotReachRemoteException|\Throwable $e) {
				// Best effort: the host keeps its previous value and will pick the new one up from
				// this user's next federated request.
				$this->logger->debug('Could not announce the read privacy of {user} to {server}', [
					'user' => $userId,
					'server' => $room->getRemoteServer(),
					'exception' => $e,
				]);
			}
		}
	}
}
