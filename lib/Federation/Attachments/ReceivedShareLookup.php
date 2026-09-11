<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;

/**
 * Reads federated shares received by a user.
 *
 * files_sharing has no public API to look up a received share by the sender's share id, so this reads its
 * `share_external` table directly. Keep all such access in this class so it can be swapped for an API later.
 */
class ReceivedShareLookup {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param string $server Server that created the share (any URL shape)
	 * @param string $remoteShareId Id of the share on that server
	 * @return string|null Mount point relative to the user's files, e.g. "/Room-both-wqhg8fxn"
	 */
	public function findAccepted(string $userId, string $server, string $remoteShareId): ?string {
		$query = $this->db->getQueryBuilder();
		$query->select('remote', 'mountpoint')
			->from('share_external')
			->where($query->expr()->eq('user', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('remote_id', $query->createNamedParameter($remoteShareId)))
			->andWhere($query->expr()->eq('share_type', $query->createNamedParameter(IShare::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('accepted', $query->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$result = $query->executeQuery();
		try {
			while ($row = $result->fetchAssociative()) {
				if (ServerUrl::equals((string)$row['remote'], $server)) {
					return (string)$row['mountpoint'];
				}
			}
		} finally {
			$result->closeCursor();
		}
		return null;
	}
}
