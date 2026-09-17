<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

/**
 * The `federated-file` message parameter the host sends to a remote viewer's server instead of a `file`.
 * The viewer's server always replaces it (with its local `file`, or a fallback text), clients never see it.
 */
final class FederatedFileReference {
	public const TYPE = 'federated-file';

	/** Display-only keys copied from the host's `file` rich object */
	private const DISPLAY_KEYS = ['name', 'size', 'mimetype', 'etag', 'preview-available', 'width', 'height', 'blurhash'];

	/**
	 * @param array<string, string> $fileData The `file` rich object as rendered on the host
	 * @param string $server Server that owns the file (holds the federated share)
	 * @param string $shareId Id of the viewer's federated share on $server
	 * @param string $path Path of the file inside the shared node, '' when the node itself is the file
	 * @return array<string, string>
	 */
	public static function forShare(array $fileData, string $server, string $shareId, string $path): array {
		$reference = self::withDisplayData($fileData);
		$reference['server'] = $server;
		$reference['share-id'] = $shareId;
		$reference['path'] = $path;
		return $reference;
	}

	/**
	 * For a viewer on the server that owns the file: the sender viewing their own file (design §5.1)
	 *
	 * @param array<string, string> $fileData Display data of the file
	 * @param string $server Server that owns the file (the viewer's own server)
	 * @param string $fileId Id of the file on $server
	 * @return array<string, string>
	 */
	public static function forOwnFile(array $fileData, string $server, string $fileId): array {
		$reference = self::withDisplayData($fileData);
		$reference['server'] = $server;
		$reference['file-id'] = $fileId;
		return $reference;
	}

	/**
	 * Without a viewer (one render for all federated recipients): display data only, which no server resolves,
	 * so per-room cached copies keep just the file name (design §5.2, ruling R6)
	 *
	 * @param array<string, string> $fileData Display data of the file
	 * @return array<string, string>
	 */
	public static function forDisplay(array $fileData, string $server): array {
		$reference = self::withDisplayData($fileData);
		$reference['server'] = $server;
		return $reference;
	}

	public static function isReference(mixed $parameter): bool {
		return is_array($parameter)
			&& ($parameter['type'] ?? null) === self::TYPE
			&& isset($parameter['name'], $parameter['server'])
			&& (isset($parameter['share-id'], $parameter['path']) || isset($parameter['file-id']));
	}

	/**
	 * @param array<string, string> $fileData
	 * @return array<string, string>
	 */
	private static function withDisplayData(array $fileData): array {
		$reference = ['type' => self::TYPE];
		foreach (self::DISPLAY_KEYS as $key) {
			if (isset($fileData[$key])) {
				$reference[$key] = (string)$fileData[$key];
			}
		}
		return $reference;
	}
}
