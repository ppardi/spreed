<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Finds the local copy of a federated attachment in the user's received federated share
 */
class LocalFileResolver {
	/** @var array<string, true> Users whose mounts were already refreshed in this process */
	private array $refreshedUsers = [];

	public function __construct(
		private readonly ReceivedShareLookup $lookup,
		private readonly IRootFolder $rootFolder,
		private readonly IConfig $config,
		private readonly ISetupManager $setupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Never throws: any failure means "not available"
	 *
	 * @param string $path Path inside the shared node, '' when the share is the file itself
	 * @param string|null $targetFolder Folder (relative to the user's files) to move the received share into.
	 *                                  Only pass it in the user's own request (moving a mount needs their session).
	 */
	public function resolve(string $userId, string $server, string $shareId, string $path, ?string $targetFolder): ?Node {
		if (in_array('..', explode('/', $path), true)) {
			// Paths come from other servers: never leave the shared node
			return null;
		}
		try {
			$mountPoint = $this->lookup->findAccepted($userId, $server, $shareId);
			if ($mountPoint === null) {
				return null;
			}
			$mountPoint = trim($mountPoint, '/');

			try {
				return $this->resolveNode($userId, $mountPoint, $path, $targetFolder);
			} catch (NotFoundException $e) {
				// Right after accepting the share, the mount may not be visible yet in this
				// request's filesystem setup: refresh it (once per user and process) and retry the same lookup.
				if (!$this->refreshMounts($userId)) {
					throw $e;
				}
				return $this->resolveNode($userId, $mountPoint, $path, $targetFolder);
			}
		} catch (NotFoundException|NotPermittedException) {
			return null;
		} catch (\Throwable $e) {
			// e.g. the remote storage is unavailable
			$this->logger->info('Could not resolve federated attachment for ' . $userId, ['exception' => $e]);
			return null;
		}
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function resolveNode(string $userId, string $mountPoint, string $path, ?string $targetFolder): Node {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if ($targetFolder !== null) {
			$mountPoint = $this->moveIntoFolder($userFolder, $userId, $mountPoint, trim($targetFolder, '/'));
		}

		$relativePath = $path === '' ? $mountPoint : $mountPoint . '/' . ltrim($path, '/');
		return $userFolder->get($relativePath);
	}

	/**
	 * @return bool Whether the refresh was performed (false when it already happened in this process,
	 *              the user no longer exists or setup failed)
	 */
	private function refreshMounts(string $userId): bool {
		if (isset($this->refreshedUsers[$userId])) {
			// A file that is still missing after a refresh was deleted: don't set everything up again for each message
			return false;
		}
		$this->refreshedUsers[$userId] = true;

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}
		$this->setupManager->tearDown();
		try {
			$this->setupManager->setupForUser($user);
		} catch (\Throwable $e) {
			// After tearDown(), Nextcloud sets the filesystem up again lazily on the next file access
			// (server lib/private/Files/Node/Root.php get() → setupForPath()), so a failed re-setup here
			// doesn't leave later code without a filesystem.
			$this->logger->warning('Could not set up the filesystem again for ' . $userId, ['exception' => $e]);
			return false;
		}
		return true;
	}

	/**
	 * Moves the share only while it is still where Nextcloud put it, so a user's own move is respected
	 *
	 * @return string The mount point after moving (unchanged when not moved or the move failed)
	 */
	private function moveIntoFolder(Folder $userFolder, string $userId, string $mountPoint, string $targetFolder): string {
		$currentFolder = trim(dirname('/' . $mountPoint), '/');
		// Nextcloud mounts accepted federated shares at the top level of the user's files even when a share folder is
		// configured (automatic accepts always do, manual accepts because of a files_sharing bug), so both places count
		if ($targetFolder === '' || $currentFolder === $targetFolder
			|| ($currentFolder !== '' && $currentFolder !== $this->getShareFolder($userId))) {
			return $mountPoint;
		}

		try {
			$mountRoot = $userFolder->get($mountPoint);
			$target = $this->getOrCreateFolder($userFolder, $targetFolder);
			$name = $target->getNonExistingName($mountRoot->getName());
			$mountRoot->move($target->getPath() . '/' . $name);
			return $targetFolder . '/' . $name;
		} catch (\Throwable $e) {
			$this->logger->info('Could not move federated attachment folder ' . $mountPoint . ' to ' . $targetFolder, ['exception' => $e]);
			return $mountPoint;
		}
	}

	/**
	 * Configured folder for accepted shares (same rules as files_sharing's Helper::getShareFolder())
	 */
	private function getShareFolder(string $userId): string {
		$shareFolder = $this->config->getSystemValueString('share_folder', '/');
		if ($this->config->getSystemValueBool('sharing.allow_custom_share_folder', true)) {
			$shareFolder = $this->config->getUserValue($userId, 'files_sharing', 'share_folder', $shareFolder);
		}
		return trim($shareFolder, '/');
	}

	/**
	 * @throws NotPermittedException
	 * @throws \RuntimeException when a path segment is a file
	 */
	private function getOrCreateFolder(Folder $userFolder, string $path): Folder {
		$folder = $userFolder;
		foreach (explode('/', $path) as $segment) {
			try {
				$next = $folder->get($segment);
			} catch (NotFoundException) {
				$next = $folder->newFolder($segment);
			}
			if (!$next instanceof Folder) {
				throw new \RuntimeException('Not a folder: ' . $path);
			}
			$folder = $next;
		}
		return $folder;
	}
}
