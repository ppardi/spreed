<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php;

use OCA\Talk\Authenticator;
use OCA\Talk\TalkSession;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

class AuthenticatorTest extends TestCase {
	public static function dataSupportsFederatedAttachments(): array {
		return [
			'federation request with header' => [['x-nextcloud-federation' => 'true', 'X-Nextcloud-Talk-Federated-Attachments' => '1'], true],
			'federation request without header' => [['x-nextcloud-federation' => 'true'], false],
			'header without federation' => [['X-Nextcloud-Talk-Federated-Attachments' => '1'], false],
		];
	}

	#[DataProvider('dataSupportsFederatedAttachments')]
	public function testSupportsFederatedAttachments(array $headers, bool $expected): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')
			->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');

		$cloudId = $this->createMock(ICloudId::class);
		$cloudId->method('getId')->willReturn('bill@nc2.test');
		$cloudIdManager = $this->createMock(ICloudIdManager::class);
		$cloudIdManager->method('resolveCloudId')->willReturn($cloudId);

		$authenticator = new Authenticator($request, $cloudIdManager, $this->createMock(TalkSession::class));
		$this->assertSame($expected, $authenticator->supportsFederatedAttachments());
	}
}
