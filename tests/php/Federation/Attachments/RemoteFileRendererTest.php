<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Federation\Attachments;

use OCA\Talk\Authenticator;
use OCA\Talk\Federation\Attachments\ConversationFolder;
use OCA\Talk\Federation\Attachments\FileParameterBuilder;
use OCA\Talk\Federation\Attachments\LocalFileResolver;
use OCA\Talk\Federation\Attachments\RemoteFile;
use OCA\Talk\Federation\Attachments\RemoteFileRenderer;
use OCA\Talk\Model\AttachmentShare;
use OCA\Talk\Model\AttachmentShareMapper;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RemoteFileRendererTest extends TestCase {
	private const STORED = [
		'owner' => 'bill@nc2.test',
		'folderId' => '42',
		'path' => 'photo.png',
		'name' => 'photo.png',
		'size' => 7855,
		'mimetype' => 'image/png',
		'etag' => 'e1',
		'fileId' => '88',
		'width' => 64,
		'height' => 64,
	];
	private const DISPLAY = [
		'name' => 'photo.png',
		'size' => '7855',
		'mimetype' => 'image/png',
		'etag' => 'e1',
		'preview-available' => 'yes',
		'width' => '64',
		'height' => '64',
	];

	protected AttachmentShareMapper&MockObject $mapper;
	protected LocalFileResolver&MockObject $resolver;
	protected ConversationFolder&MockObject $conversationFolder;
	protected FileParameterBuilder&MockObject $builder;
	protected Authenticator&MockObject $authenticator;
	protected Room&MockObject $room;
	protected RemoteFileRenderer $renderer;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(AttachmentShareMapper::class);
		$this->resolver = $this->createMock(LocalFileResolver::class);
		$this->conversationFolder = $this->createMock(ConversationFolder::class);
		$this->builder = $this->createMock(FileParameterBuilder::class);
		$this->authenticator = $this->createMock(Authenticator::class);
		$cloudId = $this->createMock(ICloudId::class);
		$cloudId->method('getRemote')->willReturn('https://nc2.test');
		$cloudIdManager = $this->createMock(ICloudIdManager::class);
		$cloudIdManager->method('resolveCloudId')->with('bill@nc2.test')->willReturn($cloudId);
		$previewManager = $this->createMock(IPreview::class);
		$previewManager->method('isMimeSupported')->with('image/png')->willReturn(true);

		$this->room = $this->createMock(Room::class);
		$this->room->method('getId')->willReturn(12);
		$this->room->method('getType')->willReturn(Room::TYPE_GROUP);

		$this->renderer = new RemoteFileRenderer(
			$this->mapper,
			$this->resolver,
			$this->conversationFolder,
			$this->builder,
			$cloudIdManager,
			$previewManager,
			$this->authenticator,
		);
	}

	private function participant(string $actorType, string $actorId): Participant {
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn(Attendee::fromRow(['actor_type' => $actorType, 'actor_id' => $actorId]));
		return $participant;
	}

	private function expectRow(string $actorType, string $actorId, ?string $shareId): void {
		$row = null;
		if ($shareId !== null) {
			$row = new AttachmentShare();
			$row->setShareId($shareId);
		}
		$this->mapper->method('findForRecipient')
			->with(12, AttachmentShare::SOURCE_REMOTE_FOLDER, RemoteFile::sourceId('bill@nc2.test', '42'), $actorType, $actorId)
			->willReturn($row);
	}

	public function testWithoutViewerOnlyDisplayDataIsRendered(): void {
		$this->mapper->expects($this->never())->method('findForRecipient');
		$this->assertSame(
			['type' => 'federated-file'] + self::DISPLAY + ['server' => 'https://nc2.test'],
			$this->renderer->render($this->room, null, self::STORED),
		);
	}

	public function testHostUserGetsTheirReceivedCopy(): void {
		$this->expectRow(Attendee::ACTOR_USERS, 'paul', '31');
		$this->conversationFolder->method('targetForViewer')->with($this->room, 'paul')->willReturn('Talk/Room-abc');
		$node = $this->createMock(File::class);
		$this->resolver->expects($this->once())
			->method('resolve')
			->with('paul', 'https://nc2.test', '31', 'photo.png', 'Talk/Room-abc')
			->willReturn($node);
		$this->builder->method('forLocalNode')->with($node, self::DISPLAY)->willReturn(['type' => 'file', 'id' => '77']);

		$this->assertSame(['type' => 'file', 'id' => '77'], $this->renderer->render($this->room, $this->participant(Attendee::ACTOR_USERS, 'paul'), self::STORED));
	}

	public function testHostUserWithoutShareSeesNothing(): void {
		$this->expectRow(Attendee::ACTOR_USERS, 'paul', null);
		$this->resolver->expects($this->never())->method('resolve');
		$this->expectException(NotFoundException::class);
		$this->renderer->render($this->room, $this->participant(Attendee::ACTOR_USERS, 'paul'), self::STORED);
	}

	public function testShareNotAcceptedYet(): void {
		$this->expectRow(Attendee::ACTOR_USERS, 'paul', '31');
		$this->resolver->method('resolve')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->renderer->render($this->room, $this->participant(Attendee::ACTOR_USERS, 'paul'), self::STORED);
	}

	public function testEachFileIsResolvedOncePerRequest(): void {
		$this->expectRow(Attendee::ACTOR_USERS, 'paul', '31');
		$this->resolver->expects($this->once())->method('resolve')->willReturn($this->createMock(File::class));
		$this->builder->method('forLocalNode')->willReturn(['type' => 'file', 'id' => '77']);

		$paul = $this->participant(Attendee::ACTOR_USERS, 'paul');
		$this->renderer->render($this->room, $paul, self::STORED);
		$this->assertSame(['type' => 'file', 'id' => '77'], $this->renderer->render($this->room, $paul, self::STORED));
	}

	public function testSenderGetsAReferenceToTheirOwnFile(): void {
		$this->authenticator->method('supportsFederatedAttachments')->willReturn(true);
		$this->mapper->expects($this->never())->method('findForRecipient');
		$this->assertSame(
			['type' => 'federated-file'] + self::DISPLAY + ['server' => 'https://nc2.test', 'file-id' => '88'],
			$this->renderer->render($this->room, $this->participant(Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test'), self::STORED),
		);
	}

	public function testParticipantOnAThirdServerGetsTheirShare(): void {
		$this->authenticator->method('supportsFederatedAttachments')->willReturn(true);
		$this->expectRow(Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test', '41');
		$this->assertSame(
			['type' => 'federated-file'] + self::DISPLAY + ['server' => 'https://nc2.test', 'share-id' => '41', 'path' => 'photo.png'],
			$this->renderer->render($this->room, $this->participant(Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test'), self::STORED),
		);
	}

	public function testFederatedViewerWithoutTheHeaderSeesNothing(): void {
		$this->authenticator->method('supportsFederatedAttachments')->willReturn(false);
		$this->expectException(NotFoundException::class);
		$this->renderer->render($this->room, $this->participant(Attendee::ACTOR_FEDERATED_USERS, 'bill@nc2.test'), self::STORED);
	}

	public function testPublicConversationsKeepTodaysBehaviour(): void {
		$this->authenticator->method('supportsFederatedAttachments')->willReturn(true);
		$room = $this->createMock(Room::class);
		$room->method('getType')->willReturn(Room::TYPE_PUBLIC);
		$this->expectException(NotFoundException::class);
		$this->renderer->render($room, $this->participant(Attendee::ACTOR_FEDERATED_USERS, 'carol@nc3.test'), self::STORED);
	}

	public function testGuestsSeeNothing(): void {
		$this->authenticator->method('supportsFederatedAttachments')->willReturn(true);
		$this->expectException(NotFoundException::class);
		$this->renderer->render($this->room, $this->participant(Attendee::ACTOR_GUESTS, 'abc'), self::STORED);
	}

	public function testBrokenStoredDataIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->renderer->render($this->room, null, array_merge(self::STORED, ['path' => '../x']));
	}
}
