<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\BackgroundJob;

use OCA\Talk\BackgroundJob\EnsureAttachmentShares;
use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class EnsureAttachmentSharesTest extends TestCase {
	protected AttachmentSharer&MockObject $sharer;
	protected RoomShareProvider&MockObject $roomShareProvider;
	protected IJobList&MockObject $jobList;
	protected EnsureAttachmentShares $job;

	public function setUp(): void {
		parent::setUp();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('wqhg8fxn');
		$manager = $this->createMock(Manager::class);
		$manager->method('getRoomById')->with(12)->willReturn($room);
		$this->sharer = $this->createMock(AttachmentSharer::class);
		$this->roomShareProvider = $this->createMock(RoomShareProvider::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->job = new EnsureAttachmentShares($time, $manager, $this->sharer, $this->roomShareProvider, $this->jobList, $this->createMock(LoggerInterface::class));
	}

	public function testBackfillForNewParticipantRefreshesDiscovery(): void {
		$this->sharer->expects($this->once())
			->method('shareAllRoomShares')
			->with($this->anything(), 'bill@nc2.test', true)
			->willReturn(true);
		$this->jobList->expects($this->never())->method('scheduleAfter');
		self::invokePrivate($this->job, 'run', [['roomId' => 12, 'cloudId' => 'bill@nc2.test', 'attempt' => 1]]);
	}

	public function testRetryIsScheduledWithBackoff(): void {
		$roomShare = $this->createMock(IShare::class);
		$roomShare->method('getSharedWith')->willReturn('wqhg8fxn');
		$this->roomShareProvider->method('getShareById')->with('5')->willReturn($roomShare);
		$this->sharer->method('shareRoomShare')->with($this->anything(), $roomShare, null, false)->willReturn(false);
		$this->jobList->expects($this->once())
			->method('scheduleAfter')
			->with(EnsureAttachmentShares::class, 1000000 + 2 * 300, ['roomId' => 12, 'roomShareId' => '5', 'attempt' => 3]);
		self::invokePrivate($this->job, 'run', [['roomId' => 12, 'roomShareId' => '5', 'attempt' => 2]]);
	}

	public function testShareOfAnotherRoomIsIgnored(): void {
		$roomShare = $this->createMock(IShare::class);
		$roomShare->method('getSharedWith')->willReturn('otherroom');
		$this->roomShareProvider->method('getShareById')->with('5')->willReturn($roomShare);
		$this->sharer->expects($this->never())->method('shareRoomShare');
		$this->jobList->expects($this->never())->method('scheduleAfter');
		self::invokePrivate($this->job, 'run', [['roomId' => 12, 'roomShareId' => '5', 'attempt' => 2]]);
	}

	public function testExceptionCountsAsFailure(): void {
		$this->sharer->method('shareAllRoomShares')->willThrowException(new \RuntimeException('provider missing'));
		$this->jobList->expects($this->once())->method('scheduleAfter');
		self::invokePrivate($this->job, 'run', [['roomId' => 12, 'attempt' => 1]]);
	}

	public function testGivesUpAfterMaxAttempts(): void {
		$this->sharer->method('shareAllRoomShares')->willReturn(false);
		$this->jobList->expects($this->never())->method('scheduleAfter');
		self::invokePrivate($this->job, 'run', [['roomId' => 12, 'attempt' => EnsureAttachmentShares::MAX_ATTEMPTS]]);
	}
}
