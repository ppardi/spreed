/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { EventBus } from '../../services/EventBus.ts'
import Signaling from '../signaling.js'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(() => ({ hideToast: vi.fn() })),
	showWarning: vi.fn(() => ({ hideToast: vi.fn() })),
	TOAST_PERMANENT_TIMEOUT: -1,
}))
vi.mock('../e2ee/encryption.js', () => ({
	default: { isEnabled: () => false, isSupported: vi.fn() },
}))
vi.mock('../../store/index.js', () => ({
	default: { dispatch: vi.fn(), commit: vi.fn(), getters: {} },
}))
const fetchSignalingSettings = vi.hoisted(() => vi.fn())
vi.mock('../../services/signalingService.js', () => ({
	fetchSignalingSettings,
	pullSignalingMessages: vi.fn(),
}))

class FakeWebSocket {
	static last = null

	constructor(url) {
		this.url = url
		this.sent = []
		FakeWebSocket.last = this
	}

	send(data) {
		this.sent.push(JSON.parse(data))
	}

	close() {}
}

const federation = {
	server: 'https://host.test/standalone-signaling/',
	nextcloudServer: 'https://host.test',
	helloAuthParams: { token: 'old-federation-token' },
	roomId: 'remotetoken',
}
const settings = {
	token: 'localtoken',
	server: 'https://own.test/standalone-signaling/',
	signalingMode: 'external',
	helloAuthParams: { '2.0': { token: 'own-token', url: 'https://own.test/ocs/v2.php/apps/spreed/api/v3/signaling/backend' } },
	federation,
}
const freshSettings = { ...settings, federation: { ...federation, helloAuthParams: { token: 'fresh-federation-token' } } }

/**
 * @param {object} signalingSettings Settings as answered by the server
 */
function settingsResponse(signalingSettings) {
	return { data: { ocs: { data: structuredClone(signalingSettings) } } }
}

describe('signaling: link of a federated conversation to the signaling server of its host', () => {
	let signaling
	let socket
	let announcedFeatures

	const onFeatures = (features) => announcedFeatures.push(features)

	/**
	 * @param {object} message A message from the signaling server
	 */
	function receive(message) {
		socket.onmessage({ data: JSON.stringify(message) })
	}

	/**
	 * The room messages sent to the signaling server so far
	 */
	function sentRoomMessages() {
		return socket.sent.filter((message) => message.type === 'room')
	}

	/**
	 * A signaling connection as after a successful hello and join of the conversation "localtoken"
	 *
	 * @param {object} signalingSettings Signaling settings of the conversation
	 */
	function createJoinedSignaling(signalingSettings = settings) {
		signaling = new Signaling.Standalone(signalingSettings, signalingSettings.server)
		socket = FakeWebSocket.last
		signaling.connected = true
		signaling.sessionId = 'own-session'
		signaling.features = { 'chat-relay': true, federation: true }
		signaling.currentRoomToken = 'localtoken'
		signaling.nextcloudSessionId = 'nextcloud-session'
		signaling.signalingRoomJoined = 'localtoken'
	}

	/**
	 * Answers a join sent to the signaling server with an error
	 *
	 * @param {object} join The sent join message
	 * @param {string} code The error code
	 */
	function rejectJoin(join, code = 'federation_error') {
		receive({ id: join.id, type: 'error', error: { code, message: 'Joining failed' } })
	}

	/**
	 * A federated join sent after an own reconnect with a new session and rejected, so that a rejoin is pending (5 s)
	 */
	async function rejectedJoinWithPendingRetry() {
		signaling.signalingRoomJoined = null
		signaling._joinRoomSuccess('localtoken', 'nextcloud-session')
		const [firstJoin] = sentRoomMessages()
		rejectJoin(firstJoin)
		await vi.advanceTimersByTimeAsync(0)
		const [, , secondJoin] = sentRoomMessages()
		rejectJoin(secondJoin)
	}

	/**
	 * Sent joins (without the leaves)
	 */
	function sentJoins() {
		return sentRoomMessages().filter((message) => message.room.roomid !== '')
	}

	beforeEach(() => {
		vi.useFakeTimers()
		vi.stubGlobal('WebSocket', FakeWebSocket)
		announcedFeatures = []
		EventBus.on('signaling-supported-features', onFeatures)
		fetchSignalingSettings.mockResolvedValue(settingsResponse(freshSettings))
		// console.error/warn are set up to fail the test otherwise, but the recovery logs expected failures with them
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		EventBus.off('signaling-supported-features', onFeatures)
		vi.unstubAllGlobals()
		vi.useRealTimers()
		vi.clearAllMocks()
	})

	test('chat relay is withheld while the link is interrupted and announced again when it is resumed', () => {
		createJoinedSignaling()

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		expect(signaling.federationLinkInterrupted).toBe(true)
		receive({ type: 'event', event: { target: 'room', type: 'federation_resumed', resumed: true } })

		expect(signaling.federationLinkInterrupted).toBe(false)
		expect(announcedFeatures).toEqual([['federation'], ['chat-relay', 'federation']])
		expect(sentRoomMessages()).toEqual([])
	})

	test('a successful join ends an interruption', () => {
		createJoinedSignaling()

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		signaling._joinRoomSuccess('localtoken', 'nextcloud-session')
		const [join] = sentRoomMessages()
		receive({ id: join.id, type: 'room', room: { roomid: 'localtoken' } })

		expect(signaling.federationLinkInterrupted).toBe(false)
		expect(announcedFeatures).toEqual([['federation'], ['chat-relay', 'federation']])
	})

	test('disconnecting forgets an interruption', () => {
		createJoinedSignaling()

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		signaling.disconnect()

		expect(signaling.federationLinkInterrupted).toBe(false)
	})

	test('joins the conversation again with its fresh settings when the signaling server gave up the link', async () => {
		createJoinedSignaling()

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)

		expect(fetchSignalingSettings).toHaveBeenCalledWith({ token: 'localtoken' }, {})
		const [leave, join] = sentRoomMessages()
		expect(leave.room).toEqual({ roomid: '' })
		expect(join.room.roomid).toBe('localtoken')
		expect(join.room.sessionid).toBe('nextcloud-session')
		expect(join.room.federation.token).toBe('fresh-federation-token')
		expect(join.room.federation.roomid).toBe('remotetoken')

		receive({ id: join.id, type: 'room', room: { roomid: 'localtoken' } })
		expect(announcedFeatures).toEqual([['federation'], ['chat-relay', 'federation']])
	})

	test('an id-less expired token in a joined federated conversation is recovered without a prior interruption event', async () => {
		createJoinedSignaling()

		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)

		expect(sentRoomMessages()).toHaveLength(2)
		expect(announcedFeatures).toEqual([['federation']])
	})

	test('retries with a growing delay while the federated join is rejected, and starts over after a successful join', async () => {
		createJoinedSignaling()
		// As after an own reconnect with a new session: the conversation is joined again with the settings in use
		signaling.signalingRoomJoined = null
		signaling._joinRoomSuccess('localtoken', 'nextcloud-session')
		const [firstJoin] = sentRoomMessages()
		expect(firstJoin.room.federation.token).toBe('old-federation-token')

		rejectJoin(firstJoin, 'token_expired')
		await vi.advanceTimersByTimeAsync(0)
		expect(announcedFeatures).toEqual([['federation']])
		const [, , secondJoin] = sentRoomMessages()
		expect(secondJoin.room.federation.token).toBe('fresh-federation-token')

		rejectJoin(secondJoin)
		await vi.advanceTimersByTimeAsync(4_999)
		expect(sentRoomMessages()).toHaveLength(3)
		await vi.advanceTimersByTimeAsync(1)
		const [, , , , thirdJoin] = sentRoomMessages()
		expect(thirdJoin.room.roomid).toBe('localtoken')

		receive({ id: thirdJoin.id, type: 'room', room: { roomid: 'localtoken' } })
		expect(announcedFeatures.at(-1)).toEqual(['chat-relay', 'federation'])

		// A later failure is handled right away again
		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)
		expect(sentRoomMessages()).toHaveLength(7)
	})

	test('an expired token answering a federated join does not refresh the settings of the own connection', async () => {
		createJoinedSignaling()
		const updateSettings = vi.fn()
		signaling.on('updateSettings', updateSettings)
		signaling.signalingRoomJoined = null
		signaling._joinRoomSuccess('localtoken', 'nextcloud-session')
		const [firstJoin] = sentRoomMessages()

		rejectJoin(firstJoin, 'token_expired')
		await vi.advanceTimersByTimeAsync(0)

		expect(updateSettings).not.toHaveBeenCalled()
		expect(signaling._pendingUpdateSettingsPromise).toBeUndefined()
	})

	test('retries after the backoff when the settings cannot be fetched (host unreachable)', async () => {
		createJoinedSignaling()
		fetchSignalingSettings.mockRejectedValueOnce(new Error('Request failed with status code 422'))

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)
		expect(sentRoomMessages()).toEqual([])

		await vi.advanceTimersByTimeAsync(5_000)
		expect(fetchSignalingSettings).toHaveBeenCalledTimes(2)
		const [leave, join] = sentRoomMessages()
		expect(leave.room).toEqual({ roomid: '' })
		expect(join.room.federation.token).toBe('fresh-federation-token')
	})

	test('stops and keeps polling when the conversation has no signaling server of its host any more', async () => {
		createJoinedSignaling()
		fetchSignalingSettings.mockResolvedValue(settingsResponse({ ...settings, federation: null }))

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(60_000)

		expect(fetchSignalingSettings).toHaveBeenCalledOnce()
		expect(sentRoomMessages()).toEqual([])
		expect(signaling.federationLinkInterrupted).toBe(true)
	})

	test('attempts do not overlap', async () => {
		createJoinedSignaling()
		let answerSettings
		fetchSignalingSettings.mockReturnValueOnce(new Promise((resolve) => {
			answerSettings = resolve
		}))

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)
		receive({ type: 'error', error: { code: 'federation_error', message: 'Connection lost' } })
		await vi.advanceTimersByTimeAsync(60_000)
		answerSettings(settingsResponse(freshSettings))
		await vi.advanceTimersByTimeAsync(0)

		expect(fetchSignalingSettings).toHaveBeenCalledOnce()
		expect(sentRoomMessages()).toHaveLength(2)
	})

	test('already joined counts as joined', async () => {
		createJoinedSignaling()
		signaling.signalingRoomJoined = null
		signaling._joinRoomSuccess('localtoken', 'nextcloud-session')
		const [join] = sentRoomMessages()

		rejectJoin(join, 'already_joined')
		await vi.advanceTimersByTimeAsync(60_000)

		expect(sentRoomMessages()).toHaveLength(1)
		expect(signaling.signalingRoomJoined).toBe('localtoken')
		expect(announcedFeatures).toEqual([['chat-relay', 'federation']])
	})

	test('a link resumed by the signaling server needs no rejoin', async () => {
		createJoinedSignaling()
		await rejectedJoinWithPendingRetry()

		receive({ type: 'event', event: { target: 'room', type: 'federation_resumed', resumed: false } })
		await vi.advanceTimersByTimeAsync(60_000)

		expect(sentRoomMessages()).toHaveLength(3)
		expect(announcedFeatures.at(-1)).toEqual(['chat-relay', 'federation'])
	})

	test('other errors keep their handling', async () => {
		createJoinedSignaling()
		const rejoin = vi.spyOn(signaling, '_joinRoomSuccess')

		// Without a prior interruption, e.g. the answer to a message sent without callback
		receive({ type: 'error', error: { code: 'processing_failed', message: 'Processing failed' } })
		// Answer to an own request
		receive({ id: '99', type: 'error', error: { code: 'not_allowed', message: 'Not allowed' } })
		await vi.advanceTimersByTimeAsync(60_000)

		expect(rejoin).not.toHaveBeenCalled()
		expect(fetchSignalingSettings).not.toHaveBeenCalled()
		expect(announcedFeatures).toEqual([])
	})

	test('an expired token of the own connection keeps its handling', async () => {
		createJoinedSignaling()
		const rejoin = vi.spyOn(signaling, '_joinRoomSuccess')
		const updateSettings = vi.fn()
		signaling.on('updateSettings', updateSettings)

		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		signaling.connected = false
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(60_000)

		expect(updateSettings).toHaveBeenCalledOnce()
		expect(rejoin).not.toHaveBeenCalled()
		expect(fetchSignalingSettings).not.toHaveBeenCalled()
	})

	test('conversations hosted on this server are not joined again', async () => {
		createJoinedSignaling({ ...settings, federation: null })
		const rejoin = vi.spyOn(signaling, '_joinRoomSuccess')

		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(60_000)

		expect(rejoin).not.toHaveBeenCalled()
		expect(fetchSignalingSettings).not.toHaveBeenCalled()
	})

	test('an attempt while the own connection is being established again is sent by the next one', async () => {
		createJoinedSignaling()
		let answerSettings
		fetchSignalingSettings.mockReturnValueOnce(new Promise((resolve) => {
			answerSettings = resolve
		}))
		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)

		// The own connection is lost meanwhile (as in reconnect()): messages sent now would be dropped by connect()
		signaling.socket = null
		answerSettings(settingsResponse(freshSettings))
		await vi.advanceTimersByTimeAsync(0)
		expect(sentRoomMessages()).toEqual([])
		expect(signaling.settings.federation.helloAuthParams.token).toBe('fresh-federation-token')

		// Connected again with the same session (resumed, so nothing joins by itself): the next attempt joins
		signaling.socket = socket
		await vi.advanceTimersByTimeAsync(5_000)
		const [leave, join] = sentRoomMessages()
		expect(leave.room).toEqual({ roomid: '' })
		expect(join.room.roomid).toBe('localtoken')
	})

	test('fresh settings do not replace the settings of another conversation that is being opened', async () => {
		createJoinedSignaling()
		let answerSettings
		fetchSignalingSettings.mockReturnValueOnce(new Promise((resolve) => {
			answerSettings = resolve
		}))
		receive({ type: 'event', event: { target: 'room', type: 'federation_interrupted' } })
		receive({ type: 'error', error: { code: 'token_expired', message: 'The token is expired.' } })
		await vi.advanceTimersByTimeAsync(0)

		// As connectSignaling(): the settings of the next conversation are loaded before it is joined
		const otherSettings = { ...settings, token: 'othertoken', federation: null }
		signaling.setSettings(otherSettings)
		answerSettings(settingsResponse(freshSettings))
		await vi.advanceTimersByTimeAsync(0)

		expect(signaling.settings).toBe(otherSettings)
		expect(sentRoomMessages()).toEqual([])
	})

	test('leaving the conversation or disconnecting cancels a pending rejoin', async () => {
		const leaveActions = [
			() => { signaling.currentRoomToken = null },
			() => signaling.disconnect(),
		]
		for (const leave of leaveActions) {
			createJoinedSignaling()
			await rejectedJoinWithPendingRetry()
			const rejoin = vi.spyOn(signaling, '_joinRoomSuccess')

			leave()
			await vi.advanceTimersByTimeAsync(60_000)

			expect(rejoin).not.toHaveBeenCalled()
		}
	})

	test('opening another conversation starts the recovery over', async () => {
		createJoinedSignaling()
		await rejectedJoinWithPendingRetry()

		// As signalingJoinConversation(): the settings of the other conversation, then joining it
		signaling.setSettings({ ...settings, token: 'othertoken', federation: { ...federation, roomId: 'otherremotetoken' } })
		signaling.joinRoom('othertoken', 'nextcloud-session-2')
		await vi.advanceTimersByTimeAsync(60_000)
		expect(sentJoins().map((join) => join.room.roomid)).toEqual(['localtoken', 'localtoken', 'othertoken'])

		// A failure there is handled right away (the backoff of the previous conversation is gone)
		rejectJoin(sentJoins()[2], 'token_expired')
		await vi.advanceTimersByTimeAsync(0)
		expect(fetchSignalingSettings).toHaveBeenLastCalledWith({ token: 'othertoken' }, {})
	})

	test('an answer to an own leave does not close the conversation', () => {
		createJoinedSignaling()

		receive({ id: '42', type: 'room', room: { roomid: '' } })

		expect(signaling.currentRoomToken).toBe('localtoken')
	})

	test('being removed from the conversation by the signaling server still closes it', () => {
		createJoinedSignaling()

		receive({ type: 'room', room: { roomid: '' } })

		expect(signaling.currentRoomToken).toBeNull()
	})
})
