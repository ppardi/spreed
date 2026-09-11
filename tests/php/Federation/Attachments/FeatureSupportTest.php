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
			->with('nc2.test', false)
			->willReturn($this->supportingProvider());

		$this->assertTrue($this->featureSupport->remoteSupports('nc2.test'));
		$this->assertSame([], $this->cached);
	}

	public function testRemoteWithoutProtocolEntry(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->willThrowException(new OCMArgumentException('talk-attachments-v1'));
		$this->discoveryService->method('discover')->willReturn($provider);

		$this->assertFalse($this->featureSupport->remoteSupports('nc2.test'));
		$this->assertSame([], $this->cached);
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
}
