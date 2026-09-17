<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Config;
use OCA\Talk\Federation\Attachments\ConversationFolder;
use OCA\Talk\Room;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ConversationFolderTest extends TestCase {
	protected Config&MockObject $config;
	protected IUserSession&MockObject $userSession;
	protected Room&MockObject $room;
	protected ConversationFolder $folder;

	public function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
		$this->config->method('getAttachmentFolder')->with('bill')->willReturn('/Talk');
		$this->config->method('getConversationFolderName')->willReturn('Room-both-64z86muv');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->room = $this->createMock(Room::class);
		$this->folder = new ConversationFolder($this->config, $this->userSession);
	}

	private function loginAs(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testInTheViewersOwnRequest(): void {
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(true);
		$this->loginAs('bill');
		$this->assertSame('Talk/Room-both-64z86muv', $this->folder->targetForViewer($this->room, 'bill'));
	}

	public function testNotInSomeoneElsesRequest(): void {
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(true);
		$this->loginAs('paul');
		$this->assertNull($this->folder->targetForViewer($this->room, 'bill'));
	}

	public function testNotWithoutSession(): void {
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(true);
		$this->loginAs(null); // e.g. OCM notifications, cron
		$this->assertNull($this->folder->targetForViewer($this->room, 'bill'));
	}

	public function testNotWhenConversationSubfoldersAreDisabled(): void {
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(false);
		$this->config->expects($this->never())->method('getConversationFolderName');
		$this->loginAs('bill');
		$this->assertNull($this->folder->targetForViewer($this->room, 'bill'));
	}
}
