<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service;

use OCA\Talk\Federation\BackendNotifier;
use OCA\Talk\Federation\Presence\FeatureSupport;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Federation\ICloudIdManager;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The statuses of federated participants, as the server hosting the conversation sees them.
 *
 * Only the server that hosts a room fetches these: it is the only one holding an invite for every
 * remote in the room. A server viewing someone else's room receives the statuses along with the
 * proxied participant list instead, which is why getStatuses() has a cache-only mode.
 */
class FederatedUserStatusService {
	/**
	 * Seconds a status is reused. The source signal is coarse: user_status invalidates at 15
	 * minutes and refreshes at 7, so finer granularity than this buys nothing real.
	 */
	private const TTL = 120;
	/** Seconds before a server that could not be reached is asked again */
	private const UNREACHABLE_TTL = 30;
	/** Seconds before a server that rejected the notification type is asked again */
	private const UNSUPPORTED_TTL = 3600;

	private const UNREACHABLE = 'unreachable';
	private const UNSUPPORTED = 'unsupported';
	/** The remote answered but holds no current status for that user, which is common and not an error */
	private const UNKNOWN = 'unknown';

	private ICache $cache;

	public function __construct(
		private readonly BackendNotifier $backendNotifier,
		private readonly FeatureSupport $featureSupport,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly IAppConfig $appConfig,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('talk/federated-presence');
	}

	/**
	 * @param list<Participant> $federatedParticipants participants whose actor type is federated_users
	 * @param bool $cacheOnly Relay what is already held and fetch nothing. Used while answering a
	 *                        federated request, where a fetch would mean a second OCM round trip
	 *                        inside the first one.
	 * @return array<string, array{status: string, icon: ?string}> keyed by cloud id
	 */
	public function getStatuses(Room $room, array $federatedParticipants, bool $cacheOnly = false): array {
		// The same switch the answering side honors, so one app config turns the feature off on
		// this server whether it is asking or being asked.
		if (!$this->appConfig->getAppValueBool('federation_presence_enabled', true)) {
			return [];
		}

		$statuses = [];
		/** @var array<string, list<string>> $missingByServer */
		$missingByServer = [];
		/** @var array<string, Attendee> $attendeeByServer */
		$attendeeByServer = [];

		foreach ($federatedParticipants as $participant) {
			$attendee = $participant->getAttendee();
			$cloudId = $attendee->getActorId();

			$cached = $this->cache->get($this->key($cloudId));
			if (is_array($cached)) {
				$statuses[$cloudId] = $cached;
				continue;
			}
			if ($cached === self::UNREACHABLE || $cached === self::UNSUPPORTED || $cached === self::UNKNOWN) {
				// Still backing off from that server
				continue;
			}

			$server = $this->serverOf($cloudId);
			if ($server === null) {
				continue;
			}
			$missingByServer[$server][] = $cloudId;

			// Any attendee of that server carries an invite the request can authenticate with, but
			// only one the remote accepted is certain to still exist there, so those win.
			if (!isset($attendeeByServer[$server])
				|| ($attendee->getState() === Invitation::STATE_ACCEPTED
					&& $attendeeByServer[$server]->getState() !== Invitation::STATE_ACCEPTED)) {
				$attendeeByServer[$server] = $attendee;
			}
		}

		if ($cacheOnly) {
			return $statuses;
		}

		foreach ($missingByServer as $server => $cloudIds) {
			if ($this->featureSupport->remoteSupports($server) !== true) {
				// Not advertising the protocol, or not reachable enough to tell. FeatureSupport
				// owns the back-off for both; asking anyway would pay its timeout again.
				continue;
			}

			$attendee = $attendeeByServer[$server];
			$answer = $this->backendNotifier->requestUserStatuses(
				$server,
				$attendee->getId(),
				$attendee->getAccessToken(),
				$room->getToken(),
				$cloudIds,
			);

			if ($answer === false) {
				// Rejected the notification type: the server is not upgraded. Feature detection
				// should have caught this, so this is the belt-and-braces path - back off for an
				// hour rather than asking again every two minutes.
				$this->remember($cloudIds, self::UNSUPPORTED, self::UNSUPPORTED_TTL);
				continue;
			}

			if ($answer === null) {
				$this->remember($cloudIds, self::UNREACHABLE, self::UNREACHABLE_TTL);
				continue;
			}

			foreach ($cloudIds as $cloudId) {
				// Driven by what was asked for, not by what came back, so a remote cannot answer
				// about users this server never asked about.
				$entry = $answer[$cloudId] ?? null;
				if (!is_array($entry) || !isset($entry['status']) || !is_string($entry['status'])) {
					// No usable answer. A user without a current status is simply absent from the
					// remote's map, so remember that rather than asking again on every request. It
					// stays out of the result: a missing key is how the UI shows "unknown".
					$this->cache->set($this->key($cloudId), self::UNKNOWN, self::TTL);
					continue;
				}
				$icon = $entry['icon'] ?? null;
				$value = ['status' => $entry['status'], 'icon' => is_string($icon) ? $icon : null];
				$this->cache->set($this->key($cloudId), $value, self::TTL);
				$statuses[$cloudId] = $value;
			}
		}

		return $statuses;
	}

	/**
	 * @param list<string> $cloudIds
	 */
	private function remember(array $cloudIds, string $marker, int $ttl): void {
		foreach ($cloudIds as $cloudId) {
			$this->cache->set($this->key($cloudId), $marker, $ttl);
		}
	}

	/**
	 * Keyed per cloud id rather than per room, so several conversations with the same person share
	 * one entry and opening all of them costs one fetch.
	 */
	private function key(string $cloudId): string {
		return sha1(strtolower($cloudId));
	}

	private function serverOf(string $cloudId): ?string {
		try {
			return $this->cloudIdManager->resolveCloudId($cloudId)->getRemote();
		} catch (\InvalidArgumentException) {
			return null;
		}
	}
}
