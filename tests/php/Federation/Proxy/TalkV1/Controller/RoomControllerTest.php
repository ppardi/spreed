<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Proxy\TalkV1\Controller;

use OCA\Talk\Federation\Proxy\TalkV1\Controller\RoomController;
use OCA\Talk\Federation\Proxy\TalkV1\ProxyRequest;
use OCA\Talk\Federation\Proxy\TalkV1\UserConverter;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RoomControllerTest extends TestCase {
	protected ProxyRequest&MockObject $proxy;
	protected UserConverter&MockObject $userConverter;
	protected Room&MockObject $room;
	protected Participant&MockObject $participant;
	protected RoomController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->proxy = $this->createMock(ProxyRequest::class);
		$this->userConverter = $this->createMock(UserConverter::class);

		$this->room = $this->createMock(Room::class);
		$this->room->method('getRemoteServer')->willReturn('https://nc1.test');
		$this->room->method('getRemoteToken')->willReturn('abcdef');
		$this->participant = $this->createMock(Participant::class);
		$this->participant->method('getAttendee')->willReturn(Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'bill',
			'display_name' => 'Bill',
			'invited_cloud_id' => 'bill@nc2.test',
			'access_token' => 'secret',
		]));

		$this->controller = new RoomController(
			$this->proxy,
			$this->userConverter,
			$this->createMock(ParticipantService::class),
		);

		$this->userConverter->method('convertAttendees')->willReturnArgument(1);
		$this->proxy->method('getOCSData')->willReturn([]);
	}

	private function hostAnswers(): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(Http::STATUS_OK);
		return $response;
	}

	public function testAsksTheHostForStatusesWhenTheClientDid(): void {
		$this->proxy->expects($this->once())
			->method('get')
			->with(
				'bill@nc2.test',
				'secret',
				'https://nc1.test/ocs/v2.php/apps/spreed/api/v4/room/abcdef/participants',
				['includeStatus' => 1],
			)
			->willReturn($this->hostAnswers());

		$this->controller->getParticipants($this->room, $this->participant, true);
	}

	public function testDoesNotAskForStatusesWhenTheClientDidNot(): void {
		// Asking anyway would make the host fetch presence from every remote in that room on every
		// participant-list load, including for clients that never show it.
		$this->proxy->expects($this->once())
			->method('get')
			->with(
				'bill@nc2.test',
				'secret',
				'https://nc1.test/ocs/v2.php/apps/spreed/api/v4/room/abcdef/participants',
				[],
			)
			->willReturn($this->hostAnswers());

		$this->controller->getParticipants($this->room, $this->participant, false);
	}
}
