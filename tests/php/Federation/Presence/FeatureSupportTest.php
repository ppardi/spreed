<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Presence;

use OCA\Talk\Federation\Presence\FeatureSupport;
use OCA\Talk\Federation\RemoteProtocolSupport;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class FeatureSupportTest extends TestCase {
	protected RemoteProtocolSupport&MockObject $protocolSupport;
	protected FeatureSupport $featureSupport;

	public function setUp(): void {
		parent::setUp();
		$this->protocolSupport = $this->createMock(RemoteProtocolSupport::class);
		$this->featureSupport = new FeatureSupport($this->protocolSupport);
	}

	public function testRemoteSupportsAsksForThePresenceProtocol(): void {
		$this->protocolSupport->expects($this->once())
			->method('supports')
			->with('https://remote.test', 'talk-presence-v1', false)
			->willReturn(true);

		$this->assertTrue($this->featureSupport->remoteSupports('https://remote.test'));
	}

	public function testUnknownIsPassedThroughAsNull(): void {
		$this->protocolSupport->method('supports')->willReturn(null);

		$this->assertNull($this->featureSupport->remoteSupports('https://remote.test'));
	}
}
