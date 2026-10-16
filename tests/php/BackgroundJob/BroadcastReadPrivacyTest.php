<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\BackgroundJob;

use OCA\Talk\BackgroundJob\BroadcastReadPrivacy;
use OCA\Talk\Federation\Proxy\TalkV1\ProxyRequest;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class BroadcastReadPrivacyTest extends TestCase {
	protected Manager&MockObject $manager;
	protected ParticipantService&MockObject $participantService;
	protected ProxyRequest&MockObject $proxy;
	protected BroadcastReadPrivacy $job;

	public function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(Manager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->proxy = $this->createMock(ProxyRequest::class);

		$this->job = new BroadcastReadPrivacy(
			$this->createMock(ITimeFactory::class),
			$this->manager,
			$this->participantService,
			$this->proxy,
			$this->createMock(LoggerInterface::class),
		);
	}

	protected function room(bool $federated, string $remoteServer = 'https://remote.test', string $remoteToken = 'remotetoken'): Room&MockObject {
		$room = $this->createMock(Room::class);
		$room->method('isFederatedConversation')->willReturn($federated);
		$room->method('getRemoteServer')->willReturn($remoteServer);
		$room->method('getRemoteToken')->willReturn($remoteToken);
		return $room;
	}

	protected function participantWithInvite(string $cloudId = 'bill@remote.test', string $token = 'invite-token'): Participant&MockObject {
		$attendee = Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'paulp',
			'invited_cloud_id' => $cloudId,
			'access_token' => $token,
		]);
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn($attendee);
		return $participant;
	}

	public function testAnnouncesToEachFederatedConversation(): void {
		$this->manager->method('getRoomsForUser')->willReturn([
			$this->room(true, 'https://a.test', 'tokenA'),
			$this->room(true, 'https://b.test', 'tokenB'),
		]);
		$this->participantService->method('getParticipant')->willReturn($this->participantWithInvite());

		$urls = [];
		$actingUsers = [];
		$this->proxy->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (?string $cloudId, ?string $token, string $url, array $params, ?string $actingUserId) use (&$urls, &$actingUsers): IResponse {
				$urls[] = $url;
				$actingUsers[] = $actingUserId;
				return $this->createMock(IResponse::class);
			});

		self::invokePrivate($this->job, 'run', [['userId' => 'paulp']]);

		$this->assertSame([
			'https://a.test/ocs/v2.php/apps/spreed/api/v4/room/tokenA/capabilities',
			'https://b.test/ocs/v2.php/apps/spreed/api/v4/room/tokenB/capabilities',
		], $urls);
		// Without this the request carries no read-privacy header at all: the job has no session,
		// so ProxyRequest would fall back to IUserSession::getUser() and find nothing.
		$this->assertSame(['paulp', 'paulp'], $actingUsers, 'each request must name the acting user');
	}

	public function testSkipsLocalConversations(): void {
		// A local room's attendee row is updated directly by the listener; there is no host to tell.
		$this->manager->method('getRoomsForUser')->willReturn([$this->room(false)]);
		$this->proxy->expects($this->never())->method('get');

		self::invokePrivate($this->job, 'run', [['userId' => 'paulp']]);
	}

	public function testAnUnreachableHostDoesNotStopTheRest(): void {
		$this->manager->method('getRoomsForUser')->willReturn([
			$this->room(true, 'https://down.test', 'tokenA'),
			$this->room(true, 'https://up.test', 'tokenB'),
		]);
		$this->participantService->method('getParticipant')->willReturn($this->participantWithInvite());

		$reached = [];
		$this->proxy->method('get')
			->willReturnCallback(function (?string $cloudId, ?string $token, string $url) use (&$reached): IResponse {
				if (str_contains($url, 'down.test')) {
					throw new \RuntimeException('unreachable');
				}
				$reached[] = $url;
				return $this->createMock(IResponse::class);
			});

		self::invokePrivate($this->job, 'run', [['userId' => 'paulp']]);

		$this->assertSame(['https://up.test/ocs/v2.php/apps/spreed/api/v4/room/tokenB/capabilities'], $reached);
	}
}
