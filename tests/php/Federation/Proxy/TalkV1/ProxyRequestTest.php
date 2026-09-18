<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Proxy\TalkV1;

use OCA\Talk\Config as TalkConfig;
use OCA\Talk\Federation\Proxy\TalkV1\ProxyRequest;
use OCA\Talk\Federation\ReadStatus\ReadPrivacySync;
use OCA\Talk\Participant;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ProxyRequestTest extends TestCase {
	public function testDefaultOptionsOptInToFederatedAttachments(): void {
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('en');

		$proxyRequest = new ProxyRequest(
			$this->createMock(IConfig::class),
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
			$l10nFactory,
			$this->createMock(IUserSession::class),
			$this->createMock(TalkConfig::class),
		);

		$options = self::invokePrivate($proxyRequest, 'generateDefaultRequestOptions', [null, null]);
		$this->assertSame('1', $options['headers']['X-Nextcloud-Talk-Federated-Attachments']);
	}

	public function testDefaultOptionsSendReadPrivacyPublic(): void {
		$options = $this->generateDefaultRequestOptionsForReadPrivacy(Participant::PRIVACY_PUBLIC);
		$this->assertSame('0', $options['headers'][ReadPrivacySync::REQUEST_HEADER]);
	}

	public function testDefaultOptionsSendReadPrivacyPrivate(): void {
		$options = $this->generateDefaultRequestOptionsForReadPrivacy(Participant::PRIVACY_PRIVATE);
		$this->assertSame('1', $options['headers'][ReadPrivacySync::REQUEST_HEADER]);
	}

	private function generateDefaultRequestOptionsForReadPrivacy(int $readPrivacy): array {
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('en');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$talkConfig = $this->createMock(TalkConfig::class);
		$talkConfig->method('getUserReadPrivacy')
			->with('user')
			->willReturn($readPrivacy);

		$proxyRequest = new ProxyRequest(
			$this->createMock(IConfig::class),
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
			$l10nFactory,
			$userSession,
			$talkConfig,
		);

		return self::invokePrivate($proxyRequest, 'generateDefaultRequestOptions', [null, null]);
	}
}
