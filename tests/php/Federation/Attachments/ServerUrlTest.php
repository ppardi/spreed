<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\ServerUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

class ServerUrlTest extends TestCase {
	public static function dataNormalize(): array {
		return [
			'trailing slash (share_external.remote)' => ['https://nc1.test/', 'https://nc1.test'],
			'no scheme (cloud id remote)' => ['nc1.test', 'https://nc1.test'],
			'already normalized (talk_rooms.remote_server)' => ['https://nc1.test', 'https://nc1.test'],
			'case, port and sub folder' => ['HTTPS://NC1.Test:8443/cloud/', 'https://nc1.test:8443/cloud'],
			'plain http is kept' => ['http://localhost:8080/', 'http://localhost:8080'],
		];
	}

	#[DataProvider('dataNormalize')]
	public function testNormalize(string $input, string $expected): void {
		$this->assertSame($expected, ServerUrl::normalize($input));
	}

	public function testEquals(): void {
		$this->assertTrue(ServerUrl::equals('https://nc1.test/', 'nc1.test'));
		$this->assertFalse(ServerUrl::equals('https://nc1.test', 'https://nc2.test'));
		$this->assertFalse(ServerUrl::equals('http://nc1.test', 'https://nc1.test'));
	}
}
