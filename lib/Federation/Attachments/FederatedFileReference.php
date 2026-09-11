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
		$reference = ['type' => self::TYPE];
		foreach (self::DISPLAY_KEYS as $key) {
			if (isset($fileData[$key])) {
				$reference[$key] = (string)$fileData[$key];
			}
		}
		$reference['server'] = $server;
		$reference['share-id'] = $shareId;
		$reference['path'] = $path;
		return $reference;
	}

	public static function isReference(mixed $parameter): bool {
		return is_array($parameter)
			&& ($parameter['type'] ?? null) === self::TYPE
			&& isset($parameter['name'], $parameter['server'], $parameter['share-id'], $parameter['path']);
	}
}
