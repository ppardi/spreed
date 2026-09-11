<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A federated share of a conversation attachment source, received by one participant
 *
 * @method void setRoomId(int $roomId)
 * @method int getRoomId()
 * @method void setSourceType(string $sourceType)
 * @method string getSourceType()
 * @method void setSourceId(string $sourceId)
 * @method string getSourceId()
 * @method void setOwnerServer(string $ownerServer)
 * @method string getOwnerServer()
 * @method void setOwnerActorType(string $ownerActorType)
 * @method string getOwnerActorType()
 * @method void setOwnerActorId(string $ownerActorId)
 * @method string getOwnerActorId()
 * @method void setRecipientActorType(string $recipientActorType)
 * @method string getRecipientActorType()
 * @method void setRecipientActorId(string $recipientActorId)
 * @method string getRecipientActorId()
 * @method void setShareId(string $shareId)
 * @method string getShareId()
 * @method void setOrigin(string $origin)
 * @method string|null getOrigin()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getCreatedAt()
 */
class AttachmentShare extends Entity {
	/** Source is a TYPE_ROOM share on the host (Talk 24 conversation folder or a single file) */
	public const SOURCE_ROOM_SHARE = 'room_share';
	/** Source is a sender folder on a remote participant's server (Plan 2) */
	public const SOURCE_REMOTE_FOLDER = 'remote_folder';

	/** Talk created the federated share, so Talk removes it again when nothing uses it anymore */
	public const ORIGIN_CREATED = 'created';
	/** The federated share existed already (e.g. the user shared the file themselves): Talk never removes it */
	public const ORIGIN_ADOPTED = 'adopted';

	protected int $roomId = 0;
	protected string $sourceType = '';
	protected string $sourceId = '';
	protected string $ownerServer = '';
	protected string $ownerActorType = '';
	protected string $ownerActorId = '';
	protected string $recipientActorType = '';
	protected string $recipientActorId = '';
	protected string $shareId = '';
	protected ?string $origin = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('roomId', Types::BIGINT);
		$this->addType('sourceType', Types::STRING);
		$this->addType('sourceId', Types::STRING);
		$this->addType('ownerServer', Types::STRING);
		$this->addType('ownerActorType', Types::STRING);
		$this->addType('ownerActorId', Types::STRING);
		$this->addType('recipientActorType', Types::STRING);
		$this->addType('recipientActorId', Types::STRING);
		$this->addType('shareId', Types::STRING);
		$this->addType('origin', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
	}
}
