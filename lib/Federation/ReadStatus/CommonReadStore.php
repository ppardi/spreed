<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\ReadStatus;

use OCA\Talk\Participant;
use OCA\Talk\Service\ParticipantService;
use OCP\Http\Client\IResponse;

/**
 * Keeps the common read marker the host of a federated conversation last reported.
 *
 * Only the host can compute it (our own attendee list for a proxy room is just us), and the
 * conversation list has to report it without a round trip, so it is stored on the attendee.
 */
class CommonReadStore {
	public function __construct(
		private readonly ParticipantService $participantService,
	) {
	}

	/**
	 * Remember what a proxied response told us, whenever it differs from what we stored.
	 */
	public function remember(Participant $participant, IResponse $proxyResponse): void {
		$header = $proxyResponse->getHeader('X-Chat-Last-Common-Read');
		if ($header === '') {
			return;
		}

		$lastCommonRead = (int)$header;
		$attendee = $participant->getAttendee();
		// Last write wins, decreases included: marking a conversation unread lowers a
		// participant's read marker, so the host's minimum legitimately drops, and upstream
		// treats any change as news (ChatController::prepareCommentsAsDataResponse compares
		// !==, not <). Refusing a decrease would keep the "read by everyone" tick on messages
		// nobody has read any more.
		if ($lastCommonRead === $attendee->getLastCommonReadMessage()) {
			return;
		}

		$attendee->setLastCommonReadMessage($lastCommonRead);
		$this->participantService->updateLastCommonReadMessage($participant, $lastCommonRead);
	}
}
