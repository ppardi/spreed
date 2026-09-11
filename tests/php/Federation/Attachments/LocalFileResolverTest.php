<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Federation\Attachments\LocalFileResolver;
use OCA\Talk\Federation\Attachments\ReceivedShareLookup;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class LocalFileResolverTest extends TestCase {
	protected ReceivedShareLookup&MockObject $lookup;
	protected IRootFolder&MockObject $rootFolder;
	protected Folder&MockObject $userFolder;
	protected IConfig&MockObject $config;
	protected LocalFileResolver $resolver;

	public function setUp(): void {
		parent::setUp();
		$this->lookup = $this->createMock(ReceivedShareLookup::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->userFolder->method('getPath')->willReturn('/bill/files');
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with('bill')->willReturn($this->userFolder);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueBool')->with('sharing.allow_custom_share_folder', true)->willReturn(true);
		$this->config->method('getSystemValueString')->with('share_folder', '/')->willReturn('/');
		$this->config->method('getUserValue')->with('bill', 'files_sharing', 'share_folder', '/')->willReturn('/');
		$this->resolver = new LocalFileResolver($this->lookup, $this->rootFolder, $this->config, $this->createMock(LoggerInterface::class));
	}

	public function testNoShare(): void {
		$this->lookup->method('findAccepted')->with('bill', 'https://nc1.test/', '6')->willReturn(null);
		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
	}

	public function testResolvesInsideMountWithoutMoving(): void {
		$file = $this->createMock(File::class);
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');
		$this->userFolder->method('get')->with('Room-both-wqhg8fxn/photo1.jpg')->willReturn($file);

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
	}

	public function testSingleFileShare(): void {
		$file = $this->createMock(File::class);
		$this->lookup->method('findAccepted')->willReturn('/smoke.png');
		$this->userFolder->method('get')->with('smoke.png')->willReturn($file);

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '9', '', null));
	}

	public function testMovesMountFromShareFolderIntoTargetFolder(): void {
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');

		$mountRoot = $this->createMock(Folder::class);
		$mountRoot->method('getName')->willReturn('Room-both-wqhg8fxn');
		$mountRoot->expects($this->once())
			->method('move')
			->with('/bill/files/Talk/Room-both-64z86muv/Room-both-wqhg8fxn');
		$talk = $this->createMock(Folder::class);
		$target = $this->createMock(Folder::class);
		$target->method('getPath')->willReturn('/bill/files/Talk/Room-both-64z86muv');
		$target->method('getNonExistingName')->with('Room-both-wqhg8fxn')->willReturn('Room-both-wqhg8fxn');
		$file = $this->createMock(File::class);

		$this->userFolder->method('get')->willReturnCallback(fn (string $path) => match ($path) {
			'Room-both-wqhg8fxn' => $mountRoot,
			'Talk' => $talk,
			'Talk/Room-both-64z86muv/Room-both-wqhg8fxn/photo1.jpg' => $file,
			default => throw new NotFoundException($path),
		});
		$talk->method('get')->with('Room-both-64z86muv')->willThrowException(new NotFoundException());
		$talk->expects($this->once())->method('newFolder')->with('Room-both-64z86muv')->willReturn($target);

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', 'Talk/Room-both-64z86muv'));
	}

	public function testShareTheUserMovedElsewhereIsLeftAlone(): void {
		$file = $this->createMock(File::class);
		$this->lookup->method('findAccepted')->willReturn('/Projects/Room-both-wqhg8fxn');
		$this->userFolder->method('get')->with('Projects/Room-both-wqhg8fxn/photo1.jpg')->willReturn($file);

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', 'Talk/Room-both-64z86muv'));
	}

	public function testFailedMoveKeepsTheShareUsable(): void {
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');
		$mountRoot = $this->createMock(Folder::class);
		$mountRoot->method('getName')->willReturn('Room-both-wqhg8fxn');
		// e.g. External\Manager without a session user: "Call to a member function getUID() on null"
		$mountRoot->method('move')->willThrowException(new \Error('Call to a member function getUID() on null'));
		$target = $this->createMock(Folder::class);
		$target->method('getPath')->willReturn('/bill/files/Talk');
		$target->method('getNonExistingName')->willReturn('Room-both-wqhg8fxn');
		$file = $this->createMock(File::class);

		$this->userFolder->method('get')->willReturnCallback(fn (string $path) => match ($path) {
			'Room-both-wqhg8fxn' => $mountRoot,
			'Talk' => $target,
			'Room-both-wqhg8fxn/photo1.jpg' => $file,
			default => throw new NotFoundException($path),
		});

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', 'Talk'));
	}

	public function testMissingFileInsideShare(): void {
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');
		$this->userFolder->method('get')->willThrowException(new NotFoundException());
		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'deleted.jpg', null));
	}
}
