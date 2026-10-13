<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Federation\RemoteProtocolSupport;

/**
 * How servers and clients find out that file attachments work in federated conversations.
 */
class FeatureSupport {
	/** Talk feature: clients enable attachments in a federated conversation only when both servers list it */
	public const FEATURE = 'federated-attachments';
	/** Sent by a remote server on proxied requests when it can resolve `federated-file` references */
	public const REQUEST_HEADER = 'X-Nextcloud-Talk-Federated-Attachments';
	/** Protocol entry on the talk-room OCM resource type, checked before sharing files with a server */
	public const OCM_PROTOCOL = 'talk-attachments-v1';
	/** Talk feature: clients offer uploads in a federated conversation only when both servers list it (direction B) */
	public const UPLOAD_FEATURE = 'federated-attachments-upload';
	/**
	 * Protocol entry on the talk-room OCM resource type: the server accepts files that federated participants post
	 * from their own server, and resolves files from any participant's server (ruling R10)
	 */
	public const OCM_PROTOCOL_V2 = 'talk-attachments-v2';

	public function __construct(
		private readonly RemoteProtocolSupport $protocolSupport,
	) {
	}

	/**
	 * Whether the server can receive federated attachments (discovery data is cached by Nextcloud for 24h,
	 * refreshed here at most every 10 minutes)
	 *
	 * @param bool $skipCache Ask the server again, even when it was recently unreachable
	 * @return bool|null True when supported, false when definitely not supported,
	 *                   null when unknown (the server could not be reached, try again later)
	 */
	public function remoteSupports(string $remote, bool $skipCache = false): ?bool {
		return $this->remoteHasProtocol($remote, self::OCM_PROTOCOL, $skipCache);
	}

	/**
	 * Whether the server also handles files of federated participants (direction B): as host it accepts them,
	 * as viewer it resolves them. Servers with only `talk-attachments-v1` show files of the host only.
	 *
	 * @return bool|null Same as remoteSupports()
	 */
	public function remoteSupportsUploads(string $remote, bool $skipCache = false): ?bool {
		return $this->remoteHasProtocol($remote, self::OCM_PROTOCOL_V2, $skipCache);
	}

	private function remoteHasProtocol(string $remote, string $protocol, bool $skipCache): ?bool {
		return $this->protocolSupport->supports($remote, $protocol, $skipCache);
	}
}
