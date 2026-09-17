<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\FeatureSupport;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\OCM\Exceptions\OCMArgumentException;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FeatureSupportTest extends TestCase {
	protected IOCMDiscoveryService&MockObject $discoveryService;
	protected LoggerInterface&MockObject $logger;
	/** @var array<string, array{value: mixed, ttl: int}> */
	protected array $cached = [];
	/** @var list<bool> */
	protected array $skipCacheArguments = [];
	protected FeatureSupport $featureSupport;

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
		$cacheFactory->method('createDistributed')->with('talk/federated-attachments')->willReturn($cache);

		$this->featureSupport = new FeatureSupport($this->discoveryService, $cacheFactory, $this->logger);
	}

	private function supportingProvider(): IOCMProvider&MockObject {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->with('talk-room', 'talk-attachments-v1')
			->willReturn('/ocs/v2.php/apps/spreed/api/');
		return $provider;
	}

	public function testRemoteSupports(): void {
		$this->discoveryService->expects($this->once())
			->method('discover')
			// The first lookup in 10 minutes refreshes the discovery document
			->with('nc2.test', true)
			->willReturn($this->supportingProvider());

		$this->assertTrue($this->featureSupport->remoteSupports('nc2.test'));
		$this->assertSame(['refresh/talk-attachments-v1/https://nc2.test'], array_keys($this->cached));
	}

	public function testRemoteWithoutProtocolEntry(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->willThrowException(new OCMArgumentException('talk-attachments-v1'));
		$this->discoveryService->method('discover')->willReturn($provider);

		$this->assertFalse($this->featureSupport->remoteSupports('nc2.test'));
		$this->assertArrayNotHasKey('https://nc2.test', $this->cached, 'A definite answer is not unknown');
	}

	/**
	 * Nextcloud caches discovery documents for 24 hours: the cached one is from before the other server's upgrade to v2
	 */
	private function discoverWithStaleCache(): void {
		$stale = $this->createMock(IOCMProvider::class);
		$stale->method('extractProtocolEntry')->willThrowException(new OCMArgumentException('talk-attachments-v2'));
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

		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test'));
		$this->assertSame([true], $this->skipCacheArguments);
		$this->assertSame(600, $this->cached['refresh/talk-attachments-v2/https://nc1.test']['ttl'] ?? null);
	}

	public function testDocumentIsRefreshedAtMostOnceIn10Minutes(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test'));
		// In production the cache now holds the refreshed document; the mock keeps answering with the stale one
		$this->assertFalse($this->featureSupport->remoteSupportsUploads('https://NC1.test/'));
		$this->assertSame([true, false], $this->skipCacheArguments, 'Asked the server again only once');
	}

	public function testRefreshIsPerProtocol(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->featureSupport->remoteSupports('nc1.test'));
		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test'));
		$this->assertSame([true, true], $this->skipCacheArguments);
	}

	public function testRefreshAfter10Minutes(): void {
		$this->discoverWithStaleCache();

		$this->featureSupport->remoteSupportsUploads('nc1.test');
		unset($this->cached['refresh/talk-attachments-v2/https://nc1.test']); // expired
		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test'));
		$this->assertSame([true, true], $this->skipCacheArguments);
	}

	public function testLookupsSkippingTheCacheLeaveTheRefreshMarkerAlone(): void {
		$this->discoverWithStaleCache();

		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test', true));
		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test'));
		$this->assertSame([true, true], $this->skipCacheArguments);
	}

	public function testRemoteUnreachableIsUnknown(): void {
		$this->discoveryService->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull($this->featureSupport->remoteSupports('nc2.test'));
		$this->assertSame(300, $this->cached['https://nc2.test']['ttl'] ?? null);
	}

	public function testOtherFailureIsUnknown(): void {
		$this->discoveryService->method('discover')
			->willThrowException(new \RuntimeException('Something else'));

		$this->assertNull($this->featureSupport->remoteSupports('https://nc2.test/'));
		$this->assertArrayHasKey('https://nc2.test', $this->cached);
	}

	public function testUnknownRemoteIsNotAskedAgainForAWhile(): void {
		$this->discoveryService->expects($this->once())
			->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));

		$this->assertNull($this->featureSupport->remoteSupports('nc2.test'));
		// A user's next upload must not wait for the discovery timeouts again
		$this->assertNull($this->featureSupport->remoteSupports('NC2.test'));
	}

	public function testSkipCacheBypassesTheUnknownMarker(): void {
		$this->cached['https://nc2.test'] = ['value' => 1, 'ttl' => 300];
		$this->discoveryService->expects($this->once())
			->method('discover')
			->with('nc2.test', true)
			->willReturn($this->supportingProvider());

		$this->assertTrue($this->featureSupport->remoteSupports('nc2.test', true));
		$this->assertSame([], $this->cached, 'A definite answer clears the marker');
	}

	public function testRemoteSupportsUploads(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->expects($this->once())
			->method('extractProtocolEntry')
			->with('talk-room', 'talk-attachments-v2')
			->willReturn('/ocs/v2.php/apps/spreed/api/');
		$this->discoveryService->method('discover')->with('nc1.test', true)->willReturn($provider);

		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc1.test'));
	}

	public function testServerWithOnlyTheFirstProtocolDoesNotSupportUploads(): void {
		// Build 24.0.5.1: shows files, but has no endpoint for files of federated participants (ruling R10)
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->willReturnCallback(function (string $resource, string $protocol): string {
				if ($protocol !== 'talk-attachments-v1') {
					throw new OCMArgumentException($protocol);
				}
				return '/ocs/v2.php/apps/spreed/api/';
			});
		$this->discoveryService->method('discover')->willReturn($provider);

		$this->assertTrue($this->featureSupport->remoteSupports('nc1.test'));
		$this->assertFalse($this->featureSupport->remoteSupportsUploads('nc1.test'));
	}

	public function testUnreachableServerIsUnknownForUploadsToo(): void {
		$this->discoveryService->expects($this->once())
			->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));

		$this->assertNull($this->featureSupport->remoteSupportsUploads('nc1.test'));
		$this->assertNull($this->featureSupport->remoteSupports('nc1.test'), 'The unknown marker is per server');
	}
}
