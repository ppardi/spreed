<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\FileMessageSanitizer;
use Test\TestCase;

class FileMessageSanitizerTest extends TestCase {
	private const ACTOR = ['type' => 'user', 'id' => 'paul', 'name' => 'Paul'];

	public function testFileWithoutCaption(): void {
		$this->assertSame(
			['photo1.jpg', ['actor' => self::ACTOR]],
			FileMessageSanitizer::toPlainName('{file}', ['actor' => self::ACTOR, 'file' => ['type' => 'file', 'id' => '187', 'name' => 'photo1.jpg', 'link' => 'https://nc1.test/s/abc']]),
		);
	}

	public function testFileWithCaption(): void {
		$this->assertSame(
			["photo1.jpg\n\nLook at this", ['actor' => self::ACTOR]],
			FileMessageSanitizer::toPlainName('Look at this', ['actor' => self::ACTOR, 'file' => ['type' => 'federated-file', 'name' => 'photo1.jpg']]),
		);
	}

	public function testOtherMessagesAreUntouched(): void {
		$parameters = ['actor' => self::ACTOR, 'file' => ['type' => 'deck-card', 'name' => 'Card']];
		$this->assertSame(['{file}', $parameters], FileMessageSanitizer::toPlainName('{file}', $parameters));
		$this->assertSame(['Hello', ['actor' => self::ACTOR]], FileMessageSanitizer::toPlainName('Hello', ['actor' => self::ACTOR]));
	}
}
