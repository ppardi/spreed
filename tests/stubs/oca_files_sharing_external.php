<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\External {

	use OCP\IUser;

	class ExternalShare {
	}

	class Manager {
		public function getShare(string $id, ?IUser $user = null): ExternalShare|false {
		}

		public function declineShare(ExternalShare $externalShare, ?IUser $user = null): bool {
		}
	}
}
