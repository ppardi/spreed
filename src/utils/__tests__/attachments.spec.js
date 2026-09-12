/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CONVERSATION } from '../../constants.ts'
import { getTalkConfig, hasTalkFeature } from '../../services/CapabilitiesManager.ts'
import { canUploadFilesInConversation } from '../attachments.ts'

vi.mock('../../services/CapabilitiesManager.ts', () => ({
	getTalkConfig: vi.fn(),
	hasTalkFeature: vi.fn(),
}))

describe('canUploadFilesInConversation', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(getTalkConfig).mockReturnValue(true)
		vi.mocked(hasTalkFeature).mockReturnValue(false)
	})

	it('allows uploads in conversations of the own server', () => {
		expect(canUploadFilesInConversation({ token: 'abc', type: CONVERSATION.TYPE.GROUP, remoteServer: '' })).toBe(true)
		expect(getTalkConfig).toHaveBeenCalledWith('abc', 'attachments', 'allowed')
		expect(hasTalkFeature).not.toHaveBeenCalled()
	})

	it('blocks uploads when attachments are not allowed', () => {
		vi.mocked(getTalkConfig).mockReturnValue(false)
		expect(canUploadFilesInConversation({ token: 'abc', type: CONVERSATION.TYPE.GROUP })).toBe(false)
	})

	it('allows uploads in federated conversations only when both servers support uploads', () => {
		const conversation = { token: 'abc', type: CONVERSATION.TYPE.GROUP, remoteServer: 'https://nc1.test' }
		expect(canUploadFilesInConversation(conversation)).toBe(false)
		expect(hasTalkFeature).toHaveBeenCalledWith('abc', 'federated-attachments-upload')

		vi.mocked(hasTalkFeature).mockReturnValue(true)
		expect(canUploadFilesInConversation(conversation)).toBe(true)
	})

	it('blocks uploads in federated conversations without conversation folders', () => {
		vi.mocked(hasTalkFeature).mockReturnValue(true)
		vi.mocked(getTalkConfig).mockImplementation((token, key1, key2) => key2 !== 'conversation-subfolders')
		expect(canUploadFilesInConversation({ token: 'abc', type: CONVERSATION.TYPE.GROUP, remoteServer: 'https://nc1.test' })).toBe(false)
	})

	it('blocks uploads in public federated conversations', () => {
		vi.mocked(hasTalkFeature).mockReturnValue(true)
		expect(canUploadFilesInConversation({ token: 'abc', type: CONVERSATION.TYPE.PUBLIC, remoteServer: 'https://nc1.test' })).toBe(false)
	})
})
