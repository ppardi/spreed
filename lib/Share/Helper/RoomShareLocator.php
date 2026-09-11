<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Share\Helper;

use OCA\Talk\Room;
use OCA\Talk\Share\RoomShareProvider;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IShare;

/**
 * Finds the folder-level room share (conversation subfolder) that contains a file
 */
class RoomShareLocator {
	private const MAX_DEPTH = 10;

	public function __construct(
		private readonly RoomShareProvider $shareProvider,
	) {
	}

	/**
	 * @return array{0: ?IShare, 1: string} The share and the path of $node inside the shared folder
	 */
	public function findForNode(Room $room, Node $node): array {
		$roomToken = $room->getToken();

		$current = $node;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			try {
				$parent = $current->getParent();
			} catch (NotFoundException) {
				break;
			}
			if ($parent === $current) {
				break;
			}
			foreach ($this->shareProvider->getSharesByPath($parent) as $share) {
				if ($share->getSharedWith() === $roomToken) {
					$relative = substr($node->getPath(), strlen($parent->getPath()));
					return [$share, ltrim($relative, '/')];
				}
			}
			$current = $parent;
		}

		return [null, ''];
	}
}
