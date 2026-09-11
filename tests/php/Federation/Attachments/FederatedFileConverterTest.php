<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Config;
use OCA\Talk\Federation\Attachments\FederatedFileConverter;
use OCA\Talk\Federation\Attachments\FileParameterBuilder;
use OCA\Talk\Federation\Attachments\LocalFileResolver;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Files\File;
use OCP\IL10N;
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

		$this->converter = new FederatedFileConverter(
			$this->resolver,
			$this->builder,
			$this->config,
			$l,
			$this->userSession,
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

	public function testReferenceToAnotherServerIsNotResolved(): void {
		$this->loginAs('bill');
		$this->resolver->expects($this->never())->method('resolve');

		$converted = $this->converter->convertMessage($this->room, $this->participant, [
			'message' => '{file}',
			'messageParameters' => ['file' => array_merge(self::REFERENCE, ['server' => 'https://nc3.test/'])],
		]);
		$this->assertSame('*"photo1.jpg" is not available*', $converted['message']);
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
