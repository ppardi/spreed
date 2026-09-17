<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\FileParameterBuilder;
use OCA\Talk\Share\Helper\FilesMetadataCache;
use OCP\Files\File;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\IPreview;
use OCP\IURLGenerator;
use Test\TestCase;

class FileParameterBuilderTest extends TestCase {
	public function testForLocalNodeUsesReferenceDimensionsAsFallback(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(188);
		$node->method('getName')->willReturn('photo1.jpg');
		$node->method('getPath')->willReturn('/bill/files/Talk/Room-both-64z86muv/Room-both-wqhg8fxn/photo1.jpg');
		$node->method('getSize')->willReturn(314468);
		$node->method('getEtag')->willReturn('etag-local');
		$node->method('getPermissions')->willReturn(17);
		$node->method('getMimeType')->willReturn('image/jpeg');

		$preview = $this->createMock(IPreview::class);
		$preview->method('isMimeSupported')->with('image/jpeg')->willReturn(true);
		$metadata = $this->createMock(FilesMetadataCache::class);
		$metadata->method('getImageMetadataForFileId')->with(188)->willThrowException(new FilesMetadataNotFoundException());
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['fileid' => 188])
			->willReturn('https://nc2.test/f/188');

		$builder = new FileParameterBuilder($preview, $metadata, $url);
		$this->assertSame([
			'type' => 'file',
			'id' => '188',
			'name' => 'photo1.jpg',
			'size' => '314468',
			'path' => 'Talk/Room-both-64z86muv/Room-both-wqhg8fxn/photo1.jpg',
			'link' => 'https://nc2.test/f/188',
			'etag' => 'etag-local',
			'permissions' => '17',
			'mimetype' => 'image/jpeg',
			'preview-available' => 'yes',
			'hide-download' => 'no',
			'width' => '1600',
			'height' => '1600',
		], $builder->forLocalNode($node, ['width' => '1600', 'height' => '1600']));
	}
}
