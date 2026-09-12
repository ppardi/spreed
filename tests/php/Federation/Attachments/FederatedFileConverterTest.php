<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Config;
use OCA\Talk\Federation\Attachments\ConversationFolder;
use OCA\Talk\Federation\Attachments\FederatedFileConverter;
use OCA\Talk\Federation\Attachments\FileParameterBuilder;
use OCA\Talk\Federation\Attachments\LocalFileResolver;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FederatedFileConverterTest extends TestCase {
	private const REFERENCE = [
		'type' => 'federated-file',
		'name' => 'photo1.jpg',
		'mimetype' => 'image/jpeg',
		'server' => 'https://nc1.test/',
		'share-id' => '6',
		'path' => 'photo1.jpg',
	];

	protected LocalFileResolver&MockObject $resolver;
	protected FileParameterBuilder&MockObject $builder;
	protected Config&MockObject $config;
	protected IUserSession&MockObject $userSession;
	protected IRootFolder&MockObject $rootFolder;
	protected IURLGenerator&MockObject $url;
	protected Room&MockObject $room;
	protected Participant&MockObject $participant;
	protected FederatedFileConverter $converter;

	public function setUp(): void {
		parent::setUp();
		$this->resolver = $this->createMock(LocalFileResolver::class);
		$this->builder = $this->createMock(FileParameterBuilder::class);
		$this->config = $this->createMock(Config::class);
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(true);
		$this->config->method('getAttachmentFolder')->with('bill')->willReturn('/Talk');
		$this->config->method('getConversationFolderName')->willReturn('Room-both-64z86muv');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$this->userSession = $this->createMock(IUserSession::class);

		$this->room = $this->createMock(Room::class);
		$this->room->method('getRemoteServer')->willReturn('https://nc1.test');
		$this->participant = $this->createMock(Participant::class);
		$this->participant->method('getAttendee')->willReturn(Attendee::fromRow([
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'bill',
		]));

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->url->method('getAbsoluteURL')->with('/')->willReturn('https://nc2.test/');

		$this->converter = new FederatedFileConverter(
			$this->resolver,
			$this->builder,
			new ConversationFolder($this->config, $this->userSession),
			$this->rootFolder,
			$this->url,
			$l,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function loginAs(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testMessagesWithoutReferenceAreUntouched(): void {
		$message = ['message' => 'Hello', 'messageParameters' => []];
		$this->resolver->expects($this->never())->method('resolve');
		$this->assertSame($message, $this->converter->convertMessage($this->room, $this->participant, $message));
	}

	public function testResolvedReferenceBecomesLocalFile(): void {
		$this->loginAs('bill');
		$node = $this->createMock(File::class);
		$this->resolver->expects($this->once())
			->method('resolve')
			->with('bill', 'https://nc1.test/', '6', 'photo1.jpg', 'Talk/Room-both-64z86muv')
			->willReturn($node);
		$this->builder->method('forLocalNode')->with($node, self::REFERENCE)->willReturn(['type' => 'file', 'id' => '188']);

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => self::REFERENCE],
		]);
		$this->assertSame(['type' => 'file', 'id' => '188'], $converted['messageParameters']['file']);
		$this->assertSame('{file}', $converted['message']);
	}

	public function testNoMoveWithoutTheParticipantsSession(): void {
		$this->loginAs(null); // e.g. inside the host's OCM notification (syncRemoteMessage)
		$this->resolver->expects($this->once())
			->method('resolve')
			->with('bill', 'https://nc1.test/', '6', 'photo1.jpg', null)
			->willReturn(null);

		$this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => self::REFERENCE],
		]);
	}

	public function testReferenceFromAThirdServerIsResolved(): void {
		// Bill viewing a file that Carol sent from her own server (design §6.7, ruling R5)
		$this->loginAs('bill');
		$node = $this->createMock(File::class);
		$this->resolver->expects($this->once())
			->method('resolve')
			->with('bill', 'https://nc3.test/', '6', 'photo1.jpg', 'Talk/Room-both-64z86muv')
			->willReturn($node);
		$this->builder->method('forLocalNode')->willReturn(['type' => 'file', 'id' => '190']);

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => array_merge(self::REFERENCE, ['server' => 'https://nc3.test/'])],
		]);
		$this->assertSame(['type' => 'file', 'id' => '190'], $converted['messageParameters']['file']);
	}

	public function testOwnFileIsFoundInTheViewersStorage(): void {
		// Bill viewing the file he sent from this server (design §6.6)
		$this->loginAs('bill');
		$reference = ['type' => 'federated-file', 'name' => 'mine.png', 'server' => 'https://nc2.test', 'file-id' => '88'];
		$node = $this->createMock(File::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())->method('getFirstNodeById')->with(88)->willReturn($node);
		$this->rootFolder->method('getUserFolder')->with('bill')->willReturn($userFolder);
		$this->resolver->expects($this->never())->method('resolve');
		$this->builder->method('forLocalNode')->with($node, $reference)->willReturn(['type' => 'file', 'id' => '88']);

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => $reference],
		]);
		$this->assertSame(['type' => 'file', 'id' => '88'], $converted['messageParameters']['file']);
	}

	public function testFileIdOfAnotherServerIsNotResolved(): void {
		$this->loginAs('bill');
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => ['type' => 'federated-file', 'name' => 'mine.png', 'server' => 'https://nc3.test', 'file-id' => '88']],
		]);
		$this->assertSame('*"mine.png" is not available*', $converted['message']);
	}

	public function testUnresolvedReferenceFallsBackToText(): void {
		$this->loginAs('bill');
		$this->resolver->method('resolve')->willReturn(null);

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => 'Look at this',
			'messageParameters' => ['actor' => ['type' => 'user', 'id' => 'paul'], 'file' => self::REFERENCE],
		]);
		$this->assertSame(['actor' => ['type' => 'user', 'id' => 'paul']], $converted['messageParameters']);
		$this->assertSame("*\"photo1.jpg\" is not available*\n\nLook at this", $converted['message']);
	}

	public function testFailureFallsBackToText(): void {
		$this->loginAs('bill');
		$this->resolver->method('resolve')->willReturn($this->createMock(File::class));
		$this->builder->method('forLocalNode')->willThrowException(new \RuntimeException('metadata broken'));

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => self::REFERENCE],
		]);
		$this->assertSame('*"photo1.jpg" is not available*', $converted['message']);
	}

	public function testMalformedReferenceFallsBackToText(): void {
		$this->loginAs('bill');
		// Values from the host that are not strings must not break the whole message list
		$reference = array_merge(self::REFERENCE, ['name' => ['photo1.jpg'], 'server' => 42]);

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => $reference],
		]);
		$this->assertSame('*"" is not available*', $converted['message']);
		$this->assertArrayNotHasKey('file', $converted['messageParameters']);
	}

	public function testEachFileIsResolvedOncePerRequest(): void {
		$this->loginAs('bill');
		$node = $this->createMock(File::class);
		$this->resolver->expects($this->once())->method('resolve')->willReturn($node);
		$this->builder->method('forLocalNode')->willReturn(['type' => 'file', 'id' => '188']);

		$message = ['message' => '{file}', 'messageParameters' => ['file' => self::REFERENCE]];
		$converted = $this->converter->convertMessages($this->room, $this->participant, [$message, $message]);
		$this->assertSame('188', $converted[1]['messageParameters']['file']['id']);
	}

	public function testParentIsConverted(): void {
		$this->loginAs('bill');
		$this->resolver->method('resolve')->willReturn(null);

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => 'Reply',
			'messageParameters' => [],
			'parent' => ['message' => '{file}', 'messageParameters' => ['file' => self::REFERENCE]],
		]);
		$this->assertSame('*"photo1.jpg" is not available*', $converted['parent']['message']);
	}

	public function testThreadInfoFirstAndLastAreConverted(): void {
		$this->loginAs('bill');
		$this->resolver->method('resolve')->willReturn(null);
		$fileMessage = ['message' => '{file}', 'messageParameters' => ['file' => self::REFERENCE]];

		$converted = $this->converter->convertThreadInfo($this->room, $this->participant, [
			'thread' => ['id' => 1],
			'first' => $fileMessage,
			'last' => $fileMessage,
		]);
		$this->assertSame('*"photo1.jpg" is not available*', $converted['first']['message']);
		$this->assertSame('*"photo1.jpg" is not available*', $converted['last']['message']);
		$this->assertSame(['id' => 1], $converted['thread']);
	}
}
