<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\FeatureSupport;
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

	public function testRemoteSupportsAsksForTheFirstProtocol(): void {
		$this->protocolSupport->expects($this->once())
			->method('supports')
			->with('nc2.test', 'talk-attachments-v1', false)
			->willReturn(true);

		$this->assertTrue($this->featureSupport->remoteSupports('nc2.test'));
	}

	public function testRemoteSupportsUploadsAsksForTheSecondProtocol(): void {
		$this->protocolSupport->expects($this->once())
			->method('supports')
			->with('nc2.test', 'talk-attachments-v2', false)
			->willReturn(true);

		$this->assertTrue($this->featureSupport->remoteSupportsUploads('nc2.test'));
	}

	public function testSkipCacheIsPassedThrough(): void {
		$this->protocolSupport->expects($this->once())
			->method('supports')
			->with('nc2.test', 'talk-attachments-v1', true)
			->willReturn(false);

		$this->assertFalse($this->featureSupport->remoteSupports('nc2.test', true));
	}

	public function testUnknownStaysUnknown(): void {
		$this->protocolSupport->method('supports')->willReturn(null);

		// Unknown must not collapse into false: callers back off on null and give up on false
		$this->assertNull($this->featureSupport->remoteSupports('nc2.test'));
		$this->assertNull($this->featureSupport->remoteSupportsUploads('nc2.test'));
	}
}
