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
use OCP\Files\ISetupManager;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class LocalFileResolverTest extends TestCase {
	protected ReceivedShareLookup&MockObject $lookup;
	protected IRootFolder&MockObject $rootFolder;
	protected Folder&MockObject $userFolder;
	protected IConfig&MockObject $config;
	protected ISetupManager&MockObject $setupManager;
	protected IUserManager&MockObject $userManager;
	protected LoggerInterface&MockObject $logger;
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
		$this->setupManager = $this->createMock(ISetupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new LocalFileResolver($this->lookup, $this->rootFolder, $this->config, $this->setupManager, $this->userManager, $this->logger);
	}

	public function testNoShare(): void {
		$this->lookup->method('findAccepted')->with('bill', 'https://nc1.test/', '6')->willReturn(null);
		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
	}

	public function testResolvesInsideMountWithoutMoving(): void {
		$file = $this->createMock(File::class);
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');
		$this->userFolder->method('get')->with('Room-both-wqhg8fxn/photo1.jpg')->willReturn($file);
		$this->setupManager->expects($this->never())->method('tearDown');
		$this->setupManager->expects($this->never())->method('setupForUser');

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
	}

	public function testRefreshesMountsOnceWhenFileNotFoundYet(): void {
		$file = $this->createMock(File::class);
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');

		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bill')->willReturn($user);
		$this->setupManager->expects($this->once())->method('tearDown');
		$this->setupManager->expects($this->once())->method('setupForUser')->with($user);

		$calls = 0;
		$this->userFolder->method('get')
			->with('Room-both-wqhg8fxn/photo1.jpg')
			->willReturnCallback(function () use (&$calls, $file) {
				$calls++;
				if ($calls === 1) {
					throw new NotFoundException();
				}
				return $file;
			});

		$this->assertSame($file, $this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
	}

	public function testStillMissingAfterRefreshReturnsNull(): void {
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');

		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bill')->willReturn($user);
		$this->setupManager->expects($this->once())->method('tearDown');
		$this->setupManager->expects($this->once())->method('setupForUser')->with($user);

		$this->userFolder->method('get')->willThrowException(new NotFoundException());

		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'deleted.jpg', null));
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

	public function testSetupForUserThrowingReturnsNullWithoutThrowing(): void {
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');

		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bill')->willReturn($user);
		$this->setupManager->expects($this->once())->method('tearDown');
		$this->setupManager->expects($this->once())->method('setupForUser')->with($user)
			->willThrowException(new \RuntimeException('Could not re-setup'));

		$calls = 0;
		$this->userFolder->method('get')
			->with('Room-both-wqhg8fxn/photo1.jpg')
			->willReturnCallback(function () use (&$calls) {
				$calls++;
				throw new NotFoundException();
			});

		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
		$this->assertSame(1, $calls, 'get() should be called exactly once; after refresh attempt fails, no retry');
	}

	public function testMountsAreRefreshedOnlyOncePerUser(): void {
		// e.g. several messages whose files were deleted inside a folder that is still shared
		$this->lookup->method('findAccepted')->willReturn('/Room-both-wqhg8fxn');
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bill')->willReturn($user);
		$this->setupManager->expects($this->once())->method('tearDown');
		$this->setupManager->expects($this->once())->method('setupForUser')->with($user);
		$this->userFolder->method('get')->willThrowException(new NotFoundException());

		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'deleted1.jpg', null));
		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'deleted2.jpg', null));
	}

	public function testPathLeavingTheShareIsRejected(): void {
		$this->lookup->expects($this->never())->method('findAccepted');
		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', '../Documents/secret.txt', null));
	}

	public function testUnexpectedFailuresAreLogged(): void {
		$this->lookup->method('findAccepted')->willThrowException(new \RuntimeException('Remote storage unavailable'));
		$this->logger->expects($this->once())->method('info');

		$this->assertNull($this->resolver->resolve('bill', 'https://nc1.test/', '6', 'photo1.jpg', null));
	}
}
