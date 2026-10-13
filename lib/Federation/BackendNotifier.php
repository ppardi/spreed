<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\Events\AParticipantModifiedEvent;
use OCA\Talk\Events\ARoomModifiedEvent;
use OCA\Talk\Exceptions\RoomHasNoModeratorException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\RetryNotification;
use OCA\Talk\Model\RetryNotificationMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\HintException;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\OCM\Exceptions\OCMProviderException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

class BackendNotifier {
	/** requestUserStatuses() outcome: the remote refused the credential the request was authenticated with */
	public const USER_STATUSES_REFUSED = 'refused';

	public function __construct(
		private readonly ICloudFederationFactory $cloudFederationFactory,
		private readonly AddressHandler $addressHandler,
		private readonly LoggerInterface $logger,
		private readonly ICloudFederationProviderManager $federationProviderManager,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $url,
		private readonly RetryNotificationMapper $retryNotificationMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly RestrictionValidator $restrictionValidator,
	) {
	}

	/**
	 * Send the invitation to participant to join the federated room
	 * Sent from Host server to Remote participant server
	 *
	 * @return array{displayName: string, cloudId: string}|false
	 * @throws HintException
	 * @throws RoomHasNoModeratorException
	 * @throws Exception
	 */
	public function sendRemoteShare(
		string $providerId,
		string $token,
		string $shareWith,
		IUser $sharedBy,
		string $shareType,
		Room $room,
		Attendee $roomOwnerAttendee,
	): array|bool {
		$invitedCloudId = $this->cloudIdManager->resolveCloudId($shareWith);

		$roomName = $room->getName();
		$roomType = $room->getType();
		$roomToken = $room->getToken();
		$roomDefaultPermissions = $room->getDefaultPermissions();

		try {
			$this->restrictionValidator->isAllowedToInvite($sharedBy, $invitedCloudId, $room);
		} catch (\InvalidArgumentException) {
			return false;
		}

		/** @var IUser $roomOwner */
		$roomOwner = $this->userManager->get($roomOwnerAttendee->getActorId());

		$remote = $this->prepareRemoteUrl($invitedCloudId->getRemote());
		if (str_starts_with($remote, 'https://')) {
			$remote = substr($remote, 8);
		}

		$shareWithCloudId = $invitedCloudId->getUser() . '@' . $remote;
		$share = $this->cloudFederationFactory->getCloudFederationShare(
			$shareWithCloudId,
			$roomToken,
			'',
			$providerId,
			$roomOwner->getCloudId(),
			$roomOwner->getDisplayName(),
			$sharedBy->getCloudId(),
			$sharedBy->getDisplayName(),
			$token,
			$shareType,
			FederationManager::TALK_ROOM_RESOURCE
		);

		// Put room name info in the share
		$protocol = $share->getProtocol();
		$protocol['invitedCloudId'] = $invitedCloudId->getId();
		$protocol['roomName'] = $roomName;
		$protocol['roomType'] = $roomType;
		$protocol['roomDefaultPermissions'] = $roomDefaultPermissions;
		$protocol['name'] = FederationManager::TALK_PROTOCOL_NAME;
		$share->setProtocol($protocol);

		try {
			$response = $this->federationProviderManager->sendCloudShare($share);
			if ($response->getStatusCode() === Http::STATUS_CREATED) {
				$body = $response->getBody();
				$data = json_decode((string)$body, true);
				if (isset($data['recipientUserId']) && $data['recipientUserId'] !== '') {
					$shareWithCloudId = $data['recipientUserId'] . '@' . $remote;
				}
				return [
					'displayName' => $data['recipientDisplayName'] ?: $shareWithCloudId,
					'cloudId' => $shareWithCloudId,
				];
			}

			$this->logger->warning("Failed sharing $roomToken with $shareWith, received status code {code}\n{body}", [
				'code' => $response->getStatusCode(),
				'body' => (string)$response->getBody(),
			]);

			return false;
		} catch (OCMProviderException $e) {
			$this->logger->error("Failed sharing $roomToken with $shareWith, received OCMProviderException", ['exception' => $e]);
			return false;
		}
	}

	/**
	 * The invited participant accepted joining the federated room
	 * Sent from Remote participant server to Host server
	 *
	 * @return bool success
	 */
	public function sendShareAccepted(
		string $remoteServerUrl,
		int $remoteAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $displayName,
		string $cloudId,
	): bool {
		$remote = $this->prepareRemoteUrl($remoteServerUrl);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_SHARE_ACCEPTED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$remoteAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'message' => 'Recipient accepted the share',
				'displayName' => $displayName,
				'cloudId' => $cloudId,
			]
		);

		return $this->sendUpdateToRemote($remote, $notification, retry: false) === true;
	}

	/**
	 * The invited participant declined joining the federated room
	 * Sent from Remote participant server to Host server
	 */
	public function sendShareDeclined(
		string $remoteServerUrl,
		int $remoteAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
	): void {
		$remote = $this->prepareRemoteUrl($remoteServerUrl);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_SHARE_DECLINED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$remoteAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'message' => 'Recipient declined the share',
			]
		);

		// We don't handle the return here as all local data is already deleted.
		// If the retry ever aborts due to "unknown" we are fine with it.
		$this->sendUpdateToRemote($remote, $notification);
	}

	public function sendRemoteUnShare(
		string $remoteServerUrl,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
	): void {
		$remote = $this->prepareRemoteUrl($remoteServerUrl);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_SHARE_UNSHARED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'message' => 'This room has been unshared',
			]
		);

		// We don't handle the return here as when the retry ever
		// aborts due to "unknown" we are fine with it.
		$this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Send information to remote participants that the room meta info updated
	 * Sent from Host server to Remote participant server
	 */
	public function sendRoomModifiedUpdate(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		string $changedProperty,
		string|int|bool|null $newValue,
		string|int|bool|null $oldValue,
	): ?bool {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_ROOM_MODIFIED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'changedProperty' => $changedProperty,
				'newValue' => $newValue,
				'oldValue' => $oldValue,
			],
		);

		return $this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Send information to remote participants that the participant meta info updated
	 * Sent from Host server to Remote participant server (only for the affected participant)
	 */
	public function sendParticipantModifiedUpdate(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		string $changedProperty,
		string|int $newValue,
		string|int|null $oldValue,
	): ?bool {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_PARTICIPANT_MODIFIED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'changedProperty' => $changedProperty,
				'newValue' => $newValue,
				'oldValue' => $oldValue,
			],
		);

		return $this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Send information to remote participants that "active since" was updated
	 * Sent from Host server to Remote participant server
	 *
	 * @psalm-param array<AParticipantModifiedEvent::DETAIL_*, bool> $details
	 */
	public function sendCallStarted(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		string $changedProperty,
		\DateTime $activeSince,
		int $callFlag,
		array $details,
	): ?bool {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_ROOM_MODIFIED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'changedProperty' => $changedProperty,
				'newValue' => $activeSince->getTimestamp(),
				'oldValue' => null,
				'callFlag' => $callFlag,
				'details' => $details,
			],
		);

		return $this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Send information to remote participants that "active since" was updated
	 * Sent from Host server to Remote participant server
	 *
	 * @psalm-param array<AParticipantModifiedEvent::DETAIL_*, bool> $details
	 */
	public function sendCallEnded(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		string $changedProperty,
		?\DateTime $activeSince,
		int $callFlag,
		array $details,
	): ?bool {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_ROOM_MODIFIED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'changedProperty' => $changedProperty,
				'newValue' => $activeSince?->getTimestamp(),
				'oldValue' => null,
				'callFlag' => $callFlag,
				'details' => $details,
			],
		);

		return $this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Send information to remote participants that the lobby was updated
	 * Sent from Host server to Remote participant server
	 */
	public function sendRoomModifiedLobbyUpdate(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		string $changedProperty,
		int $newValue,
		int $oldValue,
		?\DateTime $dateTime,
		bool $timerReached,
	): ?bool {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_ROOM_MODIFIED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'changedProperty' => $changedProperty,
				'newValue' => $newValue,
				'oldValue' => $oldValue,
				'dateTime' => $dateTime ? (string)$dateTime->getTimestamp() : '',
				'timerReached' => $timerReached,
			],
		);

		return $this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Send information to remote participants that a message was posted
	 * Sent from Host server to Remote participant server
	 *
	 * @param array{remoteMessageId: int, actorType: string, actorId: string, actorDisplayName: string, messageType: string, systemMessage: string, expirationDatetime: string, message: string, messageParameter: string, creationDatetime: string, metaData: string} $messageData
	 * @param array{unreadMessages: int, unreadMention: bool, unreadMentionDirect: bool, lastReadMessage: int} $unreadInfo
	 */
	public function sendMessageUpdate(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		array $messageData,
		array $unreadInfo,
	): ?bool {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_MESSAGE_POSTED,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'messageData' => $messageData,
				'unreadInfo' => $unreadInfo,
			],
		);

		return $this->sendUpdateToRemote($remote, $notification);
	}

	/**
	 * Ask a remote server for the status of its users in a shared conversation.
	 * Sent from Host server to Remote participant server.
	 *
	 * Unlike the other notifications this one wants an answer, so it reads the 201 body instead of
	 * only its status code, and it is never queued for retry: a status is a point-in-time value and
	 * a retry minutes later would be both useless and a row that accumulates.
	 *
	 * There are four outcomes, and the caller backs off differently for each, so they must not be
	 * conflated:
	 *  - an array: the statuses the server answered with
	 *  - false: the server rejected the notification type, so it is not upgraded
	 *  - USER_STATUSES_REFUSED: the server is reachable and understands the type, but refuses the
	 *    credential we authenticated with (the invite is gone or was replaced). Core throttles the
	 *    asker's IP for every such answer, on an action shared by all OCM notifications, so asking
	 *    again soon is harmful and not merely useless.
	 *  - null: unreachable, answered unreadably, or failed in a way that may pass
	 *
	 * @param list<string> $cloudIds
	 * @return array<string, array{status: string, icon: ?string}>|string|false|null
	 */
	public function requestUserStatuses(
		string $remoteServer,
		int $localAttendeeId,
		#[SensitiveParameter]
		string $accessToken,
		string $localToken,
		array $cloudIds,
	): array|string|false|null {
		$remote = $this->prepareRemoteUrl($remoteServer);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			FederationManager::NOTIFICATION_USER_STATUS_REQUEST,
			FederationManager::TALK_ROOM_RESOURCE,
			(string)$localAttendeeId,
			[
				'remoteServerUrl' => $this->getServerRemoteUrl(),
				'sharedSecret' => $accessToken,
				'remoteToken' => $localToken,
				'cloudIds' => $cloudIds,
			],
		);

		try {
			$response = $this->federationProviderManager->sendCloudNotification($remote, $notification);
		} catch (OCMProviderException $e) {
			$this->logger->warning('Could not ask ' . $remote . ' for user statuses', ['exception' => $e]);
			return null;
		}

		if ($response->getStatusCode() === Http::STATUS_FORBIDDEN) {
			// AuthenticationFailedException on the remote: it does not accept our credential
			$this->logger->warning('Asked ' . $remote . ' for user statuses and it refused the credential of attendee ' . $localAttendeeId);
			return self::USER_STATUSES_REFUSED;
		}

		if ($response->getStatusCode() === Http::STATUS_BAD_REQUEST) {
			$ocmBody = json_decode((string)$response->getBody(), true);
			if (!is_array($ocmBody)) {
				// Not an OCM error shape, so not the remote's match statement rejecting a type it
				// does not know: a reverse proxy or maintenance page answering 400 in HTML, say.
				// That says nothing about the server being un-upgraded.
				$this->logger->warning('Unreadable 400 answer from ' . $remote . ' to a user status request');
				return null;
			}

			if (($ocmBody['message'] ?? '') === FederationManager::OCM_RESOURCE_NOT_FOUND) {
				// The invite we authenticated with is gone or was replaced on the remote. The
				// server itself supports presence, so this must not be cached as "unsupported",
				// but it needs a long back-off all the same.
				$this->logger->warning('Asked ' . $remote . ' for user statuses with an invite it does not know (attendee ' . $localAttendeeId . ')');
				return self::USER_STATUSES_REFUSED;
			}

			// Any other OCM error is the remote's match statement rejecting a notification type it
			// does not know, which means it has not been upgraded yet.
			return false;
		}

		if ($response->getStatusCode() !== Http::STATUS_CREATED) {
			$this->logger->warning('Unexpected answer from ' . $remote . ' to a user status request, received status code {code}', ['code' => $response->getStatusCode()]);
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : null;
	}

	protected function sendUpdateToRemote(string $remote, ICloudFederationNotification $notification, int $try = 0, bool $retry = true): ?bool {
		try {
			$response = $this->federationProviderManager->sendCloudNotification($remote, $notification);
			if ($response->getStatusCode() === Http::STATUS_CREATED) {
				return true;
			}

			if ($response->getStatusCode() === Http::STATUS_BAD_REQUEST) {
				$ocmBody = json_decode((string)$response->getBody(), true) ?? [];
				if (isset($ocmBody['message']) && $ocmBody['message'] === FederationManager::OCM_RESOURCE_NOT_FOUND) {
					// Remote exists but tells us the OCM notification can not be received (invalid invite data)
					// So we stop retrying
					return null;
				}
			}

			$this->logger->warning("Failed to send notification for share from $remote, received status code {code}\n{body}", [
				'code' => $response->getStatusCode(),
				'body' => (string)$response->getBody(),
			]);
		} catch (OCMProviderException $e) {
			$this->logger->error("Failed to send notification for share from $remote, received OCMProviderException", ['exception' => $e]);
		}

		if ($retry && $try === 0) {
			$now = $this->timeFactory->getTime();
			$now += $this->getRetryDelay(1);

			// Talk data
			$retryNotification = new RetryNotification();
			$retryNotification->setRemoteServer($remote);
			$retryNotification->setNumAttempts(1);
			$retryNotification->setNextRetry($this->timeFactory->getDateTime('@' . $now));

			// OCM notification data
			$data = $notification->getMessage();
			$retryNotification->setNotificationType($data['notificationType']);
			$retryNotification->setResourceType($data['resourceType']);
			$retryNotification->setProviderId($data['providerId']);
			$retryNotification->setNotification(json_encode($data['notification']));

			$this->retryNotificationMapper->insert($retryNotification);
		}

		return false;
	}

	public function retrySendingFailedNotifications(\DateTimeInterface $dueDateTime): void {
		$retryNotifications = $this->retryNotificationMapper->getAllDue($dueDateTime);

		foreach ($retryNotifications as $retryNotification) {
			$this->retrySendingFailedNotification($retryNotification);
		}
	}

	protected function retrySendingFailedNotification(RetryNotification $retryNotification): void {
		$data = json_decode($retryNotification->getNotification(), true, flags: JSON_THROW_ON_ERROR);
		if ($retryNotification->getNotificationType() === FederationManager::NOTIFICATION_ROOM_MODIFIED) {
			$localToken = $data['remoteToken'];

			try {
				$manager = \OCP\Server::get(Manager::class);
				$room = $manager->getRoomByToken($localToken);
			} catch (RoomNotFoundException) {
				// Room was deleted in the meantime
				return;
			}

			if ($data['changedProperty'] === ARoomModifiedEvent::PROPERTY_LOBBY) {
				$dateTime = $room->getLobbyTimer();
				$data['newValue'] = $room->getLobbyState();
				$data['dateTime'] = (string)$dateTime?->getTimestamp();
			} elseif ($data['changedProperty'] === ARoomModifiedEvent::PROPERTY_ACTIVE_SINCE) {
				if ($room->getActiveSince() === null) {
					$data['newValue'] = null;
					$data['callFlag'] = Participant::FLAG_DISCONNECTED;
				} else {
					$data['newValue'] = $room->getActiveSince()->getTimestamp();
					$data['callFlag'] = $room->getCallFlag();
				}
			} else {
				$data['newValue'] = match ($data['changedProperty']) {
					ARoomModifiedEvent::PROPERTY_AVATAR => $room->getAvatar(),
					ARoomModifiedEvent::PROPERTY_CALL_RECORDING => $room->getCallRecording(),
					ARoomModifiedEvent::PROPERTY_DEFAULT_PERMISSIONS => $room->getDefaultPermissions(),
					ARoomModifiedEvent::PROPERTY_DESCRIPTION => $room->getDescription(),
					ARoomModifiedEvent::PROPERTY_IN_CALL => $room->getCallFlag(),
					ARoomModifiedEvent::PROPERTY_LAST_PINNED_ID => $room->getLastPinnedId(),
					ARoomModifiedEvent::PROPERTY_MENTION_PERMISSIONS => $room->getMentionPermissions(),
					ARoomModifiedEvent::PROPERTY_MESSAGE_EXPIRATION => $room->getMessageExpiration(),
					ARoomModifiedEvent::PROPERTY_NAME => $room->getName(),
					ARoomModifiedEvent::PROPERTY_READ_ONLY => $room->getReadOnly(),
					ARoomModifiedEvent::PROPERTY_RECORDING_CONSENT => $room->getRecordingConsent(),
					ARoomModifiedEvent::PROPERTY_SIP_ENABLED => $room->getSIPEnabled(),
					ARoomModifiedEvent::PROPERTY_TYPE => $room->getType(),
					default => $data['newValue'],
				};
			}
		}

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			$retryNotification->getNotificationType(),
			$retryNotification->getResourceType(),
			$retryNotification->getProviderId(),
			$data,
		);

		$success = $this->sendUpdateToRemote($retryNotification->getRemoteServer(), $notification, $retryNotification->getNumAttempts());

		if ($success) {
			$this->retryNotificationMapper->delete($retryNotification);
		} elseif ($success === null) {
			$this->logger->error('Server signaled the OCM notification is not accepted at ' . $retryNotification->getRemoteServer() . ', giving up!');
			$this->retryNotificationMapper->delete($retryNotification);
		} elseif ($retryNotification->getNumAttempts() === RetryNotification::MAX_NUM_ATTEMPTS) {
			$this->logger->error('Failed to send notification to ' . $retryNotification->getRemoteServer() . ' ' . RetryNotification::MAX_NUM_ATTEMPTS . ' times, giving up!');
			$this->retryNotificationMapper->delete($retryNotification);
		} else {
			$retryNotification->setNumAttempts($retryNotification->getNumAttempts() + 1);

			$now = $this->timeFactory->getTime();
			$now += $this->getRetryDelay($retryNotification->getNumAttempts());

			$retryNotification->setNextRetry($this->timeFactory->getDateTime('@' . $now));
			$this->retryNotificationMapper->update($retryNotification);
		}
	}

	/**
	 * First 5 attempts are retried on the next cron run.
	 * Attempts 6-10 we back off to cover slightly longer maintenance/downtimes (5 minutes * per attempt)
	 * And the last tries 11-20 are retried with ~8 hours delay
	 *
	 * This means the last retry is after ~84 hours so a downtime from Friday to Monday would be covered
	 */
	protected function getRetryDelay(int $attempt): int {
		if ($attempt < 5) {
			// Retry after "attempt" minutes
			return 5 * 60;
		}

		if ($attempt > 10) {
			// Retry after 8 hours
			return 8 * 3600;
		}

		// Retry after "attempt" * 5 minutes
		return $attempt * 5 * 60;
	}

	protected function prepareRemoteUrl(string $remote): string {
		if (!$this->addressHandler->urlContainProtocol($remote)) {
			return 'https://' . $remote;
		}
		return $remote;
	}

	protected function getServerRemoteUrl(): string {
		$server = rtrim($this->url->getAbsoluteURL('/'), '/');
		if (str_ends_with($server, '/index.php')) {
			$server = substr($server, 0, -10);
		}

		return $server;
	}
}
