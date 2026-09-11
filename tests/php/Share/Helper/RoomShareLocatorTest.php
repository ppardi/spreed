<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Share\Helper;

use OCA\Talk\Room;
use OCA\Talk\Share\Helper\RoomShareLocator;
use OCA\Talk\Share\RoomShareProvider;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\IShare;
use Test\TestCase;

class RoomShareLocatorTest extends TestCase {
	public function testFindsShareOnGrandParent(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('abc123');

		$shared = $this->createMock(Folder::class);
		$shared->method('getPath')->willReturn('/paul/files/Talk/Room-abc123/Paul-paul');
		$sub = $this->createMock(Folder::class);
		$sub->method('getParent')->willReturn($shared);
		$node = $this->createMock(Node::class);
		$node->method('getParent')->willReturn($sub);
		$node->method('getPath')->willReturn('/paul/files/Talk/Room-abc123/Paul-paul/sub/photo1.jpg');

		$otherShare = $this->createMock(IShare::class);
		$otherShare->method('getSharedWith')->willReturn('otherroom');
		$roomShare = $this->createMock(IShare::class);
		$roomShare->method('getSharedWith')->willReturn('abc123');

		$provider = $this->createMock(RoomShareProvider::class);
		$provider->method('getSharesByPath')
			->willReturnCallback(fn (Node $path): array => $path === $shared ? [$otherShare, $roomShare] : []);

		$locator = new RoomShareLocator($provider);
		$this->assertSame([$roomShare, 'sub/photo1.jpg'], $locator->findForNode($room, $node));
	}

	public function testNoShare(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('abc123');
		$folder = $this->createMock(Folder::class);
		$folder->method('getParent')->willReturn($folder);
		$node = $this->createMock(Node::class);
		$node->method('getParent')->willReturn($folder);

		$provider = $this->createMock(RoomShareProvider::class);
		$provider->method('getSharesByPath')->willReturn([]);

		$locator = new RoomShareLocator($provider);
		$this->assertSame([null, ''], $locator->findForNode($room, $node));
	}
}
