<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Command\Room;

use OC\Core\Command\Base;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Service\ParticipantService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows each attendee's read marker and read privacy, and says who is holding the conversation's
 * common read marker back.
 *
 * A read receipt appears only when every attendee who shares their read status has read the
 * message, so the conversation's marker is a MIN over those attendees. One participant who never
 * reads - an account with no client, typically - pins that marker and silently removes read
 * receipts for everyone else. Nothing in the API or the interface says which participant that is;
 * only the MIN is exposed, and an aggregate cannot name the input that produced it. Finding one
 * took a manual database query once, which is why this exists.
 */
class Participants extends Base {
	public function __construct(
		private readonly Manager $manager,
		private readonly ParticipantService $participantService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		parent::configure();
		$this
			->setName('talk:room:participants')
			->setDescription('List a conversation\'s attendees with their read marker and read privacy')
			->addArgument(
				'token',
				InputArgument::REQUIRED,
				'Token of the room to list participants of'
			);
	}

	/**
	 * Which attendees the common read marker is computed over, and which of them are holding it
	 * back. Mirrors ParticipantService::getLastCommonReadChatMessage(): users and federated users
	 * whose read privacy is public.
	 *
	 * @param list<array{actor_type: string, read_privacy: int, last_read_message: int, ...}> $attendees
	 * @return array{counted: list<int>, common: ?int}
	 */
	public static function readMarkerReport(array $attendees): array {
		$counted = [];
		foreach ($attendees as $index => $attendee) {
			if ($attendee['read_privacy'] !== Participant::PRIVACY_PUBLIC) {
				continue;
			}
			if ($attendee['actor_type'] !== Attendee::ACTOR_USERS
				&& $attendee['actor_type'] !== Attendee::ACTOR_FEDERATED_USERS) {
				continue;
			}
			$counted[$index] = $attendee['last_read_message'];
		}

		if ($counted === []) {
			return ['counted' => [], 'common' => null];
		}

		return ['counted' => array_keys($counted), 'common' => min($counted)];
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$token = $input->getArgument('token');

		try {
			$room = $this->manager->getRoomByToken($token);
		} catch (RoomNotFoundException) {
			$output->writeln('<error>Room not found.</error>');
			return 1;
		}

		$rows = [];
		foreach ($this->participantService->getParticipantsForRoom($room) as $participant) {
			$attendee = $participant->getAttendee();
			$rows[] = [
				'actor_type' => $attendee->getActorType(),
				'actor_id' => $attendee->getActorId(),
				'display_name' => $attendee->getDisplayName(),
				'last_read_message' => $attendee->getLastReadMessage(),
				'read_privacy' => $attendee->getReadPrivacy(),
			];
		}

		$report = self::readMarkerReport($rows);
		$plain = $input->getOption('output') === 'plain';

		foreach ($rows as $index => $row) {
			$counted = in_array($index, $report['counted'], true);
			$pinning = $counted && $report['common'] !== null && $row['last_read_message'] === $report['common'];

			$rows[$index]['read_privacy'] = $row['read_privacy'] === Participant::PRIVACY_PUBLIC ? 'public' : 'private';
			$rows[$index]['counted'] = $counted ? 'yes' : 'no';
			$rows[$index]['holding_marker'] = $pinning ? ($plain ? '<error>yes</error>' : 'yes') : '';
		}

		$this->writeTableInOutputFormat($input, $output, $rows);

		$lastMessage = $room->getLastMessageId();
		if ($plain) {
			$output->writeln('');
			$output->writeln('last message in the conversation: ' . $lastMessage);
			if ($report['common'] === null) {
				$output->writeln('common read marker: none - nobody here shares their read status, so no read receipts are shown');
			} else {
				$output->writeln('common read marker: ' . $report['common']
					. ' (a read receipt is shown only on messages up to this id)');
				if ($report['common'] < $lastMessage) {
					$output->writeln('<comment>The attendees marked in holding_marker have not read past '
						. $report['common'] . ', which is why newer messages show no read receipt. For a person that is '
						. 'simply unread mail and resolves itself. An account with no client - a bot or an agent - never '
						. 'advances its marker and will hold this forever: set its read privacy to private to leave it '
						. 'out of the calculation.</comment>');
				}
			}
		}

		return 0;
	}
}
