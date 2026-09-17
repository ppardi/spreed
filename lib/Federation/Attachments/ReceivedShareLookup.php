<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Files_Sharing\External\Manager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and removes federated shares received by a user.
 *
 * files_sharing has no public API to look up a received share by the sender's share id, so this reads its
 * `share_external` table directly, and removes shares through its internal `External\Manager` (ruling R9).
 * Keep all such access in this class so it can be swapped for an API later.
 */
class ReceivedShareLookup {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $server Server that created the share (any URL shape)
	 * @param string $remoteShareId Id of the share on that server
	 * @return string|null Mount point relative to the user's files, e.g. "/Room-both-wqhg8fxn"
	 */
	public function findAccepted(string $userId, string $server, string $remoteShareId): ?string {
		$row = $this->findRow($userId, $server, $remoteShareId, true);
		return $row === null ? null : $row['mountpoint'];
	}

	/**
	 * Removes a received share, accepted or still pending, as removing it in Files would: the sending server is
	 * notified and deletes its share. Never throws.
	 *
	 * @return bool Whether a share was removed
	 */
	public function decline(string $userId, string $server, string $remoteShareId): bool {
		try {
			$row = $this->findRow($userId, $server, $remoteShareId, false);
			$user = $this->userManager->get($userId);
			if ($row === null || $user === null) {
				return false;
			}

			// Loaded only when needed: most requests never remove a share
			/** @var Manager $manager */
			$manager = $this->container->get(Manager::class);
			$share = $manager->getShare($row['id'], $user);
			return $share !== false && $manager->declineShare($share, $user);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove the federated share ' . $remoteShareId . ' from ' . $server . ' received by ' . $userId, ['exception' => $e]);
			return false;
		}
	}

	/**
	 * @return array{id: string, mountpoint: string}|null
	 */
	private function findRow(string $userId, string $server, string $remoteShareId, bool $acceptedOnly): ?array {
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'remote', 'mountpoint')
			->from('share_external')
			->where($query->expr()->eq('user', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('remote_id', $query->createNamedParameter($remoteShareId)))
			->andWhere($query->expr()->eq('share_type', $query->createNamedParameter(IShare::TYPE_USER, IQueryBuilder::PARAM_INT)));
		if ($acceptedOnly) {
			$query->andWhere($query->expr()->eq('accepted', $query->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}

		$result = $query->executeQuery();
		try {
			while ($row = $result->fetchAssociative()) {
				if (ServerUrl::equals((string)$row['remote'], $server)) {
					return ['id' => (string)$row['id'], 'mountpoint' => (string)$row['mountpoint']];
				}
			}
		} finally {
			$result->closeCursor();
		}
		return null;
	}
}
