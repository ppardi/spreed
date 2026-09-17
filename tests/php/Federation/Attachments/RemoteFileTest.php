<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\RemoteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

class RemoteFileTest extends TestCase {
	private const FILE = [
		'path' => 'photo.png',
		'name' => 'photo.png',
		'size' => 7855,
		'mimetype' => 'image/png',
		'etag' => 'e1',
		'fileId' => '88',
		'width' => 64,
		'height' => '64',
		'blurhash' => 'LKO2?U%2Tw=w]~RBVZRi};RPxuwH',
		'unexpected' => 'dropped',
	];

	public function testFromRequestKeepsOnlyKnownKeys(): void {
		$this->assertSame([
			'owner' => 'bill@nc2.test',
			'folderId' => '42',
			'path' => 'photo.png',
			'name' => 'photo.png',
			'size' => 7855,
			'mimetype' => 'image/png',
			'etag' => 'e1',
			'fileId' => '88',
			'width' => 64,
			'height' => 64,
			'blurhash' => 'LKO2?U%2Tw=w]~RBVZRi};RPxuwH',
		], RemoteFile::fromRequest('bill@nc2.test', '42', self::FILE));
	}

	public static function dataInvalidFile(): array {
		return [
			'folder id not numeric' => ['abc', []],
			'file id missing' => ['42', ['fileId' => null]],
			'file id not a string' => ['42', ['fileId' => 88]],
			'absolute path' => ['42', ['path' => '/photo.png']],
			'path leaves the folder' => ['42', ['path' => '../other/photo.png']],
			'empty path' => ['42', ['path' => '']],
			'empty name' => ['42', ['name' => '']],
			'name with slash' => ['42', ['name' => 'a/b.png']],
			'negative size' => ['42', ['size' => -1]],
			'float size' => ['42', ['size' => 1.5]],
			'bad mimetype' => ['42', ['mimetype' => 'image png']],
			'etag not a string' => ['42', ['etag' => ['x']]],
		];
	}

	#[DataProvider('dataInvalidFile')]
	public function testFromRequestRejectsInvalidData(string $folderId, array $override): void {
		$this->expectException(\InvalidArgumentException::class);
		RemoteFile::fromRequest('bill@nc2.test', $folderId, array_merge(self::FILE, $override));
	}

	public function testInvalidOptionalImageDetailsAreLeftOut(): void {
		$file = RemoteFile::fromRequest('bill@nc2.test', '42', array_merge(self::FILE, ['width' => 0, 'height' => 'x', 'blurhash' => '']));
		$this->assertArrayNotHasKey('width', $file);
		$this->assertArrayNotHasKey('height', $file);
		$this->assertArrayNotHasKey('blurhash', $file);
	}

	public function testIsValid(): void {
		$stored = RemoteFile::fromRequest('bill@nc2.test', '42', self::FILE);
		$this->assertTrue(RemoteFile::isValid($stored));
		// As read back from the message JSON
		$this->assertTrue(RemoteFile::isValid(json_decode(json_encode($stored), true)));
		$this->assertFalse(RemoteFile::isValid(array_merge($stored, ['path' => '../x'])));
		$this->assertFalse(RemoteFile::isValid(array_merge($stored, ['size' => '7855'])));
		$this->assertFalse(RemoteFile::isValid(array_merge($stored, ['fileId' => 88])));
		$this->assertFalse(RemoteFile::isValid('photo.png'));
	}

	public function testSourceIdDependsOnTheOwner(): void {
		$this->assertSame(40, strlen(RemoteFile::sourceId('bill@nc2.test', '42')));
		$this->assertSame(RemoteFile::sourceId('bill@nc2.test', '42'), RemoteFile::sourceId('bill@nc2.test', '42'));
		$this->assertNotSame(RemoteFile::sourceId('bill@nc2.test', '42'), RemoteFile::sourceId('carol@nc3.test', '42'));
	}
}
