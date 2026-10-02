/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
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
vi.mock('../../services/signalingService.js', () => ({
	fetchSignalingSettings: vi.fn(),
	pullSignalingMessages: vi.fn(),
}))

class FakeWebSocket {
	static created = []

	constructor(url) {
		this.url = url
		this.sent = []
		FakeWebSocket.created.push(this)
	}

	send(data) {
		this.sent.push(JSON.parse(data))
	}

	close() {}
}

const settings = {
	token: 'localtoken',
	server: 'https://own.test/standalone-signaling/',
	signalingMode: 'external',
	helloAuthParams: { '2.0': { token: 'own-token', url: 'https://own.test/ocs/v2.php/apps/spreed/api/v3/signaling/backend' } },
}

/*
 * Close code 1001 "Going Away" is sent both by the browser while the page unloads and by a proxy that goes away, e.g.
 * Caddy closing every websocket on a config reload. Only the first must not reconnect: after the second, Talk Desktop
 * stayed without a signaling connection for hours (2026-10-02), so no chat messages were pushed any more.
 */
describe('signaling: a connection closed with 1001 "Going Away"', () => {
	let signaling

	beforeEach(() => {
		vi.useFakeTimers()
		vi.stubGlobal('WebSocket', FakeWebSocket)
		FakeWebSocket.created = []
		signaling = new Signaling.Standalone(settings, settings.server)
		signaling.connected = true
	})

	afterEach(() => {
		signaling.disconnect()
		vi.unstubAllGlobals()
		vi.clearAllMocks()
		vi.useRealTimers()
	})

	/**
	 * @param {number} code The close code
	 */
	function closeSocket(code) {
		FakeWebSocket.created.at(-1).onclose({ code })
	}

	test('reconnects when the server side goes away', async () => {
		closeSocket(1001)
		await vi.advanceTimersByTimeAsync(2_000)

		expect(FakeWebSocket.created).toHaveLength(2)
	})

	test('reconnects every time the server side goes away', async () => {
		closeSocket(1001)
		await vi.advanceTimersByTimeAsync(2_000)
		closeSocket(1001)
		await vi.advanceTimersByTimeAsync(4_000)

		expect(FakeWebSocket.created).toHaveLength(3)
	})

	test('does not reconnect when the page itself is going away', async () => {
		window.dispatchEvent(new Event('pagehide'))
		closeSocket(1001)
		await vi.advanceTimersByTimeAsync(2_000)

		expect(FakeWebSocket.created).toHaveLength(1)
	})

	test('reconnects again once a hidden page is shown again', async () => {
		window.dispatchEvent(new Event('pagehide'))
		window.dispatchEvent(new Event('pageshow'))
		closeSocket(1001)
		await vi.advanceTimersByTimeAsync(2_000)

		expect(FakeWebSocket.created).toHaveLength(2)
	})

	test('reconnects after an abnormal closure, as before', async () => {
		closeSocket(1006)
		await vi.advanceTimersByTimeAsync(2_000)

		expect(FakeWebSocket.created).toHaveLength(2)
	})

	test('stops listening to the page once disconnected', async () => {
		const other = new Signaling.Standalone(settings, settings.server)
		other.disconnect()

		window.dispatchEvent(new Event('pagehide'))
		expect(other._pageIsGoingAway).toBe(false)
		window.dispatchEvent(new Event('pageshow'))
	})
})
