<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Federation\BackendNotifier;
use OCA\Talk\Federation\Presence\FeatureSupport;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\FederatedUserStatusService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class FederatedUserStatusServiceTest extends TestCase {
	protected BackendNotifier&MockObject $backendNotifier;
	protected FeatureSupport&MockObject $featureSupport;
	protected ICache&MockObject $cache;
	protected ICacheFactory&MockObject $cacheFactory;
	protected ICloudIdManager&MockObject $cloudIdManager;
	protected Room&MockObject $room;
	/** @var array<string, array{value: mixed, ttl: int}> */
	protected array $cached = [];
	protected FederatedUserStatusService $service;

	public function setUp(): void {
		parent::setUp();

		$this->backendNotifier = $this->createMock(BackendNotifier::class);
		$this->featureSupport = $this->createMock(FeatureSupport::class);

		$this->cache = $this->createMock(ICache::class);
		$this->cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key]['value'] ?? null);
		$this->cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
			$this->cached[$key] = ['value' => $value, 'ttl' => $ttl];
			return true;
		});
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->with('talk/federated-presence')->willReturn($this->cache);

		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
		$this->cloudIdManager->method('resolveCloudId')
			->willReturnCallback(function (string $cloudId): ICloudId {
				$at = strrpos($cloudId, '@');
				if ($at === false) {
					throw new \InvalidArgumentException($cloudId);
				}
				$resolved = $this->createMock(ICloudId::class);
				$resolved->method('getUser')->willReturn(substr($cloudId, 0, $at));
				$resolved->method('getRemote')->willReturn(substr($cloudId, $at + 1));
				return $resolved;
			});

		$this->room = $this->createMock(Room::class);
		$this->room->method('getToken')->willReturn('roomtoken');

		$this->service = $this->buildService($this->enabledAppConfig());
	}

	private function enabledAppConfig(): IAppConfig&MockObject {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueBool')
			->willReturnCallback(static fn (string $key, bool $default = false): bool => $default);
		return $appConfig;
	}

	private function buildService(IAppConfig $appConfig): FederatedUserStatusService {
		return new FederatedUserStatusService(
			$this->backendNotifier,
			$this->featureSupport,
			$this->cloudIdManager,
			$appConfig,
			$this->cacheFactory,
		);
	}

	private function federatedParticipant(string $cloudId, int $attendeeId = 1, int $state = Invitation::STATE_ACCEPTED): Participant {
		return new Participant(
			$this->room,
			Attendee::fromRow([
				'id' => $attendeeId,
				'actor_type' => Attendee::ACTOR_FEDERATED_USERS,
				'actor_id' => $cloudId,
				'access_token' => 'token-' . $attendeeId,
				'state' => $state,
			]),
			null,
		);
	}

	private function cacheKeyFor(string $cloudId): string {
		return sha1(strtolower($cloudId));
	}

	public function testCachedStatusIsServedWithoutAskingTheRemote(): void {
		$this->cached[$this->cacheKeyFor('bill@cloud.test')] = ['value' => ['status' => 'away', 'icon' => null], 'ttl' => 120];
		$this->backendNotifier->expects($this->never())->method('requestUserStatuses');

		$result = $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]);

		$this->assertSame(['bill@cloud.test' => ['status' => 'away', 'icon' => null]], $result);
	}

	public function testUnsupportedRemoteIsNeverAsked(): void {
		$this->featureSupport->method('remoteSupports')->with('cloud.test')->willReturn(false);
		$this->backendNotifier->expects($this->never())->method('requestUserStatuses');

		$this->assertSame([], $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]));
	}

	public function testUnknownSupportIsNeverAskedEither(): void {
		// null means the discovery document could not be read. FeatureSupport is already backing
		// off; asking anyway would pay the timeout twice.
		$this->featureSupport->method('remoteSupports')->willReturn(null);
		$this->backendNotifier->expects($this->never())->method('requestUserStatuses');

		$this->assertSame([], $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]));
	}

	public function testOneRequestPerRemoteServerCarriesAllItsCloudIds(): void {
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->backendNotifier->expects($this->once())
			->method('requestUserStatuses')
			->with('cloud.test', 1, 'token-1', 'roomtoken', ['bill@cloud.test', 'carol@cloud.test'])
			->willReturn([
				'bill@cloud.test' => ['status' => 'online', 'icon' => null],
				'carol@cloud.test' => ['status' => 'dnd', 'icon' => null],
			]);

		$result = $this->service->getStatuses($this->room, [
			$this->federatedParticipant('bill@cloud.test', 1),
			$this->federatedParticipant('carol@cloud.test', 2),
		]);

		$this->assertCount(2, $result);
		$this->assertSame(120, $this->cached[$this->cacheKeyFor('bill@cloud.test')]['ttl']);
	}

	public function testTheCredentialComesFromAnAcceptedAttendee(): void {
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		// Any attendee of that server carries a usable invite, but a pending one may not exist on
		// the other side yet, and asking with it would lose presence for everyone on that server.
		$this->backendNotifier->expects($this->once())
			->method('requestUserStatuses')
			->with('cloud.test', 2, 'token-2', 'roomtoken', $this->anything())
			->willReturn([]);

		$this->service->getStatuses($this->room, [
			$this->federatedParticipant('bill@cloud.test', 1, Invitation::STATE_PENDING),
			$this->federatedParticipant('carol@cloud.test', 2, Invitation::STATE_ACCEPTED),
		]);
	}

	public function testAnUnreachableRemoteYieldsNoStatusAndIsNegativeCachedBriefly(): void {
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->backendNotifier->method('requestUserStatuses')->willReturn(null);

		$this->assertSame([], $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]));
		$this->assertSame(['value' => 'unreachable', 'ttl' => 30], $this->cached[$this->cacheKeyFor('bill@cloud.test')]);
	}

	public function testARefusedRemoteIsBackedOffFromForAnHour(): void {
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->backendNotifier->method('requestUserStatuses')->willReturn(false);

		$this->assertSame([], $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]));
		// A distinct marker, because an hour and 30 seconds are very different states to find in
		// the cache when working out why someone shows no presence.
		$this->assertSame(['value' => 'unsupported', 'ttl' => 3600], $this->cached[$this->cacheKeyFor('bill@cloud.test')]);
	}

	public function testANegativeCacheEntryIsNotRetriedWithinItsTtl(): void {
		$this->cached[$this->cacheKeyFor('bill@cloud.test')] = ['value' => 'unreachable', 'ttl' => 30];
		$this->featureSupport->expects($this->never())->method('remoteSupports');
		$this->backendNotifier->expects($this->never())->method('requestUserStatuses');

		$this->assertSame([], $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]));
	}

	public function testCacheOnlyNeverTouchesTheNetwork(): void {
		$this->cached[$this->cacheKeyFor('bill@cloud.test')] = ['value' => ['status' => 'online', 'icon' => null], 'ttl' => 120];
		$this->featureSupport->expects($this->never())->method('remoteSupports');
		$this->backendNotifier->expects($this->never())->method('requestUserStatuses');

		$result = $this->service->getStatuses($this->room, [
			$this->federatedParticipant('bill@cloud.test', 1),
			$this->federatedParticipant('dana@other.test', 2),
		], cacheOnly: true);

		// What is held is relayed; what is missing stays missing rather than being fetched inside
		// a request that is itself a federation request.
		$this->assertSame(['bill@cloud.test' => ['status' => 'online', 'icon' => null]], $result);
	}

	public function testDisabledByTheAdminSwitch(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueBool')
			->willReturnCallback(static fn (string $key, bool $default = false): bool => $key !== 'federation_presence_enabled' && $default);
		$this->backendNotifier->expects($this->never())->method('requestUserStatuses');

		$service = $this->buildService($appConfig);

		$this->assertSame([], $service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]));
	}

	public function testAnswersForUsersWeDidNotAskAboutAreIgnored(): void {
		$this->featureSupport->method('remoteSupports')->willReturn(true);
		$this->backendNotifier->method('requestUserStatuses')->willReturn([
			'bill@cloud.test' => ['status' => 'online', 'icon' => null],
			'eve@cloud.test' => ['status' => 'online', 'icon' => null],
			'malformed' => 'not an array',
		]);

		$result = $this->service->getStatuses($this->room, [$this->federatedParticipant('bill@cloud.test')]);

		// A remote cannot widen the answer into users this server never asked about
		$this->assertSame(['bill@cloud.test' => ['status' => 'online', 'icon' => null]], $result);
	}

	public function testASupportedRemoteStillAnswersWhenAnotherDoesNot(): void {
		$this->featureSupport->method('remoteSupports')
			->willReturnCallback(static fn (string $server): ?bool => $server === 'cloud.test');
		$this->backendNotifier->expects($this->once())
			->method('requestUserStatuses')
			->with('cloud.test', 1, 'token-1', 'roomtoken', ['bill@cloud.test'])
			->willReturn(['bill@cloud.test' => ['status' => 'online', 'icon' => null]]);

		$result = $this->service->getStatuses($this->room, [
			$this->federatedParticipant('bill@cloud.test', 1),
			$this->federatedParticipant('dana@other.test', 2),
		]);

		// The unsupported server contributes nothing and breaks nothing
		$this->assertSame(['bill@cloud.test' => ['status' => 'online', 'icon' => null]], $result);
	}
}
