<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Config;
use OCA\Talk\Room;
use OCP\IUserSession;

/**
 * Where a viewer's received federated attachment shares are placed (design D3)
 */
class ConversationFolder {
	public function __construct(
		private readonly Config $talkConfig,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * The viewer's conversation folder, e.g. "Talk/Room-both-64z86muv", relative to their files.
	 *
	 * Null outside the viewer's own request: moving a received share needs their session (not the case in OCM
	 * notifications, cron or another user's request).
	 */
	public function targetForViewer(Room $room, string $userId): ?string {
		if ($this->userSession->getUser()?->getUID() !== $userId
			|| !$this->talkConfig->isConversationSubfoldersEnabled()) {
			return null;
		}
		return trim($this->talkConfig->getAttachmentFolder($userId), '/') . '/' . $this->talkConfig->getConversationFolderName($room, $userId);
	}
}
