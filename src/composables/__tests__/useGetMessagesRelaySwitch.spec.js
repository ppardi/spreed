/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { EventBus } from '../../services/EventBus.ts'
import { useGetMessagesProvider } from '../useGetMessages.ts'

const store = vi.hoisted(() => ({
	getters: {
		conversation: () => ({ token: 'TOKEN', lastReadMessage: 100 }),
		isInLobby: false,
		findParticipant: () => ({ attendeeId: 1 }),
		message: () => undefined,
	},
	dispatch: vi.fn(),
}))

vi.mock('vuex', () => ({
	createStore: vi.fn(),
	useStore: vi.fn(() => store),
}))
vi.mock('vue-router', () => ({
	START_LOCATION: { name: undefined },
	useRoute: vi.fn(() => ({ name: 'conversation', hash: '', params: { token: 'TOKEN' } })),
}))
vi.mock('../useGetToken.ts', async () => {
	const { ref } = await import('vue')
	return { useGetToken: () => ref('TOKEN') }
})
vi.mock('../useGetThreadId.ts', async () => {
	const { ref } = await import('vue')
	return { useGetThreadId: () => ref(0) }
})

describe('useGetMessagesProvider: switching between pushed chat messages and polling', () => {
	let resolveContext

	/**
	 * The timeout of every "pollNewMessages" request so far (0 = pushed messages mode, undefined = long polling)
	 */
	function pollTimeouts() {
		return store.dispatch.mock.calls
			.filter(([action]) => action === 'pollNewMessages')
			.map(([, payload]) => payload.timeout)
	}

	beforeEach(() => {
		vi.useFakeTimers()
		setActivePinia(createPinia())
		store.dispatch.mockImplementation((action) => {
			if (action === 'getMessageContext') {
				return new Promise((resolve) => {
					resolveContext = resolve
				})
			}
			if (action === 'pollNewMessages') {
				return Promise.resolve({ data: { ocs: { data: [] } } })
			}
			return Promise.resolve()
		})
	})

	afterEach(() => {
		vi.clearAllMocks()
		vi.useRealTimers()
	})

	test('polls when chat relay goes away after the chat was initialised with chat relay', async () => {
		mount(defineComponent({
			setup() {
				useGetMessagesProvider()
				return () => h('div')
			},
		}))

		// The signaling server announces chat relay while the chat context is still loading: no poll yet
		EventBus.emit('signaling-supported-features', ['chat-relay'])
		await vi.advanceTimersByTimeAsync(0)
		expect(pollTimeouts()).toEqual([])

		resolveContext()
		await vi.advanceTimersByTimeAsync(0)
		expect(pollTimeouts()).toEqual([0])

		// The link of the federated conversation to its host is interrupted: long polling
		EventBus.emit('signaling-supported-features', ['federation'])
		await vi.advanceTimersByTimeAsync(0)
		expect(pollTimeouts()).toEqual([0, undefined])

		// Restored: one catch-up request, then pushed messages again
		EventBus.emit('signaling-supported-features', ['chat-relay', 'federation'])
		await vi.advanceTimersByTimeAsync(0)
		expect(pollTimeouts().at(-1)).toBe(0)
	})
})
