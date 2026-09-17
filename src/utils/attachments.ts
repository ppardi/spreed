/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { CONVERSATION } from '../constants.ts'
import { getTalkConfig, hasTalkFeature } from '../services/CapabilitiesManager.ts'

/**
 * Whether files can be uploaded into the conversation: from the device, pasted, dropped or recorded.
 * In a federated conversation the files stay on the own server, in the conversation folder, and are shared with the
 * other participants, which needs `federated-attachments-upload` on both servers. Not about sharing existing files
 * from Nextcloud.
 *
 * @param conversation the conversation
 * @param conversation.token conversation token
 * @param conversation.type conversation type
 * @param conversation.remoteServer host of a federated conversation, empty otherwise
 */
export function canUploadFilesInConversation({ token, type, remoteServer }: { token: string, type: number, remoteServer?: string | null }): boolean {
	if (!getTalkConfig(token, 'attachments', 'allowed')) {
		return false
	}
	if (!remoteServer) {
		return true
	}
	return type !== CONVERSATION.TYPE.PUBLIC
		&& getTalkConfig(token, 'attachments', 'conversation-subfolders') === true
		&& hasTalkFeature(token, 'federated-attachments-upload')
}
