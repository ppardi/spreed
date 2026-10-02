<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\FederatedFileReference;
use Test\TestCase;

class FederatedFileReferenceTest extends TestCase {
	public function testForShareKeepsOnlyDisplayData(): void {
		$hostFile = [
			'type' => 'file',
			'id' => '187',
			'name' => 'photo1.jpg',
			'size' => '314468',
			'path' => 'photo1.jpg',
			'link' => '',
			'etag' => 'abc',
			'permissions' => '27',
			'mimetype' => 'image/jpeg',
			'preview-available' => 'yes',
			'hide-download' => 'no',
			'width' => '1600',
			'height' => '1600',
		];

		$this->assertSame([
			'type' => 'federated-file',
			'name' => 'photo1.jpg',
			'size' => '314468',
			'mimetype' => 'image/jpeg',
			'etag' => 'abc',
			'preview-available' => 'yes',
			'width' => '1600',
			'height' => '1600',
			'server' => 'https://nc1.test/',
			'share-id' => '6',
			'path' => 'photo1.jpg',
		], FederatedFileReference::forShare($hostFile, 'https://nc1.test/', '6', 'photo1.jpg'));
	}

	public function testIsReference(): void {
		$reference = FederatedFileReference::forShare(['name' => 'a.png'], 'https://nc1.test', '6', '');
		$this->assertTrue(FederatedFileReference::isReference($reference));
		$this->assertTrue(FederatedFileReference::isReference(FederatedFileReference::forOwnFile(['name' => 'a.png'], 'https://nc2.test', '88')));
		$this->assertFalse(FederatedFileReference::isReference(['type' => 'file', 'name' => 'a.png']));
		$this->assertFalse(FederatedFileReference::isReference(['type' => 'federated-file', 'name' => 'a.png']));
		$this->assertFalse(FederatedFileReference::isReference(null));
	}

	public function testForOwnFile(): void {
		$this->assertSame([
			'type' => 'federated-file',
			'name' => 'photo.png',
			'mimetype' => 'image/png',
			'server' => 'https://nc2.test',
			'file-id' => '88',
		], FederatedFileReference::forOwnFile(['id' => '1', 'name' => 'photo.png', 'mimetype' => 'image/png'], 'https://nc2.test', '88'));
	}

	public function testForDisplayCanNotBeResolved(): void {
		$reference = FederatedFileReference::forDisplay(['id' => '1', 'name' => 'photo.png', 'size' => '7855'], 'https://nc2.test');
		$this->assertSame([
			'type' => 'federated-file',
			'name' => 'photo.png',
			'size' => '7855',
			'server' => 'https://nc2.test',
		], $reference);
		$this->assertFalse(FederatedFileReference::isReference($reference));
	}

	/**
	 * The signaling relay broadcasts one payload to every participant, so the reference it carries
	 * is the id-less `forDisplay()` variant that no client can resolve. `isReference()` rejects that
	 * variant for having no ids, so detection has to go by type alone.
	 */
	public function testIsInParametersFindsTheUnresolvableDisplayVariant(): void {
		$parameters = [
			'actor' => ['type' => 'user', 'id' => 'paulp', 'name' => 'Paul'],
			'file' => FederatedFileReference::forDisplay(['name' => 'photo.png', 'size' => '7855'], 'https://nc2.test'),
		];

		$this->assertFalse(FederatedFileReference::isReference($parameters['file']),
			'guard against the display variant ever becoming resolvable without this test being revisited');
		$this->assertTrue(FederatedFileReference::isInParameters($parameters));
	}

	public function testIsInParametersFindsAResolvableReference(): void {
		$parameters = [
			'file' => FederatedFileReference::forOwnFile(['name' => 'photo.png'], 'https://nc2.test', '87514'),
		];

		$this->assertTrue(FederatedFileReference::isInParameters($parameters));
	}

	public function testIsInParametersIgnoresAnOrdinaryFile(): void {
		$parameters = [
			'actor' => ['type' => 'user', 'id' => 'paulp', 'name' => 'Paul'],
			'file' => ['type' => 'file', 'id' => '87514', 'name' => 'photo.png'],
		];

		$this->assertFalse(FederatedFileReference::isInParameters($parameters));
		$this->assertFalse(FederatedFileReference::isInParameters([]));
	}

	public function testIsInParametersSurvivesMalformedParameters(): void {
		$this->assertFalse(FederatedFileReference::isInParameters(['file' => 'not-an-array', 'other' => 7]));
	}
}
