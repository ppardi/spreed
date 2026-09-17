<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Proxy\TalkV1;

use OCA\Talk\Config as TalkConfig;
use OCA\Talk\Federation\Proxy\TalkV1\ProxyRequest;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
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
}
