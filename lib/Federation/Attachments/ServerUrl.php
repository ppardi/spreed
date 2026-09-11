<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

/**
 * Server URLs are stored in different shapes (with/without scheme, trailing slash).
 */
final class ServerUrl {
	/**
	 * Lower-case scheme and host, https:// when no scheme is given, no trailing slash
	 */
	public static function normalize(string $url): string {
		$url = trim($url);
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return rtrim($url, '/');
		}

		$normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
		if (isset($parts['port'])) {
			$normalized .= ':' . $parts['port'];
		}
		return $normalized . rtrim($parts['path'] ?? '', '/');
	}

	public static function equals(string $a, string $b): bool {
		return self::normalize($a) === self::normalize($b);
	}
}
