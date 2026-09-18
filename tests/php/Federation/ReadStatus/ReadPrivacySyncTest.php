<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\ReadStatus;

use OCA\Talk\Federation\ReadStatus\ReadPrivacySync;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ReadPrivacySyncTest extends TestCase {
	protected IRequest&MockObject $request;
	protected ParticipantService&MockObject $participantService;
	protected ReadPrivacySync $sync;

	public function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->sync = new ReadPrivacySync($this->request, $this->participantService);
	}

	private function participantWith(int $readPrivacy): Participant {
		$attendee = new Attendee();
		$attendee->setActorType(Attendee::ACTOR_FEDERATED_USERS);
		$attendee->setActorId('user@remote.test');
		$attendee->setReadPrivacy($readPrivacy);

		$room = $this->createMock(Room::class);
		return new Participant($room, $attendee, null);
	}

	public function testHeaderPublicUpdatesAPrivateAttendee(): void {
		$this->request->method('getHeader')
			->with(ReadPrivacySync::REQUEST_HEADER)
			->willReturn('0');
		$participant = $this->participantWith(Participant::PRIVACY_PRIVATE);

		$this->participantService->expects($this->once())
			->method('updateReadPrivacyForActor')
			->with(Attendee::ACTOR_FEDERATED_USERS, 'user@remote.test', Participant::PRIVACY_PUBLIC);

		$this->sync->applyFromRequest($participant);

		$this->assertSame(Participant::PRIVACY_PUBLIC, $participant->getAttendee()->getReadPrivacy());
	}

	public function testHeaderPublicIsANoOpWhenAlreadyPublic(): void {
		$this->request->method('getHeader')
			->with(ReadPrivacySync::REQUEST_HEADER)
			->willReturn('0');
		$participant = $this->participantWith(Participant::PRIVACY_PUBLIC);

		$this->participantService->expects($this->never())->method('updateReadPrivacyForActor');

		$this->sync->applyFromRequest($participant);
	}

	public function testHeaderPrivateUpdatesAPublicAttendee(): void {
		$this->request->method('getHeader')
			->with(ReadPrivacySync::REQUEST_HEADER)
			->willReturn('1');
		$participant = $this->participantWith(Participant::PRIVACY_PUBLIC);

		$this->participantService->expects($this->once())
			->method('updateReadPrivacyForActor')
			->with(Attendee::ACTOR_FEDERATED_USERS, 'user@remote.test', Participant::PRIVACY_PRIVATE);

		$this->sync->applyFromRequest($participant);

		$this->assertSame(Participant::PRIVACY_PRIVATE, $participant->getAttendee()->getReadPrivacy());
	}

	public function testMissingHeaderIsANoOp(): void {
		$this->request->method('getHeader')
			->with(ReadPrivacySync::REQUEST_HEADER)
			->willReturn('');
		$participant = $this->participantWith(Participant::PRIVACY_PRIVATE);

		$this->participantService->expects($this->never())->method('updateReadPrivacyForActor');

		$this->sync->applyFromRequest($participant);
	}

	public function testJunkHeaderIsTreatedAsAbsent(): void {
		$this->request->method('getHeader')
			->with(ReadPrivacySync::REQUEST_HEADER)
			->willReturn('yes');
		$participant = $this->participantWith(Participant::PRIVACY_PRIVATE);

		$this->participantService->expects($this->never())->method('updateReadPrivacyForActor');

		$this->sync->applyFromRequest($participant);
	}
}
