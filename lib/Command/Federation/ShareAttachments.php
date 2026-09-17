<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Command\Federation;

use OC\Core\Command\Base;
use OCA\Talk\BackgroundJob\EnsureAttachmentShares;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Invitation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shares attachments of conversations that existed before federated attachments were enabled
 */
class ShareAttachments extends Base {
	public function __construct(
		private readonly Manager $manager,
		private readonly AttachmentSharer $sharer,
		private readonly IDBConnection $db,
		private readonly Config $talkConfig,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $timeFactory,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('talk:federation:share-attachments')
			->setDescription('Shares the existing attachments of conversations with their federated participants')
			->addArgument(
				'token',
				InputArgument::OPTIONAL,
				'Token of one conversation (default: all conversations with federated participants)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->talkConfig->isFederationEnabled()) {
			$output->writeln('<error>Federation is disabled.</error>');
			return 1;
		}

		$token = $input->getArgument('token');
		if (is_string($token) && $token !== '') {
			try {
				$rooms = [$this->manager->getRoomByToken($token)];
			} catch (RoomNotFoundException) {
				$output->writeln('<error>Room not found.</error>');
				return 1;
			}
		} else {
			$rooms = [];
			foreach ($this->getRoomIdsWithFederatedParticipants() as $roomId) {
				try {
					$rooms[] = $this->manager->getRoomById($roomId);
				} catch (RoomNotFoundException) {
				}
			}
		}

		$failed = 0;
		foreach ($rooms as $room) {
			if ($room->isFederatedConversation()) {
				continue;
			}
			try {
				$success = $this->sharer->shareAllRoomShares($room, null, true);
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				$success = false;
			}
			$output->writeln(($success ? '<info>shared</info>  ' : '<error>failed</error>  ') . $room->getToken() . '  ' . $room->getName());
			if (!$success) {
				$failed++;
				EnsureAttachmentShares::schedule($this->jobList, $this->timeFactory, ['roomId' => $room->getId(), 'attempt' => 2]);
			}
		}

		if ($failed > 0) {
			$output->writeln($failed . ' conversation(s) failed, retrying in the background.');
			return 1;
		}
		return 0;
	}

	/**
	 * @return list<int>
	 */
	private function getRoomIdsWithFederatedParticipants(): array {
		$query = $this->db->getQueryBuilder();
		$query->selectDistinct('room_id')
			->from('talk_attendees')
			->where($query->expr()->eq('actor_type', $query->createNamedParameter(Attendee::ACTOR_FEDERATED_USERS)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(Invitation::STATE_ACCEPTED, IQueryBuilder::PARAM_INT)));

		$roomIds = [];
		$result = $query->executeQuery();
		while ($row = $result->fetchAssociative()) {
			$roomIds[] = (int)$row['room_id'];
		}
		$result->closeCursor();
		return $roomIds;
	}
}
