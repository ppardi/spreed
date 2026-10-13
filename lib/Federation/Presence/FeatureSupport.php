<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Presence;

use OCA\Talk\Federation\RemoteProtocolSupport;

/**
 * How servers and clients find out that user status works in federated conversations.
 */
class FeatureSupport {
	/** Talk feature: clients show presence for federated participants only when the server lists it */
	public const FEATURE = 'federated-user-status';
	/** Protocol entry on the talk-room OCM resource type, checked before asking a server for statuses */
	public const OCM_PROTOCOL = 'talk-presence-v1';

	public function __construct(
		private readonly RemoteProtocolSupport $protocolSupport,
	) {
	}

	/**
	 * @return bool|null True when supported, false when definitely not, null when unknown
	 */
	public function remoteSupports(string $remote, bool $skipCache = false): ?bool {
		return $this->protocolSupport->supports($remote, self::OCM_PROTOCOL, $skipCache);
	}
}
