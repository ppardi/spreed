<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Federated shares of conversation attachments, one row per source and recipient
 */
class Version24000Date20260911000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('talk_attachment_shares')) {
			return null;
		}

		$table = $schema->createTable('talk_attachment_shares');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
			'length' => 20,
		]);
		$table->addColumn('room_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
			'length' => 20,
		]);
		$table->addColumn('source_type', Types::STRING, [
			'notnull' => true,
			'length' => 16,
		]);
		$table->addColumn('source_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('owner_server', Types::STRING, [
			'notnull' => false,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('owner_actor_type', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('owner_actor_id', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('recipient_actor_type', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('recipient_actor_id', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('share_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => false,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['room_id', 'source_type', 'source_id', 'recipient_actor_type', 'recipient_actor_id'], 'tas_source_recipient');
		$table->addIndex(['room_id', 'recipient_actor_type', 'recipient_actor_id'], 'tas_room_recipient');
		$table->addIndex(['owner_server', 'share_id'], 'tas_owner_share');

		return $schema;
	}
}
