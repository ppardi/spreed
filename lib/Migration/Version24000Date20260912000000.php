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
 * Remember whether Talk created a federated attachment share or reused one that already existed,
 * so Talk only ever removes shares it created
 */
class Version24000Date20260912000000 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('talk_attachment_shares')) {
			return null;
		}

		$table = $schema->getTable('talk_attachment_shares');
		if ($table->hasColumn('origin')) {
			return null;
		}

		$table->addColumn('origin', Types::STRING, [
			'notnull' => false,
			'length' => 16,
			'default' => 'created',
		]);

		return $schema;
	}
}
