<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Proxy\TalkV1\Controller;

use OCA\Talk\Chat\Notifier;
use OCA\Talk\Federation\Attachments\AttachmentSharer;
use OCA\Talk\Federation\Attachments\FederatedFileConverter;
use OCA\Talk\Federation\Proxy\TalkV1\Controller\ChatController;
use OCA\Talk\Federation\Proxy\TalkV1\ProxyRequest;
use OCA\Talk\Federation\Proxy\TalkV1\UserConverter;
use OCA\Talk\Federation\ReadStatus\CommonReadStore;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomFormatter;
use OCA\Talk\Share\Helper\FilesMetadataCache;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ChatControllerTest extends TestCase {
	protected ProxyRequest&MockObject $proxy;
	protected UserConverter&MockObject $userConverter;
	protected AttachmentSharer&MockObject $sharer;
	protected FilesMetadataCache&MockObject $metadataCache;
	protected Room&MockObject $room;
	protected Participant&MockObject $participant;
	protected Folder&MockObject $folder;
	protected File&MockObject $file;
	protected ChatController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->proxy = $this->createMock(ProxyRequest::class);
		$this->userConverter = $this->createMock(UserConverter::class);
		$this->sharer = $this->createMock(AttachmentSharer::class);
		$this->metadataCache = $this->createMock(FilesMetadataCache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$this->room = $this->createMock(Room::class);
		$this->room->method('getRemoteServer')->willReturn('https://nc1.test');
		$this->room->method('getRemoteToken')->willReturn('abcdef');
		$this->participant = $this->createMock(Participant::class);
		$this->participant->method('getAttendee')->willReturn(Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'bill',
			'display_name' => 'Bill',
			'invited_cloud_id' => 'bill@nc2.test',
			'access_token' => 'secret',
		]));

		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('getId')->willReturn(42);
		$this->folder->method('getRelativePath')->with('/bill/files/Talk/Room-xyz/Bill-bill/photo.png')->willReturn('/photo.png');
		$this->file = $this->createMock(File::class);
		$this->file->method('getPath')->willReturn('/bill/files/Talk/Room-xyz/Bill-bill/photo.png');
		$this->file->method('getName')->willReturn('photo.png');
		$this->file->method('getSize')->willReturn(7855);
		$this->file->method('getMimeType')->willReturn('image/png');
		$this->file->method('getEtag')->willReturn('e1');
		$this->file->method('getId')->willReturn(88);

		$this->controller = new ChatController(
			$this->proxy,
			$this->userConverter,
			$this->createMock(FederatedFileConverter::class),
			$this->createMock(ParticipantService::class),
			$this->createMock(RoomFormatter::class),
			$this->createMock(Notifier::class),
			$cacheFactory,
			$this->sharer,
			$this->metadataCache,
			$this->createMock(CommonReadStore::class),
		);
	}

	private function hostResponse(int $status): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		return $response;
	}

	/**
	 * The host's participant list, as the host sends it and as this server sees it after the conversion
	 */
	private function expectParticipantList(int $status, array $converted = []): IResponse&MockObject {
		$listResponse = $this->hostResponse($status);
		$this->proxy->method('get')
			->with('bill@nc2.test', 'secret', 'https://nc1.test/ocs/v2.php/apps/spreed/api/v4/room/abcdef/participants')
			->willReturn($listResponse);
		$hostData = [['actorType' => Attendee::ACTOR_USERS, 'actorId' => 'paul']];
		$this->userConverter->method('convertAttendees')
			->with($this->room, $hostData, 'actorType', 'actorId', 'displayName')
			->willReturn($converted);
		return $listResponse;
	}

	public function testSharesTheFolderThenPostsOnTheHost(): void {
		$listResponse = $this->expectParticipantList(Http::STATUS_OK, [
			['actorType' => Attendee::ACTOR_FEDERATED_USERS, 'actorId' => 'paul@nc1.test'],
			['actorType' => Attendee::ACTOR_USERS, 'actorId' => 'bill'],
			['actorType' => Attendee::ACTOR_GUESTS, 'actorId' => 'abc'],
			['actorType' => Attendee::ACTOR_FEDERATED_USERS, 'actorId' => 'carol@nc3.test'],
		]);
		$this->proxy->method('getOCSData')->with($listResponse)->willReturn([['actorType' => Attendee::ACTOR_USERS, 'actorId' => 'paul']]);
		$this->sharer->expects($this->once())
			->method('shareSenderFolder')
			->with($this->room, 'bill', $this->folder, ['paul@nc1.test', 'carol@nc3.test'])
			->willReturn([['recipient' => 'paul@nc1.test', 'shareId' => '21']]);
		$this->metadataCache->method('getImageMetadataForFileId')->with(88)->willReturn(['width' => 64, 'height' => 64, 'blurhash' => 'LKO2']);
		$this->proxy->expects($this->once())
			->method('post')
			->with('bill@nc2.test', 'secret', 'https://nc1.test/ocs/v2.php/apps/spreed/api/v1/chat/abcdef/federated-attachment', [
				'folderId' => '42',
				'file' => [
					'path' => 'photo.png',
					'name' => 'photo.png',
					'size' => 7855,
					'mimetype' => 'image/png',
					'etag' => 'e1',
					'fileId' => '88',
					'width' => 64,
					'height' => 64,
					'blurhash' => 'LKO2',
				],
				'shares' => [['recipient' => 'paul@nc1.test', 'shareId' => '21']],
				'talkMetaData' => '{"caption":"Look"}',
				'referenceId' => 'ref1',
				'actorDisplayName' => 'Bill',
			])
			->willReturn($this->hostResponse(Http::STATUS_CREATED));

		$this->assertNull($this->controller->postAttachment($this->room, $this->participant, $this->folder, $this->file, '{"caption":"Look"}', 'ref1'));
	}

	public function testNoSharingWithoutTheParticipantList(): void {
		// An error answer would read as "nobody is left" and remove every share of the folder (ruling R12)
		$this->expectParticipantList(Http::STATUS_NOT_FOUND);
		$this->sharer->expects($this->never())->method('shareSenderFolder');
		$this->proxy->expects($this->never())->method('post');

		$response = $this->controller->postAttachment($this->room, $this->participant, $this->folder, $this->file, '', 'ref1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'participants'], $response->getData());
	}

	public function testHostErrorIsPassedOn(): void {
		$listResponse = $this->expectParticipantList(Http::STATUS_OK);
		$this->sharer->method('shareSenderFolder')->willReturn([]);
		$this->metadataCache->method('getImageMetadataForFileId')->willThrowException(new FilesMetadataNotFoundException('none'));
		$hostResponse = $this->hostResponse(Http::STATUS_BAD_REQUEST);
		$this->proxy->method('post')->willReturn($hostResponse);
		$this->proxy->method('getOCSData')->willReturnCallback(
			fn (IResponse $response): array => $response === $hostResponse ? ['error' => 'file'] : [['actorType' => Attendee::ACTOR_USERS, 'actorId' => 'paul']],
		);

		$response = $this->controller->postAttachment($this->room, $this->participant, $this->folder, $this->file, '', 'ref1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'file'], $response->getData());
	}

	public function testUnexpectedHostStatusBecomesBadRequest(): void {
		$this->expectParticipantList(Http::STATUS_OK);
		$this->sharer->method('shareSenderFolder')->willReturn([]);
		$hostResponse = $this->hostResponse(Http::STATUS_CONFLICT);
		$this->proxy->method('post')->willReturn($hostResponse);
		$this->proxy->expects($this->once())->method('logUnexpectedStatusCode')->willReturn(Http::STATUS_BAD_REQUEST);
		$this->proxy->method('getOCSData')->willReturnCallback(
			fn (IResponse $response): array => $response === $hostResponse ? [] : [['actorType' => Attendee::ACTOR_USERS, 'actorId' => 'paul']],
		);

		$response = $this->controller->postAttachment($this->room, $this->participant, $this->folder, $this->file, '', 'ref1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'remote'], $response->getData());
	}

	public function testSearchIsForwardedToTheHost(): void {
		$hostResponse = $this->hostResponse(Http::STATUS_OK);
		$this->proxy->expects($this->once())
			->method('get')
			->with('bill@nc2.test', 'secret', 'https://nc1.test/ocs/v2.php/apps/spreed/api/v1/chat/abcdef/search', [
				'term' => 'essa',
				'since' => 1700000000,
				'until' => 0,
				'actorType' => Attendee::ACTOR_FEDERATED_USERS,
				'actorId' => 'bill@nc2.test',
				'offset' => 10,
				'limit' => 20,
			])
			->willReturn($hostResponse);
		$hostMessages = [['id' => 12, 'actorType' => Attendee::ACTOR_FEDERATED_USERS, 'actorId' => 'bill@nc2.test', 'actorDisplayName' => 'Bill', 'timestamp' => 1700000001, 'message' => 'Message 2', 'messageParameters' => []]];
		$converted = [['id' => 12, 'actorType' => Attendee::ACTOR_USERS, 'actorId' => 'bill', 'actorDisplayName' => 'Bill', 'timestamp' => 1700000001, 'message' => 'Message 2', 'messageParameters' => []]];
		$this->proxy->method('getOCSData')->with($hostResponse)->willReturn($hostMessages);
		$this->userConverter->expects($this->once())
			->method('convertMessages')
			->with($this->room, $hostMessages)
			->willReturn($converted);

		$this->assertSame($converted, $this->controller->searchMessages($this->room, $this->participant, 'essa', 1700000000, 0, Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test', 10, 20));
	}

	public function testSearchFindsNothingWhenTheHostDoesNotAnswerIt(): void {
		// Official Talk and older builds don't have the endpoint (404); a participant held in the lobby gets 403
		$this->proxy->method('get')->willReturn($this->hostResponse(Http::STATUS_NOT_FOUND));
		$this->proxy->expects($this->never())->method('getOCSData');
		$this->userConverter->expects($this->never())->method('convertMessages');

		$this->assertSame([], $this->controller->searchMessages($this->room, $this->participant, 'essa', 0, 0, '', '', 0, 20));
	}

	public function testSearchIgnoresANonListAnswerFromTheHost(): void {
		// e.g. an error body answered with a 200 status code
		$hostResponse = $this->hostResponse(Http::STATUS_OK);
		$this->proxy->method('get')->willReturn($hostResponse);
		$this->proxy->method('getOCSData')->with($hostResponse)->willReturn(['error' => 'x']);
		$this->userConverter->method('convertMessages')->willReturnArgument(1);

		$this->assertSame([], $this->controller->searchMessages($this->room, $this->participant, 'essa', 0, 0, '', '', 0, 20));
	}

	public function testSearchDropsMalformedEntriesButKeepsValidOnes(): void {
		$hostResponse = $this->hostResponse(Http::STATUS_OK);
		$this->proxy->method('get')->willReturn($hostResponse);
		$validMessage = ['id' => 12, 'actorType' => Attendee::ACTOR_FEDERATED_USERS, 'actorId' => 'bill@nc2.test', 'actorDisplayName' => 'Bill', 'timestamp' => 1700000001, 'message' => 'Message 2', 'messageParameters' => []];
		$hostMessages = [
			// Missing "timestamp" and "messageParameters"
			['id' => 11, 'actorType' => Attendee::ACTOR_FEDERATED_USERS, 'actorId' => 'bill@nc2.test', 'actorDisplayName' => 'Bill', 'message' => 'Message 1'],
			$validMessage,
			'not even an array',
		];
		$this->proxy->method('getOCSData')->with($hostResponse)->willReturn($hostMessages);
		$this->userConverter->expects($this->once())
			->method('convertMessages')
			->with($this->room, [$validMessage])
			->willReturn([$validMessage]);

		$this->assertSame([$validMessage], $this->controller->searchMessages($this->room, $this->participant, 'essa', 0, 0, '', '', 0, 20));
	}

	public function testFilterValidSearchResultsIgnoresANonArrayAnswer(): void {
		// Guards the (currently unreachable through ProxyRequest::getOCSData(), which is typed "?array") case of
		// "ocs.data" not being an array at all, so the helper stays safe if that ever changes.
		$method = new \ReflectionMethod($this->controller, 'filterValidSearchResults');
		$this->assertSame([], $method->invoke($this->controller, 'not-an-array'));
	}
}
