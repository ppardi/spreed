<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Federation\Attachments;

use OCA\Talk\Share\Helper\FilesMetadataCache;
use OCP\Files\Node;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\IPreview;
use OCP\IURLGenerator;

/**
 * Builds the `file` rich object for a node in the viewer's own storage
 * (same shape as SystemMessage::getFileFromNodeId() for local users)
 */
class FileParameterBuilder {
	public function __construct(
		private readonly IPreview $previewManager,
		private readonly FilesMetadataCache $metadataCache,
		private readonly IURLGenerator $url,
	) {
	}

	/**
	 * @param array<string, string> $reference The federated-file reference, its image dimensions are used when the
	 *                                         local server has no metadata for the received file
	 * @return array<string, string>
	 */
	public function forLocalNode(Node $node, array $reference): array {
		$pathSegments = explode('/', $node->getPath(), 4);
		$size = $node->getSize();
		$mimeType = $node->getMimeType();
		$isPreviewAvailable = $size > 0 && $this->previewManager->isMimeSupported($mimeType);

		$data = [
			'type' => 'file',
			'id' => (string)$node->getId(),
			'name' => $node->getName(),
			'size' => (string)$size,
			'path' => $pathSegments[3] ?? $node->getName(),
			'link' => $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $node->getId()]),
			'etag' => $node->getEtag(),
			'permissions' => (string)$node->getPermissions(),
			'mimetype' => $mimeType,
			'preview-available' => $isPreviewAvailable ? 'yes' : 'no',
			'hide-download' => 'no',
		];

		if ($isPreviewAvailable && str_starts_with($mimeType, 'image/')) {
			try {
				$metadata = $this->metadataCache->getImageMetadataForFileId($node->getId());
			} catch (FilesMetadataNotFoundException) {
				$metadata = [];
			}
			foreach (['width', 'height', 'blurhash'] as $key) {
				if (isset($metadata[$key])) {
					$data[$key] = (string)$metadata[$key];
				} elseif (isset($reference[$key])) {
					$data[$key] = $reference[$key];
				}
			}
		}

		return $data;
	}
}
