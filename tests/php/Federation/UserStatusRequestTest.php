<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Federation\CloudFederationProviderTalk;
use OCA\Talk\Federation\FederationManager;
use OCA\Talk\Federation\Proxy\TalkV1\UserConverter;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Model\InvitationMapper;
use OCA\Talk\Model\ProxyCacheMessageMapper;
use OCA\Talk\Notification\FederationChatNotifier;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ProxyCacheMessageService;
use OCA\Talk\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\ICacheFactory;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserStatusRequestTest extends TestCase {
	protected Config&MockObject $config;
	protected IAppConfig&MockObject $appConfig;
	protected AddressHandler&MockObject $addressHandler;
	protected ICloudIdManager&MockObject $cloudIdManager;
	protected IUserManager&MockObject $userManager;
	protected InvitationMapper&MockObject $invitationMapper;
	protected Manager&MockObject $manager;
	protected ParticipantService&MockObject $participantService;
	protected IUserStatusManager&MockObject $statusManager;
	protected CloudFederationProviderTalk $provider;
	/** @var array<int, Room&MockObject> */
	private array $rooms = [];
	/** @var array<int, list<Participant>> */
	private array $participantsByRoom = [];

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(Config::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->addressHandler = $this->createMock(AddressHandler::class);
		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->invitationMapper = $this->createMock(InvitationMapper::class);
		$this->manager = $this->createMock(Manager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->statusManager = $this->createMock(IUserStatusManager::class);

		// getByRemoteAttendeeAndValidate() checks these two before it looks anything up
		$this->config->method('isFederationEnabled')->willReturn(true);
		$this->addressHandler->method('urlContainProtocol')->willReturn(true);
		$this->appConfig->method('getAppValueBool')
			->willReturnCallback(static fn (string $key, bool $default = false): bool => $default);

		// Every room this server holds is answered from the maps that givenRoomParticipants() fills
		$this->manager->method('getRoomById')
			->willReturnCallback(fn (int $roomId): Room => $this->rooms[$roomId] ?? throw new RoomNotFoundException());
		$this->participantService->method('getParticipantsByActorType')
			->willReturnCallback(fn (Room $room, string $actorType): array => $actorType === Attendee::ACTOR_USERS
				? ($this->participantsByRoom[$room->getId()] ?? [])
				: []);

		$this->provider = $this->buildProvider($this->appConfig);
	}

	private function buildProvider(IAppConfig $appConfig): CloudFederationProviderTalk {
		return new CloudFederationProviderTalk(
			$this->cloudIdManager,
			$this->userManager,
			$this->addressHandler,
			$this->createMock(FederationManager::class),
			$this->config,
			$appConfig,
			$this->createMock(INotificationManager::class),
			$this->participantService,
			$this->createMock(RoomService::class),
			$this->createMock(AttendeeMapper::class),
			$this->invitationMapper,
			$this->manager,
			$this->createMock(ISession::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ProxyCacheMessageMapper::class),
			$this->createMock(ProxyCacheMessageService::class),
			$this->createMock(FederationChatNotifier::class),
			$this->createMock(UserConverter::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(ICacheFactory::class),
			$this->statusManager,
		);
	}

	/**
	 * The invite the asking server authenticates with, pointing at this server's copy of the room
	 */
	private function givenInvitationForRoom(string $remoteServerUrl, int $remoteAttendeeId, string $secret, int $localRoomId): void {
		$invitation = Invitation::fromRow([
			'id' => 1,
			'user_id' => 'bill',
			'state' => Invitation::STATE_ACCEPTED,
			'local_room_id' => $localRoomId,
			'access_token' => $secret,
			'remote_server_url' => $remoteServerUrl,
			'remote_token' => 'roomtoken',
			'remote_attendee_id' => $remoteAttendeeId,
		]);

		$this->invitationMapper->method('getByRemoteAndAccessToken')
			->with($remoteServerUrl, $remoteAttendeeId, $secret)
			->willReturn($invitation);
	}

	/**
	 * One of this server's copies of a shared room, and the local users who are in it. Call it once
	 * per room to model a server that holds several.
	 *
	 * @param list<string> $userIds
	 */
	private function givenRoomParticipants(int $localRoomId, array $userIds, bool $federated = true): void {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn($localRoomId);
		// An invite always points at a federated conversation, never at one of our own rooms
		$room->method('isFederatedConversation')->willReturn($federated);
		$this->rooms[$localRoomId] = $room;

		$this->participantsByRoom[$localRoomId] = array_map(
			static fn (string $userId): Participant => new Participant(
				$room,
				Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_USERS,
					'actor_id' => $userId,
					'display_name' => ucfirst($userId),
				]),
				null,
			),
			$userIds,
		);
	}

	/**
	 * Cloud ids resolve to their user part, and local accounts exist under the spelling given here.
	 *
	 * @param list<string> $existingUserIds the canonical uid of every local account in these tests
	 */
	private function givenLocalAccounts(array $existingUserIds): void {
		$this->cloudIdManager->method('resolveCloudId')
			->willReturnCallback(function (string $cloudId): ICloudId {
				$at = strrpos($cloudId, '@');
				if ($at === false) {
					throw new \InvalidArgumentException($cloudId);
				}
				$resolved = $this->createMock(ICloudId::class);
				$resolved->method('getUser')->willReturn(substr($cloudId, 0, $at));
				$resolved->method('getRemote')->willReturn(substr($cloudId, $at + 1));
				return $resolved;
			});

		$this->userManager->method('get')
			->willReturnCallback(function (string $userId) use ($existingUserIds): ?IUser {
				foreach ($existingUserIds as $existing) {
					// Nextcloud resolves accounts case-insensitively and answers with the canonical uid
					if (strcasecmp($userId, $existing) === 0) {
						$user = $this->createMock(IUser::class);
						$user->method('getUID')->willReturn($existing);
						return $user;
					}
				}
				return null;
			});
	}

	/**
	 * @param array<string, array{0: string, 1: ?string}> $statusByUserId
	 */
	private function givenStatuses(array $statusByUserId): void {
		$this->statusManager->method('getUserStatuses')
			->willReturnCallback(function (array $userIds) use ($statusByUserId): array {
				$result = [];
				foreach ($userIds as $userId) {
					if (!isset($statusByUserId[$userId])) {
						// getUserStatuses() simply omits users it has no current status for
						continue;
					}
					[$value, $icon] = $statusByUserId[$userId];
					$status = $this->createMock(IUserStatus::class);
					$status->method('getStatus')->willReturn($value);
					$status->method('getIcon')->willReturn($icon);
					$result[$userId] = $status;
				}
				return $result;
			});
	}

	/**
	 * @param list<string> $cloudIds
	 */
	private function request(array $cloudIds, ?CloudFederationProviderTalk $provider = null): array {
		return ($provider ?? $this->provider)->notificationReceived(
			FederationManager::NOTIFICATION_USER_STATUS_REQUEST,
			'7',
			[
				'remoteServerUrl' => 'https://host.test',
				'sharedSecret' => 'secret',
				'remoteToken' => 'roomtoken',
				'cloudIds' => $cloudIds,
			],
		);
	}

	public function testReturnsStatusForAParticipantOfThatRoom(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->givenStatuses(['bill' => [IUserStatus::AWAY, '🏃']]);

		$this->assertSame(
			['bill@cloud.test' => ['status' => 'away', 'icon' => '🏃']],
			$this->request(['bill@cloud.test']),
		);
	}

	public function testInvisibleIsReportedAsOfflineAndNotOmitted(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->givenStatuses(['bill' => [IUserStatus::INVISIBLE, '🙈']]);

		// Invisible must look exactly like offline, and must be present rather than absent,
		// because absent renders as "unknown" and would reveal that something is being hidden.
		$this->assertSame(
			['bill@cloud.test' => ['status' => 'offline', 'icon' => null]],
			$this->request(['bill@cloud.test']),
		);
	}

	public function testUserWhoIsNotInThatRoomIsNotAnswered(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill', 'carol']);
		$this->givenStatuses(['carol' => [IUserStatus::ONLINE, null]]);

		$this->assertSame([], $this->request(['carol@cloud.test']));
	}

	public function testCloudIdIsMatchedAgainstTheCanonicalUserId(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		// The attendee row, and user_status, know this account as "bill"
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->givenStatuses(['bill' => [IUserStatus::ONLINE, null]]);

		// The asking server stored the cloud id with a capital B. Matching the raw string against
		// the attendee row would answer nothing, which reads on the other side as "unknown" and is
		// indistinguishable from this server being unreachable.
		$result = $this->request(['Bill@Cloud.TEST']);

		// Keyed by the spelling the caller used, so it can match its own request up again
		$this->assertSame(['Bill@Cloud.TEST' => ['status' => 'online', 'icon' => null]], $result);
	}

	public function testInviteForOneRoomCannotReadStatusesOfAnother(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		// dave is a user of this server with a status, but he is only in room 99, a different
		// conversation that this invite has nothing to do with
		$this->givenRoomParticipants(99, ['dave']);
		$this->givenLocalAccounts(['bill', 'dave']);
		$this->givenStatuses([
			'bill' => [IUserStatus::AWAY, null],
			'dave' => [IUserStatus::ONLINE, null],
		]);

		$this->assertSame(
			['bill@cloud.test' => ['status' => 'away', 'icon' => null]],
			$this->request(['bill@cloud.test', 'dave@cloud.test']),
		);
	}

	public function testAUserWithNoStatusIsAbsentRatherThanReportedOffline(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->givenStatuses([]);

		// getUserStatuses() drops statuses older than INVALIDATE_STATUS_THRESHOLD, so "no entry"
		// means we genuinely do not know - which must render as unknown, not as offline.
		$this->assertSame([], $this->request(['bill@cloud.test']));
	}

	public function testNothingIsAnsweredWhenTheFeatureIsDisabled(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->givenStatuses(['bill' => [IUserStatus::ONLINE, null]]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueBool')
			->willReturnCallback(static fn (string $key, bool $default = false): bool => $key !== 'federation_presence_enabled' && $default);

		// The admin switch holds on the answering side too: asymmetry is fine and needs no handshake
		$this->assertSame([], $this->request(['bill@cloud.test'], $this->buildProvider($appConfig)));
	}

	public function testAnOversizedRequestIsTruncated(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->statusManager->expects($this->never())->method('getUserStatuses');

		$cloudIds = array_map(static fn (int $i): string => 'user' . $i . '@cloud.test', range(1, 500));
		$cloudIds[] = 'bill@cloud.test';

		// Only the first 200 are considered, so a hostile list cannot turn one request into a
		// 500-user lookup. bill is past the cut, so nothing is left to look up at all.
		$this->assertSame([], $this->request($cloudIds));
	}

	public function testAnInviteThatDoesNotAuthenticateIsRefusedBeforeAnythingIsLookedUp(): void {
		$this->invitationMapper->method('getByRemoteAndAccessToken')
			->willThrowException(new DoesNotExistException('no such invite'));
		$this->givenRoomParticipants(42, ['bill']);
		$this->givenLocalAccounts(['bill']);
		$this->statusManager->expects($this->never())->method('getUserStatuses');

		$this->expectException(ShareNotFound::class);
		$this->request(['bill@cloud.test']);
	}

	public function testAnInviteForARoomThatIsNotFederatedIsRefused(): void {
		$this->givenInvitationForRoom('https://host.test', 7, 'secret', localRoomId: 42);
		// One of this server's own rooms, which a remote invite must never be able to reach
		$this->givenRoomParticipants(42, ['bill'], federated: false);
		$this->givenLocalAccounts(['bill']);
		$this->statusManager->expects($this->never())->method('getUserStatuses');

		$this->expectException(ShareNotFound::class);
		$this->request(['bill@cloud.test']);
	}
}
