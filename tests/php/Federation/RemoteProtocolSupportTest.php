<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation;

use OCA\Talk\Federation\RemoteProtocolSupport;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\OCM\Exceptions\OCMArgumentException;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RemoteProtocolSupportTest extends TestCase {
	protected const V1 = 'talk-attachments-v1';
	protected const V2 = 'talk-attachments-v2';

	protected IOCMDiscoveryService&MockObject $discoveryService;
	protected LoggerInterface&MockObject $logger;
	/** @var array<string, array{value: mixed, ttl: int}> */
	protected array $cached = [];
	/** @var list<bool> */
	protected array $skipCacheArguments = [];
	protected RemoteProtocolSupport $protocolSupport;

	public function setUp(): void {
		parent::setUp();
		$this->discoveryService = $this->createMock(IOCMDiscoveryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key]['value'] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
			$this->cached[$key] = ['value' => $value, 'ttl' => $ttl];
			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->cached[$key]);
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->with('talk/remote-protocols')->willReturn($cache);

		$this->protocolSupport = new RemoteProtocolSupport($this->discoveryService, $cacheFactory, $this->logger);
	}

	private function supportingProvider(): IOCMProvider&MockObject {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->with('talk-room', self::V1)
			->willReturn('/ocs/v2.php/apps/spreed/api/');
		return $provider;
	}

	public function testRemoteSupports(): void {
		$this->discoveryService->expects($this->once())
			->method('discover')
			// The first lookup in 10 minutes refreshes the discovery document
			->with('nc2.test', true)
			->willReturn($this->supportingProvider());

		$this->assertTrue($this->protocolSupport->supports('nc2.test', self::V1));
		$this->assertSame(['refresh/talk-attachments-v1/https://nc2.test'], array_keys($this->cached));
	}

	public function testRemoteWithoutProtocolEntry(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->willThrowException(new OCMArgumentException('talk-attachments-v1'));
		$this->discoveryService->method('discover')->willReturn($provider);

		$this->assertFalse($this->protocolSupport->supports('nc2.test', self::V1));
		$this->assertArrayNotHasKey('https://nc2.test', $this->cached, 'A definite answer is not unknown');
	}

	/**
	 * Nextcloud caches discovery documents for 24 hours: the cached one is from before the other server's upgrade to v2
	 */
	private function discoverWithStaleCache(): void {
		$stale = $this->createMock(IOCMProvider::class);
		$stale->method('extractProtocolEntry')->willThrowException(new OCMArgumentException(self::V2));
		$fresh = $this->createMock(IOCMProvider::class);
		$fresh->method('extractProtocolEntry')->willReturn('/ocs/v2.php/apps/spreed/api/');

		$this->discoveryService->method('discover')
			->willReturnCallback(function (string $remote, bool $skipCache) use ($stale, $fresh): IOCMProvider {
				$this->skipCacheArguments[] = $skipCache;
				return $skipCache ? $fresh : $stale;
			});
	}

	public function testCachedDocumentWithoutTheProtocolIsRefreshed(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2));
		$this->assertSame([true], $this->skipCacheArguments);
		$this->assertSame(600, $this->cached['refresh/talk-attachments-v2/https://nc1.test']['ttl'] ?? null);
	}

	public function testDocumentIsRefreshedAtMostOnceIn10Minutes(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2));
		// In production the cache now holds the refreshed document; the mock keeps answering with the stale one
		$this->assertFalse($this->protocolSupport->supports('https://NC1.test/', self::V2));
		$this->assertSame([true, false], $this->skipCacheArguments, 'Asked the server again only once');
	}

	public function testRefreshIsPerProtocol(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V1));
		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2));
		$this->assertSame([true, true], $this->skipCacheArguments);
	}

	public function testRefreshAfter10Minutes(): void {
		$this->discoverWithStaleCache();

		$this->protocolSupport->supports('nc1.test', self::V2);
		unset($this->cached['refresh/talk-attachments-v2/https://nc1.test']); // expired
		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2));
		$this->assertSame([true, true], $this->skipCacheArguments);
	}

	public function testLookupsSkippingTheCacheLeaveTheRefreshMarkerAlone(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2, true));
		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2));
		$this->assertSame([true, true], $this->skipCacheArguments);
	}

	public function testRemoteUnreachableIsUnknown(): void {
		$this->discoveryService->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull($this->protocolSupport->supports('nc2.test', self::V1));
		$this->assertSame(300, $this->cached['https://nc2.test']['ttl'] ?? null);
	}

	public function testOtherFailureIsUnknown(): void {
		$this->discoveryService->method('discover')
			->willThrowException(new \RuntimeException('Something else'));

		$this->assertNull($this->protocolSupport->supports('https://nc2.test/', self::V1));
		$this->assertArrayHasKey('https://nc2.test', $this->cached);
	}

	public function testUnknownRemoteIsNotAskedAgainForAWhile(): void {
		$this->discoveryService->expects($this->once())
			->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));

		$this->assertNull($this->protocolSupport->supports('nc2.test', self::V1));
		// A user's next upload must not wait for the discovery timeouts again
		$this->assertNull($this->protocolSupport->supports('NC2.test', self::V1));
	}

	public function testSkipCacheBypassesTheUnknownMarker(): void {
		$this->cached['https://nc2.test'] = ['value' => 1, 'ttl' => 300];
		$this->discoveryService->expects($this->once())
			->method('discover')
			->with('nc2.test', true)
			->willReturn($this->supportingProvider());

		$this->assertTrue($this->protocolSupport->supports('nc2.test', self::V1, true));
		$this->assertSame([], $this->cached, 'A definite answer clears the marker');
	}

	public function testSecondProtocolIsAskedForByName(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->expects($this->once())
			->method('extractProtocolEntry')
			->with('talk-room', self::V2)
			->willReturn('/ocs/v2.php/apps/spreed/api/');
		$this->discoveryService->method('discover')->with('nc1.test', true)->willReturn($provider);

		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V2));
	}

	public function testServerWithOnlyTheFirstProtocolDoesNotSupportTheSecond(): void {
		// Build 24.0.5.1: shows files, but has no endpoint for files of federated participants (ruling R10)
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->willReturnCallback(function (string $resource, string $protocol): string {
				if ($protocol !== self::V1) {
					throw new OCMArgumentException($protocol);
				}
				return '/ocs/v2.php/apps/spreed/api/';
			});
		$this->discoveryService->method('discover')->willReturn($provider);

		$this->assertTrue($this->protocolSupport->supports('nc1.test', self::V1));
		$this->assertFalse($this->protocolSupport->supports('nc1.test', self::V2));
	}

	public function testUnreachableServerIsUnknownForEveryProtocol(): void {
		$this->discoveryService->expects($this->once())
			->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));

		$this->assertNull($this->protocolSupport->supports('nc1.test', self::V2));
		$this->assertNull($this->protocolSupport->supports('nc1.test', self::V1), 'The unknown marker is per server');
	}
}
