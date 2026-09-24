<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version25000Date20260923155555 extends SimpleMigrationStep {
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

		$changed = false;
		$table = $schema->getTable('talk_thread_attendees');

		/* Both names are indexes: each is created with addUniqueIndex(), here
		 * and in Version22000Date20250623142327. Doctrine keeps indexes and
		 * unique constraints in separate collections, so hasUniqueConstraint()
		 * never sees either of them. Restoring the missing return made this
		 * content run for the first time, and the guard below then tried to add
		 * an index that was already there: "An index with name
		 * tta_throom_attendee was already defined on table
		 * oc_talk_thread_attendees", which aborts the upgrade. The constraint
		 * checks are kept beside the index ones, because an instance carrying
		 * that uniqueness as a real constraint still has to lose it - it spans
		 * three columns, and room_id is the fourth. */
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

		return $changed ? $schema : null;
	}
}
