<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Command\Federation;

use OCA\Talk\BackgroundJob\EnsureAttachmentShares;
use OCA\Talk\Command\Federation\ShareAttachments;
use OCA\Talk\Config;
use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

class ShareAttachmentsTest extends TestCase {
	protected Manager&MockObject $manager;
	protected AttachmentSharer&MockObject $sharer;
	protected IJobList&MockObject $jobList;
	protected CommandTester $tester;

	public function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(Manager::class);
		$this->sharer = $this->createMock(AttachmentSharer::class);
		$this->jobList = $this->createMock(IJobList::class);
		$config = $this->createMock(Config::class);
		$config->method('isFederationEnabled')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$command = new ShareAttachments(
			$this->manager,
			$this->sharer,
			$this->createMock(IDBConnection::class),
			$config,
			$this->jobList,
			$time,
		);
		$this->tester = new CommandTester($command);
	}

	public function testSharesOneConversation(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('wqhg8fxn');
		$room->method('getName')->willReturn('Twin Internet Technologies');
		$room->method('isFederatedConversation')->willReturn(false);
		$this->manager->method('getRoomByToken')->with('wqhg8fxn')->willReturn($room);
		$this->sharer->expects($this->once())->method('shareAllRoomShares')->with($room, null, true)->willReturn(true);

		$this->assertSame(0, $this->tester->execute(['token' => 'wqhg8fxn']));
		$this->assertStringContainsString('wqhg8fxn', $this->tester->getDisplay());
	}

	public function testFailureIsReportedAndRetried(): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(12);
		$room->method('getToken')->willReturn('wqhg8fxn');
		$room->method('isFederatedConversation')->willReturn(false);
		$this->manager->method('getRoomByToken')->willReturn($room);
		$this->sharer->method('shareAllRoomShares')->willReturn(false);
		$this->jobList->expects($this->once())
			->method('scheduleAfter')
			->with(EnsureAttachmentShares::class, 1000000 + 300, ['roomId' => 12, 'attempt' => 2]);

		$this->assertSame(1, $this->tester->execute(['token' => 'wqhg8fxn']));
	}
}
