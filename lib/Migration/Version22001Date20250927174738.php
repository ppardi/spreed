<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version22001Date20250927174738 extends SimpleMigrationStep {
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

		$table = $schema->getTable('talk_thread_attendees');

		/* Both names refer to indexes: each was created with addUniqueIndex(),
		 * here and in Version22000Date20250623142327. Doctrine keeps indexes and
		 * unique constraints in separate collections, so hasUniqueConstraint()
		 * never sees either of them — which left the old index in place and made
		 * the new one be added a second time on any database that already had
		 * it, failing with "An index with name tta_throom_attendee was already
		 * defined". The constraint check is kept alongside, because an instance
		 * that somehow carries the old uniqueness as a constraint still has to
		 * lose it: it spans three columns, and room_id is the fourth. */
		$changed = false;

		if ($table->hasUniqueConstraint('tta_thread_attendee')) {
			$table->removeUniqueConstraint('tta_thread_attendee');
			$changed = true;
		}
		if ($table->hasIndex('tta_thread_attendee')) {
			$table->dropIndex('tta_thread_attendee');
			$changed = true;
		}
		if (!$table->hasIndex('tta_throom_attendee')) {
			$table->addUniqueIndex(['thread_id', 'room_id', 'actor_type', 'actor_id'], 'tta_throom_attendee');
			$changed = true;
		}

		/* The schema is applied only when it is returned - a null return
		 * discards every mutation above, which is how this file came to be a
		 * silent no-op. Every branch that touches the table has to set the
		 * flag, or the work it did goes nowhere and the migration still
		 * reports success. */
		return $changed ? $schema : null;
	}
}
