<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Manager;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Share\Exceptions\ShareNotFound;
use Psr\Log\LoggerInterface;

/**
 * Shares conversation attachments with federated participants in the background:
 * history for a participant who just accepted the invitation, and retries after failures.
 */
class EnsureAttachmentShares extends QueuedJob {
	public const MAX_ATTEMPTS = 20;
	private const RETRY_DELAY = 300;

	public function __construct(
		ITimeFactory $time,
		private readonly Manager $manager,
		private readonly AttachmentSharer $sharer,
		private readonly RoomShareProvider $roomShareProvider,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{roomId: int, roomShareId?: string, cloudId?: string, attempt: int} $argument
	 */
	public static function schedule(IJobList $jobList, ITimeFactory $timeFactory, array $argument): void {
		$jobList->scheduleAfter(
			self::class,
			$timeFactory->getTime() + ($argument['attempt'] - 1) * self::RETRY_DELAY,
			$argument,
		);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$room = $this->manager->getRoomById((int)$argument['roomId']);
		} catch (RoomNotFoundException) {
			return;
		}

		$attempt = (int)$argument['attempt'];
		$cloudId = $argument['cloudId'] ?? null;
		// The first run is the backfill for a new participant: their server may have been upgraded recently
		$refreshDiscovery = $attempt === 1;
		try {
			if (isset($argument['roomShareId'])) {
				try {
					$roomShare = $this->roomShareProvider->getShareById((string)$argument['roomShareId']);
				} catch (ShareNotFound) {
					return;
				}
				$success = $this->sharer->shareRoomShare($room, $roomShare, $cloudId, $refreshDiscovery);
			} else {
				$success = $this->sharer->shareAllRoomShares($room, $cloudId, $refreshDiscovery);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Sharing conversation attachments failed, attempt ' . $attempt, ['exception' => $e]);
			$success = false;
		}

		if (!$success && $attempt < self::MAX_ATTEMPTS) {
			$argument['attempt'] = $attempt + 1;
			self::schedule($this->jobList, $this->time, $argument);
		}
	}
}
