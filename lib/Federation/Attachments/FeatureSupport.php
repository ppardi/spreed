<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Federation\FederationManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\OCM\Exceptions\OCMArgumentException;
use OCP\OCM\IOCMDiscoveryService;
use Psr\Log\LoggerInterface;

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

	/** Seconds during which a server whose support is unknown is not asked again (unless skipping the cache) */
	private const UNKNOWN_TTL = 300;
	/** Seconds during which a server's discovery document is taken from Nextcloud's cache (per protocol) */
	private const REFRESH_TTL = 600;

	/** Servers whose support is unknown, and when discovery documents were last refreshed */
	private ICache $markers;

	public function __construct(
		private readonly IOCMDiscoveryService $discoveryService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->markers = $cacheFactory->createDistributed('talk/federated-attachments');
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
		$key = ServerUrl::normalize($remote);
		if (!$skipCache) {
			if ($this->markers->get($key) !== null) {
				// Don't make users' requests wait for the discovery timeouts again and again
				return null;
			}

			// Nextcloud keeps discovery documents for 24 hours, and nothing clears them when the other server is
			// upgraded: a new protocol would stay missing for up to a day while clients (live capabilities) already
			// offer the feature. So the first lookup in 10 minutes asks the server again. Not only once the cached
			// document turned out to lack the protocol: OCMDiscoveryService::discover() returns the document it
			// already loaded in this process even when told to skip the cache, so a second call would get the same.
			$refreshKey = 'refresh/' . $protocol . '/' . $key;
			if ($this->markers->get($refreshKey) === null) {
				$this->markers->set($refreshKey, 1, self::REFRESH_TTL);
				$skipCache = true;
			}
		}

		try {
			$provider = $this->discoveryService->discover($remote, $skipCache);
			$provider->extractProtocolEntry(FederationManager::TALK_ROOM_RESOURCE, $protocol);
			$supported = true;
		} catch (OCMArgumentException) {
			$supported = false;
		} catch (\Throwable $e) {
			// OCMProviderException (unreachable, invalid response) or anything unexpected
			$this->logger->warning('Could not find out whether ' . $remote . ' supports federated attachments, trying again later', ['exception' => $e]);
			$this->markers->set($key, 1, self::UNKNOWN_TTL);
			return null;
		}

		if ($skipCache) {
			$this->markers->remove($key);
		}
		return $supported;
	}
}
