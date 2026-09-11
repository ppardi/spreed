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

	/** Seconds during which a server whose support is unknown is not asked again (unless skipping the cache) */
	private const UNKNOWN_TTL = 300;

	private ICache $unknownRemotes;

	public function __construct(
		private readonly IOCMDiscoveryService $discoveryService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->unknownRemotes = $cacheFactory->createDistributed('talk/federated-attachments');
	}

	/**
	 * Whether the server can receive federated attachments (discovery data is cached by Nextcloud for 24h)
	 *
	 * @param bool $skipCache Ask the server again, even when it was recently unreachable
	 * @return bool|null True when supported, false when definitely not supported,
	 *                   null when unknown (the server could not be reached, try again later)
	 */
	public function remoteSupports(string $remote, bool $skipCache = false): ?bool {
		$key = ServerUrl::normalize($remote);
		if (!$skipCache && $this->unknownRemotes->get($key) !== null) {
			// Don't make users' requests wait for the discovery timeouts again and again
			return null;
		}

		try {
			$provider = $this->discoveryService->discover($remote, $skipCache);
			$provider->extractProtocolEntry(FederationManager::TALK_ROOM_RESOURCE, self::OCM_PROTOCOL);
			$supported = true;
		} catch (OCMArgumentException) {
			$supported = false;
		} catch (\Throwable $e) {
			// OCMProviderException (unreachable, invalid response) or anything unexpected
			$this->logger->warning('Could not find out whether ' . $remote . ' supports federated attachments, trying again later', ['exception' => $e]);
			$this->unknownRemotes->set($key, 1, self::UNKNOWN_TTL);
			return null;
		}

		if ($skipCache) {
			$this->unknownRemotes->remove($key);
		}
		return $supported;
	}
}
