<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Command\Room;

use OCA\Talk\Command\Room\Participants;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use Test\TestCase;

/**
 * The report must agree with ParticipantService::getLastCommonReadChatMessage(), because its whole
 * purpose is naming who holds the marker back. A report that blames the wrong attendee is worse
 * than none: it sends someone to change a setting that was never the problem.
 */
class ParticipantsTest extends TestCase {
	protected function attendee(string $actorType, int $readPrivacy, int $lastRead): array {
		return ['actor_type' => $actorType, 'read_privacy' => $readPrivacy, 'last_read_message' => $lastRead];
	}

	public function testTheLowestSharingAttendeeHoldsTheMarker(): void {
		$report = Participants::readMarkerReport([
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 1814),
			$this->attendee(Attendee::ACTOR_FEDERATED_USERS, Participant::PRIVACY_PUBLIC, 1523),
			$this->attendee(Attendee::ACTOR_FEDERATED_USERS, Participant::PRIVACY_PUBLIC, 1812),
		], 1814);

		$this->assertSame([0, 1, 2], $report['counted']);
		$this->assertSame(1523, $report['common']);
		$this->assertSame([1], $report['holding'], 'only the attendee at the minimum holds it');
	}

	public function testAPrivateAttendeeIsNotCountedEvenWhenItIsFurthestBehind(): void {
		// The real case: an agent frozen far behind, excluded because its read privacy is private.
		$report = Participants::readMarkerReport([
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 1814),
			$this->attendee(Attendee::ACTOR_FEDERATED_USERS, Participant::PRIVACY_PRIVATE, 1523),
		], 1814);

		$this->assertSame([0], $report['counted']);
		$this->assertSame(1814, $report['common'], 'a private attendee must not hold the marker');
		$this->assertSame([], $report['holding'], 'the marker has reached the last message');
	}

	public function testGuestsAndBotsAreNotCounted(): void {
		// getLastCommonReadChatMessage() restricts to users and federated users
		$report = Participants::readMarkerReport([
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 900),
			$this->attendee(Attendee::ACTOR_GUESTS, Participant::PRIVACY_PUBLIC, 1),
			$this->attendee(Attendee::ACTOR_BOTS, Participant::PRIVACY_PUBLIC, 2),
			$this->attendee(Attendee::ACTOR_EMAILS, Participant::PRIVACY_PUBLIC, 3),
			$this->attendee(Attendee::ACTOR_PHONES, Participant::PRIVACY_PUBLIC, 4),
		], 900);

		$this->assertSame([0], $report['counted']);
		$this->assertSame(900, $report['common']);
	}

	public function testNobodySharingMeansNoCommonMarkerAtAll(): void {
		// Not the same as "everyone has read everything": no receipts can be shown at all
		$report = Participants::readMarkerReport([
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PRIVATE, 1814),
			$this->attendee(Attendee::ACTOR_FEDERATED_USERS, Participant::PRIVACY_PRIVATE, 1523),
		], 1814);

		$this->assertSame([], $report['counted']);
		$this->assertNull($report['common']);
		$this->assertSame([], $report['holding']);
	}

	public function testNobodyHoldsTheMarkerWhenEveryoneIsUpToDate(): void {
		// Reported from a real install: with the marker level with the newest message the command
		// flagged every counted attendee as holding it back, which accuses people of being up to
		// date. Nothing is being held back here.
		$report = Participants::readMarkerReport([
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 1825),
			$this->attendee(Attendee::ACTOR_FEDERATED_USERS, Participant::PRIVACY_PUBLIC, 1825),
		], 1825);

		$this->assertSame(1825, $report['common']);
		$this->assertSame([], $report['holding'], 'nobody holds a marker that has reached the end');
	}

	public function testSeveralAttendeesCanShareTheLowestMarker(): void {
		$report = Participants::readMarkerReport([
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 100),
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 100),
			$this->attendee(Attendee::ACTOR_USERS, Participant::PRIVACY_PUBLIC, 200),
		], 200);

		$this->assertSame(100, $report['common']);
		$this->assertSame([0, 1, 2], $report['counted']);
		$this->assertSame([0, 1], $report['holding'], 'both attendees at the minimum hold it');
	}
}
