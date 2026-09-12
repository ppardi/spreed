<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

/**
 * The `federatedFile` parameter of a file_shared message posted by a federated participant (design §6.2).
 *
 * The file stays on the sender's server, in their conversation folder ("sender folder"), which that server shared
 * with the conversation's other participants before posting.
 *
 * @psalm-type TalkRemoteFile = array{owner: string, folderId: string, path: string, name: string, size: int, mimetype: string, etag: string, fileId: string, width?: int, height?: int, blurhash?: string}
 */
final class RemoteFile {
	private const MAX_NAME_LENGTH = 250;
	private const MAX_PATH_LENGTH = 4000;

	/**
	 * Validates what the sender's server sent to the host
	 *
	 * @param string $owner Cloud id of the sender, from the authenticated request (never from the request body)
	 * @param string $folderId Id of the sender folder on the sender's server
	 * @param array<array-key, mixed> $file Details of the file inside that folder
	 * @return TalkRemoteFile
	 * @throws \InvalidArgumentException
	 */
	public static function fromRequest(string $owner, string $folderId, array $file): array {
		$fileId = $file['fileId'] ?? null;
		if (!self::isId($folderId) || !self::isId($fileId)) {
			throw new \InvalidArgumentException('Invalid file or folder id');
		}
		$path = $file['path'] ?? null;
		if (!is_string($path) || !self::isRelativePath($path)) {
			throw new \InvalidArgumentException('Invalid path');
		}
		$name = $file['name'] ?? null;
		if (!is_string($name) || $name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || str_contains($name, '/')) {
			throw new \InvalidArgumentException('Invalid name');
		}
		$size = self::toInt($file['size'] ?? null);
		if ($size === null) {
			throw new \InvalidArgumentException('Invalid size');
		}
		$mimetype = $file['mimetype'] ?? null;
		if (!is_string($mimetype) || strlen($mimetype) > 255 || preg_match('#^[\w.+-]+/[\w.+-]+$#', $mimetype) !== 1) {
			throw new \InvalidArgumentException('Invalid mimetype');
		}
		$etag = $file['etag'] ?? '';
		if (!is_string($etag) || strlen($etag) > 64) {
			throw new \InvalidArgumentException('Invalid etag');
		}

		$remoteFile = [
			'owner' => $owner,
			'folderId' => $folderId,
			'path' => $path,
			'name' => $name,
			'size' => $size,
			'mimetype' => $mimetype,
			'etag' => $etag,
			'fileId' => $fileId,
		];

		// Optional image details, only used until the viewer's server has its own metadata
		$width = self::toInt($file['width'] ?? null);
		if ($width !== null && $width > 0) {
			$remoteFile['width'] = $width;
		}
		$height = self::toInt($file['height'] ?? null);
		if ($height !== null && $height > 0) {
			$remoteFile['height'] = $height;
		}
		$blurhash = $file['blurhash'] ?? null;
		if (is_string($blurhash) && $blurhash !== '' && strlen($blurhash) <= 255) {
			$remoteFile['blurhash'] = $blurhash;
		}
		return $remoteFile;
	}

	/**
	 * Stored messages are checked too before they are used
	 *
	 * @psalm-assert-if-true TalkRemoteFile $stored
	 */
	public static function isValid(mixed $stored): bool {
		return is_array($stored)
			&& is_string($stored['owner'] ?? null)
			&& self::isId($stored['folderId'] ?? null)
			&& self::isId($stored['fileId'] ?? null)
			&& is_string($stored['path'] ?? null)
			&& self::isRelativePath($stored['path'])
			&& is_string($stored['name'] ?? null)
			&& is_int($stored['size'] ?? null)
			&& is_string($stored['mimetype'] ?? null)
			&& is_string($stored['etag'] ?? null);
	}

	/**
	 * Source id of the host's share-table rows for a sender folder (ruling R2): folder ids are only unique per
	 * server, and the table's unique key has no owner column
	 */
	public static function sourceId(string $owner, string $folderId): string {
		return sha1($owner . '#' . $folderId);
	}

	/**
	 * File, folder and share ids of other servers, as strings of digits
	 *
	 * @psalm-assert-if-true numeric-string $value
	 */
	public static function isId(mixed $value): bool {
		return is_string($value) && preg_match('/^\d{1,20}$/', $value) === 1;
	}

	/**
	 * Relative to the sender folder, never leaving it
	 */
	private static function isRelativePath(string $path): bool {
		return $path !== ''
			&& strlen($path) <= self::MAX_PATH_LENGTH
			&& !str_starts_with($path, '/')
			&& !str_contains($path, "\0")
			&& !in_array('..', explode('/', $path), true);
	}

	private static function toInt(mixed $value): ?int {
		if (is_int($value)) {
			return $value >= 0 ? $value : null;
		}
		if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
			return (int)$value;
		}
		return null;
	}
}
