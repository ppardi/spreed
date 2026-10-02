/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { cloneDeep } from 'es-toolkit'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { computed, defineComponent, h, ref } from 'vue'
import { createStore, useStore } from 'vuex'
import { CHAT } from '../../constants.ts'
import { EventBus } from '../../services/EventBus.ts'
import { fetchMessages, getMessageContext, pollNewMessages } from '../../services/messagesService.ts'
import storeConfig from '../../store/storeConfig.js'
import { useChatStore } from '../../stores/chat.ts'
import { generateOCSErrorResponse, generateOCSResponse } from '../../test-helpers.js'
import { useGetMessages, useGetMessagesProvider } from '../useGetMessages.ts'

/*
 * "A message shows in the sidebar but not in the open chat" (Talk Desktop, conversations hosted by
 * another server). The signaling connection of the client is gone, so no chat relay arrives; the chat
 * still believes chat relay is supported and only runs the 2-minute fallback check. These tests drive
 * the real Vuex and chat stores against a fake server and assert on what the chat window would show:
 * the block of the chat store that contains the context message id (MessagesList.vue messagesList).
 */

const TOKEN = 'BILLROOM'
const OTHER_TOKEN = 'OTHERROOM'

const currentToken = vi.hoisted(() => ({ value: null }))

vi.mock('vuex', async () => {
	const vuex = await vi.importActual('vuex')
	return { ...vuex, useStore: vi.fn() }
})
vi.mock('vue-router', () => ({
	START_LOCATION: { name: undefined },
	useRoute: vi.fn(() => ({ name: 'conversation', hash: '', params: { token: currentToken.value.value } })),
}))
vi.mock('../useGetToken.ts', () => ({ useGetToken: () => currentToken.value }))
vi.mock('../useGetThreadId.ts', async () => {
	const { ref } = await import('vue')
	return { useGetThreadId: () => ref(0) }
})
vi.mock('../../services/messagesService.ts', async (importOriginal) => ({
	...(await importOriginal()),
	fetchMessages: vi.fn(),
	getMessageContext: vi.fn(),
	pollNewMessages: vi.fn(),
	updateLastReadMessage: vi.fn(() => Promise.resolve()),
}))

/**
 * A fake chat API of one server holding the messages of each conversation
 */
function createServer() {
	const messages = { [TOKEN]: [], [OTHER_TOKEN]: [] }

	const post = (token, count = 1) => {
		for (let i = 0; i < count; i++) {
			const id = (messages[token].at(-1)?.id ?? 0) + 1
			messages[token].push({
				id,
				token,
				actorType: 'federated_users',
				actorId: 'bill@cloud.pardi.net',
				actorDisplayName: 'Bill',
				message: `message ${id}`,
				messageType: 'comment',
				systemMessage: '',
				messageParameters: {},
				timestamp: 1_000_000 + id,
				isThread: false,
				threadId: 0,
				reactions: {},
			})
		}
		return messages[token].at(-1)
	}

	const answer = (list) => {
		if (list.length === 0) {
			// The API answers 304 when there is nothing new
			return Promise.reject(generateOCSErrorResponse({ status: 304 }))
		}
		return Promise.resolve(generateOCSResponse({
			payload: list,
			headers: { 'x-chat-last-given': String(Math.max(...list.map((m) => m.id))) },
		}))
	}

	const newer = (token, lastKnownMessageId, limit) => messages[token]
		.filter((message) => message.id > lastKnownMessageId).slice(0, limit)

	fetchMessages.mockImplementation(({ token, lastKnownMessageId, includeLastKnown, lookIntoFuture, limit }) => {
		if (lookIntoFuture === CHAT.FETCH_NEW) {
			return answer(messages[token].filter((message) => message.id > lastKnownMessageId
				|| (includeLastKnown && message.id === lastKnownMessageId)).slice(0, limit))
		}
		return answer(messages[token].filter((message) => message.id < lastKnownMessageId
			|| (includeLastKnown && message.id === lastKnownMessageId)).slice(-limit).reverse())
	})
	pollNewMessages.mockImplementation(({ token, lastKnownMessageId, limit }) => answer(newer(token, lastKnownMessageId, limit)))
	getMessageContext.mockImplementation(({ token, messageId, limit }) => answer(messages[token]
		.filter((message) => message.id > messageId - limit && message.id <= messageId + limit)))

	return { messages, post }
}

describe('useGetMessagesProvider: new messages while no chat relay arrives', () => {
	let server
	let store
	let conversations
	let shownIds

	/**
	 * What the sidebar holds for a conversation hosted elsewhere: the last message without its id
	 * (ProxyCacheMessage::jsonSerialize() has no "id")
	 *
	 * @param {string} token conversation token
	 * @param {object} lastMessage the newest message on the server
	 * @param {number} lastReadMessage read marker
	 */
	function sidebarRefresh(token, lastMessage, lastReadMessage = conversations[token].lastReadMessage) {
		const { id, ...withoutId } = lastMessage
		conversations[token] = { ...conversations[token], lastMessage: withoutId, lastReadMessage }
	}

	function mountChat() {
		const Child = defineComponent({
			setup() {
				const { contextMessageId } = useGetMessages()
				const chatStore = useChatStore()
				shownIds = computed(() => chatStore.getMessagesList(currentToken.value.value, { messageId: contextMessageId.value })
					.map((message) => message.id))
				return () => h('div')
			},
		})
		return mount(defineComponent({
			setup() {
				useGetMessagesProvider()
				return () => h(Child)
			},
		}))
	}

	beforeEach(() => {
		vi.useFakeTimers()
		setActivePinia(createPinia())
		server = createServer()
		currentToken.value = ref(TOKEN)

		conversations = {
			[TOKEN]: { token: TOKEN, remoteServer: 'https://cloud.pardi.net', lastReadMessage: 0, unreadMessages: 0 },
			[OTHER_TOKEN]: { token: OTHER_TOKEN, lastReadMessage: 0, unreadMessages: 0 },
		}

		const config = cloneDeep(storeConfig)
		config.modules.conversationsStore.getters.conversation = () => (token) => conversations[token]
		config.modules.conversationsStore.actions.updateConversationLastMessage = vi.fn()
		config.modules.conversationsStore.actions.updateConversationLastReadMessage = vi.fn()
		config.modules.conversationsStore.actions.updateConversationLastActive = vi.fn()
		config.modules.participantsStore.getters.findParticipant = () => () => ({ attendeeId: 1 })
		store = createStore(config)
		useStore.mockReturnValue(store)

		// History of Bill's conversation, all read
		const last = server.post(TOKEN, 200)
		sidebarRefresh(TOKEN, last, last.id)
		const otherLast = server.post(OTHER_TOKEN, 10)
		conversations[OTHER_TOKEN].lastReadMessage = otherLast.id
	})

	afterEach(() => {
		vi.clearAllMocks()
		vi.useRealTimers()
	})

	/**
	 * Opens the conversation with a signaling server that announces chat relay, then lets everything settle
	 */
	async function openWithChatRelay() {
		await vi.advanceTimersByTimeAsync(0)
		EventBus.emit('signaling-supported-features', ['chat-relay'])
		await vi.advanceTimersByTimeAsync(0)
	}

	test('a message posted after the relay died is shown after the 2-minute fallback check', async () => {
		mountChat()
		await openWithChatRelay()
		expect(shownIds.value.at(-1)).toBe(200)

		// Signaling is dead: Bill posts, only the sidebar learns about it
		sidebarRefresh(TOKEN, server.post(TOKEN))

		await vi.advanceTimersByTimeAsync(120_000)
		expect(shownIds.value.at(-1)).toBe(201)
	})

	test('a message posted after the relay died is shown, when earlier ones came by relay', async () => {
		mountChat()
		await openWithChatRelay()

		// Three messages arrive by chat relay while it still works
		for (let i = 0; i < 3; i++) {
			const message = server.post(TOKEN)
			EventBus.emit('signaling-message-received', { token: TOKEN, message: { ...message } })
			sidebarRefresh(TOKEN, message)
		}
		await vi.advanceTimersByTimeAsync(0)
		expect(shownIds.value.at(-1)).toBe(203)

		// Then signaling dies and Bill posts again
		sidebarRefresh(TOKEN, server.post(TOKEN))
		await vi.advanceTimersByTimeAsync(120_000)
		expect(shownIds.value.at(-1)).toBe(204)
	})

	/*
	 * Reproduced on 2026-10-02 before the fix: on the return visit the chat polled from the "last given by server" id
	 * kept from the previous visit (chatStore.lastGivenByServerMap), so the catch-up and every fallback check extended
	 * the OLD block (151-300, then 151-400) while the window showed the context block (451-500). Message 501 only
	 * appeared once the fallback had walked the old block up to the shown one, 100 messages per 2-minute round.
	 * Starting the chat now forgets that id (handleStartGettingMessagesPreconditions()).
	 */
	test('a message posted after the relay died is shown, when the conversation was opened before', async () => {
		mountChat()
		await openWithChatRelay()

		// Away in another conversation while Bill posts a lot and Paul reads it on the phone
		currentToken.value.value = OTHER_TOKEN
		await vi.advanceTimersByTimeAsync(0)
		EventBus.emit('signaling-supported-features', ['chat-relay'])
		await vi.advanceTimersByTimeAsync(0)
		const readOnPhone = server.post(TOKEN, 300)
		sidebarRefresh(TOKEN, readOnPhone, readOnPhone.id)

		// Back in Bill's conversation, relay works on joining, then signaling dies and Bill posts
		currentToken.value.value = TOKEN
		await openWithChatRelay()
		expect(shownIds.value.at(-1)).toBe(500)

		sidebarRefresh(TOKEN, server.post(TOKEN))
		await vi.advanceTimersByTimeAsync(120_000)
		expect(shownIds.value.at(-1)).toBe(501)
	})
})
