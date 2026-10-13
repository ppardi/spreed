<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\Federation\BackendNotifier;
use OCA\Talk\Federation\FederationManager;
use OCA\Talk\Federation\RestrictionValidator;
use OCA\Talk\Model\RetryNotificationMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\OCM\Exceptions\OCMProviderException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class BackendNotifierUserStatusTest extends TestCase {
	protected ICloudFederationFactory&MockObject $cloudFederationFactory;
	protected ICloudFederationProviderManager&MockObject $federationProviderManager;
	protected AddressHandler&MockObject $addressHandler;
	protected RetryNotificationMapper&MockObject $retryNotificationMapper;
	protected LoggerInterface&MockObject $logger;
	protected ICloudFederationNotification&MockObject $notification;
	protected BackendNotifier $backendNotifier;

	public function setUp(): void {
		parent::setUp();

		$this->cloudFederationFactory = $this->createMock(ICloudFederationFactory::class);
		$this->federationProviderManager = $this->createMock(ICloudFederationProviderManager::class);
		$this->addressHandler = $this->createMock(AddressHandler::class);
		$this->retryNotificationMapper = $this->createMock(RetryNotificationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->notification = $this->createMock(ICloudFederationNotification::class);
		$this->cloudFederationFactory->method('getCloudFederationNotification')->willReturn($this->notification);
		$this->addressHandler->method('urlContainProtocol')->willReturn(true);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->with('/')->willReturn('https://host.test/index.php/');

		// Same ten arguments, same order, as tests/php/Federation/FederationTest::setUp() builds
		// it; the five mocks above are promoted to properties because these tests drive them.
		$this->backendNotifier = new BackendNotifier(
			$this->cloudFederationFactory,
			$this->addressHandler,
			$this->logger,
			$this->federationProviderManager,
			$this->createMock(IUserManager::class),
			$url,
			$this->retryNotificationMapper,
			$this->createMock(ITimeFactory::class),
			$this->createMock(ICloudIdManager::class),
			$this->createMock(RestrictionValidator::class),
		);
	}

	private function givenRemoteResponds(int $status, string $body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$this->federationProviderManager->method('sendCloudNotification')->willReturn($response);
	}

	private function givenRemoteThrows(\Throwable $e): void {
		$this->federationProviderManager->method('sendCloudNotification')->willThrowException($e);
	}

	private function request(): array|string|false|null {
		return $this->backendNotifier->requestUserStatuses('https://cloud.test', 7, 'token', 'roomtoken', ['bill@cloud.test']);
	}

	public function testSendsTheNotificationTypeWithTheCloudIds(): void {
		$this->notification->expects($this->once())
			->method('setMessage')
			->with(
				FederationManager::NOTIFICATION_USER_STATUS_REQUEST,
				FederationManager::TALK_ROOM_RESOURCE,
				'7',
				[
					'remoteServerUrl' => 'https://host.test',
					'sharedSecret' => 'token',
					'remoteToken' => 'roomtoken',
					'cloudIds' => ['bill@cloud.test'],
				],
			);
		$this->givenRemoteResponds(Http::STATUS_CREATED, '{}');

		$this->request();
	}

	public function testReturnsTheDecodedBodyOnSuccess(): void {
		$this->givenRemoteResponds(Http::STATUS_CREATED, json_encode(['bill@cloud.test' => ['status' => 'away', 'icon' => null]]));

		$this->assertSame(['bill@cloud.test' => ['status' => 'away', 'icon' => null]], $this->request());
	}

	public function testNeverEnqueuesARetry(): void {
		// A status is a point-in-time value: retrying it minutes later is useless, and the rows
		// would accumulate for as long as the other server is down.
		$this->givenRemoteResponds(Http::STATUS_INTERNAL_SERVER_ERROR, '');
		$this->retryNotificationMapper->expects($this->never())->method('insert');

		$this->assertNull($this->request());
	}

	public function testReturnsFalseWhenTheRemoteRejectsTheNotificationType(): void {
		// An un-upgraded server does not know this notification type, so its match statement
		// throws BadRequestException([$notificationType]). Core answers that with
		// BadRequestException::getReturnMessage(), which hardcodes message RESOURCE_NOT_FOUND and
		// names the type in validationErrors -- so the message alone cannot tell this apart from a
		// stale invite, and only the non-empty validationErrors identifies it. That is
		// "unsupported", which the caller caches for an hour.
		$this->givenRemoteResponds(Http::STATUS_BAD_REQUEST, '{"message":"' . FederationManager::OCM_RESOURCE_NOT_FOUND . '","validationErrors":[{"name":"' . FederationManager::NOTIFICATION_USER_STATUS_REQUEST . '","message":"NOT_FOUND"}]}');
		$this->retryNotificationMapper->expects($this->never())->method('insert');

		$this->assertFalse($this->request());
	}

	public function testReturnsRefusedWhenTheRemoteDoesNotKnowTheInvite(): void {
		// Same status code, entirely different cause: the invite we authenticated with is gone or
		// was replaced. The server is upgraded, so this is not "unsupported", but core throttles
		// the asker for each of these, so it is not a 30 second "unreachable" either.
		// Core answers ShareNotFound with the same message but an empty validationErrors.
		$this->givenRemoteResponds(Http::STATUS_BAD_REQUEST, '{"message":"' . FederationManager::OCM_RESOURCE_NOT_FOUND . '","validationErrors":[]}');
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(BackendNotifier::USER_STATUSES_REFUSED, $this->request());
	}

	public function testReturnsRefusedWhenTheRemoteFailsAuthentication(): void {
		// AuthenticationFailedException is answered 403 and throttled just like the 400 above
		$this->givenRemoteResponds(Http::STATUS_FORBIDDEN, '{"message":"' . FederationManager::OCM_RESOURCE_NOT_FOUND . '"}');
		$this->retryNotificationMapper->expects($this->never())->method('insert');

		$this->assertSame(BackendNotifier::USER_STATUSES_REFUSED, $this->request());
	}

	public function testReturnsNullForA400ThatIsNotAnOcmErrorShape(): void {
		// A reverse proxy or maintenance page can answer 400 in HTML. That is not the remote's
		// match statement rejecting the type, so it must not be cached as "not upgraded" for an hour.
		$this->givenRemoteResponds(Http::STATUS_BAD_REQUEST, '<html><body>400 Bad Request</body></html>');

		$this->assertNull($this->request());
	}

	public function testReturnsNullForA400WhoseJsonBodyIsAScalar(): void {
		$this->givenRemoteResponds(Http::STATUS_BAD_REQUEST, '"Bad Request"');

		$this->assertNull($this->request());
	}

	public function testReturnsNullWhenTheRemoteCannotBeReached(): void {
		$this->givenRemoteThrows(new OCMProviderException('unreachable'));

		$this->assertNull($this->request());
	}

	public function testReturnsNullOnAMalformedBody(): void {
		$this->givenRemoteResponds(Http::STATUS_CREATED, 'not json at all');

		$this->assertNull($this->request());
	}

	public function testReturnsNullOnAJsonBodyThatIsNotAMap(): void {
		$this->givenRemoteResponds(Http::STATUS_CREATED, '"a string"');

		$this->assertNull($this->request());
	}
}
