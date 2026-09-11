<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Federation\FederationManager;
use OCP\OCM\Exceptions\OCMArgumentException;
use OCP\OCM\Exceptions\OCMProviderException;
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

	public function __construct(
		private readonly IOCMDiscoveryService $discoveryService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the server can receive federated attachments (discovery data is cached by Nextcloud for 24h)
	 */
	public function remoteSupports(string $remote, bool $skipCache = false): bool {
		try {
			$provider = $this->discoveryService->discover($remote, $skipCache);
			$provider->extractProtocolEntry(FederationManager::TALK_ROOM_RESOURCE, self::OCM_PROTOCOL);
			return true;
		} catch (OCMArgumentException) {
			return false;
		} catch (OCMProviderException $e) {
			$this->logger->info('Could not discover OCM provider of ' . $remote, ['exception' => $e]);
			return false;
		}
	}
}
