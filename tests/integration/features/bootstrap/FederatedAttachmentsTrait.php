<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Step\Then;
use PHPUnit\Framework\Assert;

/**
 * Steps for file attachments in federated conversations.
 */
trait FederatedAttachmentsTrait {
	#[Then('/^user "([^"]*)" accepts all pending federated shares$/')]
	public function userAcceptsAllPendingFederatedShares(string $user): void {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/files_sharing/api/v1/remote_shares/pending');
		$this->assertStatusCode($this->response, 200);

		foreach ($this->getDataFromResponse($this->response) as $share) {
			$this->sendRequest('POST', '/apps/files_sharing/api/v1/remote_shares/pending/' . $share['id']);
			$this->assertStatusCode($this->response, 200);
		}
	}

	#[Then('/^user "([^"]*)" has (\d+) accepted federated shares$/')]
	public function userHasAcceptedFederatedShares(string $user, int $count): void {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/files_sharing/api/v1/remote_shares');
		$this->assertStatusCode($this->response, 200);
		Assert::assertCount($count, $this->getDataFromResponse($this->response));
	}

	#[Then('/^user "([^"]*)" sees the last file message in room "([^"]*)" as local file "([^"]*)"$/')]
	public function userSeesTheLastFileMessageAsLocalFile(string $user, string $identifier, string $name): void {
		$message = $this->getLastCommentInRoom($user, $identifier);
		$file = $message['messageParameters']['file'] ?? null;
		Assert::assertIsArray($file, 'Last message has no file parameter: ' . json_encode($message));
		Assert::assertSame('file', $file['type']);
		Assert::assertSame($name, $file['name']);

		// The file must be downloadable from the viewer's own server.
		$path = implode('/', array_map('rawurlencode', explode('/', $file['path'])));
		$this->sendRequestFullUrl('GET', $this->baseUrl . 'remote.php/dav/files/' . $user . '/' . $path);
		$this->assertStatusCode($this->response, 200);
	}

	#[Then('/^user "([^"]*)" sees the last file message in room "([^"]*)" as not available$/')]
	public function userSeesTheLastFileMessageAsNotAvailable(string $user, string $identifier): void {
		$message = $this->getLastCommentInRoom($user, $identifier);
		Assert::assertArrayNotHasKey('file', $message['messageParameters'] ?: []);
		// Remote viewers get their server's fallback, users of the host Talk's "no longer available" text
		Assert::assertMatchesRegularExpression('/(not|no longer) available/', $message['message']);
	}

	private function getLastCommentInRoom(string $user, string $identifier): array {
		$this->setCurrentUser($user);
		$this->sendRequest('GET', '/apps/spreed/api/v1/chat/' . self::$identifierToToken[$identifier] . '?' . http_build_query([
			'lookIntoFuture' => 0,
			'limit' => 20,
		]));
		$this->assertStatusCode($this->response, 200);

		$comments = array_values(array_filter(
			$this->getDataFromResponse($this->response),
			static fn (array $message): bool => $message['messageType'] === 'comment',
		));
		Assert::assertNotEmpty($comments, 'No chat message found in room ' . $identifier);
		usort($comments, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
		return $comments[0];
	}
}
