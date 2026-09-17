<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

/**
 * Copies of messages that a remote server stores once per conversation (conversation list,
 * notifications) must not carry per-user file data, so file messages become plain file names.
 */
final class FileMessageSanitizer {
	/**
	 * @return array{0: string, 1: array}
	 */
	public static function toPlainName(string $message, array $parameters): array {
		$file = $parameters['file'] ?? null;
		if (!is_array($file)
			|| !isset($file['name'])
			|| !in_array($file['type'] ?? '', ['file', FederatedFileReference::TYPE], true)) {
			return [$message, $parameters];
		}

		unset($parameters['file']);
		$name = (string)$file['name'];
		if ($message === '{file}') {
			return [$name, $parameters];
		}
		// The message is the caption
		return [$name . "\n\n" . $message, $parameters];
	}
}
