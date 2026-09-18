<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\ReadStatus;

use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Service\ParticipantService;
use OCP\IRequest;

/**
 * How a federated participant's "Send read receipts" setting reaches the server hosting the conversation.
 *
 * The host stores federated attendees as PRIVACY_PRIVATE (ParticipantService::addUsers()), so they are
 * excluded from the common read marker until their own server says the user shares their read status.
 */
class ReadPrivacySync {
	/** Talk feature: clients show the read marker in a federated conversation only when both servers list it */
	public const FEATURE = 'federated-read-status';
	/** Sent by a remote server on proxied requests: 0 = the user shares their read status, 1 = they do not */
	public const REQUEST_HEADER = 'X-Nextcloud-Talk-Federation-Read-Privacy';

	public function __construct(
		private readonly IRequest $request,
		private readonly ParticipantService $participantService,
	) {
	}

	/**
	 * Apply the read privacy a remote server sent for its user, if it differs from what we stored.
	 *
	 * An absent or unparseable header means "no information": never downgrade or upgrade on a guess,
	 * because older servers send nothing and their users must keep the private default.
	 */
	public function applyFromRequest(Participant $participant): void {
		$header = $this->request->getHeader(self::REQUEST_HEADER);
		if ($header !== (string)Participant::PRIVACY_PUBLIC && $header !== (string)Participant::PRIVACY_PRIVATE) {
			return;
		}

		$readPrivacy = (int)$header;
		$attendee = $participant->getAttendee();
		if ($attendee->getReadPrivacy() === $readPrivacy) {
			// Writing on every proxied request would be one UPDATE per chat poll
			return;
		}

		$this->participantService->updateReadPrivacyForActor(
			Attendee::ACTOR_FEDERATED_USERS,
			$attendee->getActorId(),
			$readPrivacy,
		);
		// Deliberate: updateReadPrivacyForActor() writes the database directly, so the participant
		// this request already resolved would otherwise carry a stale value for the rest of it —
		// and the common read marker is computed later in that same request.
		$attendee->setReadPrivacy($readPrivacy);
	}
}
