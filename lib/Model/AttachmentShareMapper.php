<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AttachmentShare>
 */
class AttachmentShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_attachment_shares', AttachmentShare::class);
	}

	public function findForRecipient(int $roomId, string $sourceType, string $sourceId, string $recipientActorType, string $recipientActorId): ?AttachmentShare {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('source_type', $query->createNamedParameter($sourceType)))
			->andWhere($query->expr()->eq('source_id', $query->createNamedParameter($sourceId)))
			->andWhere($query->expr()->eq('recipient_actor_type', $query->createNamedParameter($recipientActorType)))
			->andWhere($query->expr()->eq('recipient_actor_id', $query->createNamedParameter($recipientActorId)));

		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<AttachmentShare>
	 */
	public function findBySource(int $roomId, string $sourceType, string $sourceId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('source_type', $query->createNamedParameter($sourceType)))
			->andWhere($query->expr()->eq('source_id', $query->createNamedParameter($sourceId)));
		return $this->findEntities($query);
	}

	/**
	 * @return list<AttachmentShare>
	 */
	public function findByRecipient(int $roomId, string $recipientActorType, string $recipientActorId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('recipient_actor_type', $query->createNamedParameter($recipientActorType)))
			->andWhere($query->expr()->eq('recipient_actor_id', $query->createNamedParameter($recipientActorId)));
		return $this->findEntities($query);
	}

	/**
	 * @return list<AttachmentShare>
	 */
	public function findByRoom(int $roomId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('room_id', $query->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($query);
	}

	/**
	 * @param string $ownerServer '' for shares created by this server
	 * @param string|null $origin Only count rows with this origin (AttachmentShare::ORIGIN_*)
	 */
	public function countByOwnerShareId(string $ownerServer, string $shareId, ?string $origin = null): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_rows'))
			->from($this->getTableName());
		$this->whereOwnerShareId($query, $ownerServer, $shareId);
		if ($origin !== null) {
			$query->andWhere($query->expr()->eq('origin', $query->createNamedParameter($origin)));
		}

		$result = $query->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @param string $ownerServer '' for shares created by this server
	 */
	public function deleteByOwnerShareId(string $ownerServer, string $shareId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName());
		$this->whereOwnerShareId($query, $ownerServer, $shareId);
		$query->executeStatement();
	}

	private function whereOwnerShareId(IQueryBuilder $query, string $ownerServer, string $shareId): void {
		$query->where($query->expr()->eq('share_id', $query->createNamedParameter($shareId)));
		if ($ownerServer === '') {
			$query->andWhere($query->expr()->orX(
				$query->expr()->eq('owner_server', $query->createNamedParameter('')),
				$query->expr()->isNull('owner_server'),
			));
		} else {
			$query->andWhere($query->expr()->eq('owner_server', $query->createNamedParameter($ownerServer)));
		}
	}
}
