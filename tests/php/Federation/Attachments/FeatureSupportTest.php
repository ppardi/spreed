<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\FeatureSupport;
use OCP\OCM\Exceptions\OCMArgumentException;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FeatureSupportTest extends TestCase {
	protected IOCMDiscoveryService&MockObject $discoveryService;
	protected FeatureSupport $featureSupport;

	public function setUp(): void {
		parent::setUp();
		$this->discoveryService = $this->createMock(IOCMDiscoveryService::class);
		$this->featureSupport = new FeatureSupport($this->discoveryService, $this->createMock(LoggerInterface::class));
	}

	public function testRemoteSupports(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->expects($this->once())
			->method('extractProtocolEntry')
			->with('talk-room', 'talk-attachments-v1')
			->willReturn('/ocs/v2.php/apps/spreed/api/');
		$this->discoveryService->expects($this->once())
			->method('discover')
			->with('nc2.test', false)
			->willReturn($provider);

		$this->assertTrue($this->featureSupport->remoteSupports('nc2.test'));
	}

	public function testRemoteWithoutProtocolEntry(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('extractProtocolEntry')
			->willThrowException(new OCMArgumentException('talk-attachments-v1'));
		$this->discoveryService->method('discover')->willReturn($provider);

		$this->assertFalse($this->featureSupport->remoteSupports('nc2.test'));
	}

	public function testRemoteUnreachable(): void {
		$this->discoveryService->method('discover')
			->willThrowException(new OCMProviderException('unreachable'));

		$this->assertFalse($this->featureSupport->remoteSupports('nc2.test'));
	}
}
