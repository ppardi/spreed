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
 * Stores the common read marker a conversation's host last reported, per local attendee of a
 * federated conversation. Only the host can compute it, and the value has to survive between
 * requests, so the conversation list can report it without asking the host again.
 *
 * The class name carries a timestamp of this fork's own, so it can never collide with an upstream
 * migration: Nextcloud records applied migrations by app plus the name after "Version".
 */
class Version25000Date20260918213000 extends SimpleMigrationStep {
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

		$table = $schema->getTable('talk_attendees');
		if ($table->hasColumn('last_common_read_message')) {
			return null;
		}

		$table->addColumn('last_common_read_message', Types::BIGINT, [
			'notnull' => false,
			'default' => 0,
		]);

		return $schema;
	}
}
