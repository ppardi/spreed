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

	beforeEach(() => {
		vi.useFakeTimers()
		vi.stubGlobal('WebSocket', FakeWebSocket)
		announcedFeatures = []
		EventBus.on('signaling-supported-features', onFeatures)
	})

	afterEach(() => {
		EventBus.off('signaling-supported-features', onFeatures)
		vi.unstubAllGlobals()
		vi.useRealTimers()
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
})
