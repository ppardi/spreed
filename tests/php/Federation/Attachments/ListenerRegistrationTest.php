<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\AppInfo\Application;
use OCA\Talk\Chat\SystemMessage\Listener as SystemMessageListener;
use OCA\Talk\Events\SystemMessageSentEvent;
use OCA\Talk\Federation\Attachments\Listener as FederatedAttachmentsListener;
use OCA\Talk\Federation\Proxy\TalkV1\Notifier\MessageSentListener as TalkV1MessageSentListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Share\Events\ShareCreatedEvent;
use Test\TestCase;

/**
 * The federated share must exist before the remote servers are told about the message,
 * otherwise they fetch the message while the file is not shared yet (and never fetch it again).
 */
class ListenerRegistrationTest extends TestCase {
	/** @var array<string, list<array{listener: string, priority: int}>> */
	private array $registrations = [];

	public function setUp(): void {
		parent::setUp();
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')
			->willReturnCallback(function (string $event, string $listener, int $priority = 0): void {
				$this->registrations[$event][] = ['listener' => $listener, 'priority' => $priority];
			});
		(new Application())->register($context);
	}

	/**
	 * Order in which Nextcloud's event dispatcher (Symfony) calls the listeners of an event:
	 * higher priority first, the same priority in registration order
	 *
	 * @return list<string>
	 */
	private function callOrder(string $event): array {
		$byPriority = [];
		foreach ($this->registrations[$event] ?? [] as $registration) {
			$byPriority[$registration['priority']][] = $registration['listener'];
		}
		krsort($byPriority);
		return array_merge(...array_values($byPriority));
	}

	private function assertCalledBefore(string $event, string $first, string $second): void {
		$order = $this->callOrder($event);
		$this->assertContains($first, $order);
		$this->assertContains($second, $order);
		$this->assertLessThan(array_search($second, $order, true), array_search($first, $order, true), $first . ' must run before ' . $second . ' for ' . $event);
	}

	public function testLegacyShareIsSharedBeforeTheFileMessageIsPosted(): void {
		// SystemMessageListener posts "file_shared", which notifies the remote servers
		$this->assertCalledBefore(ShareCreatedEvent::class, FederatedAttachmentsListener::class, SystemMessageListener::class);
	}

	public function testFileMessageIsSharedBeforeRemoteServersAreNotified(): void {
		$this->assertCalledBefore(SystemMessageSentEvent::class, FederatedAttachmentsListener::class, TalkV1MessageSentListener::class);
	}
}
