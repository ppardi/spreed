import { t } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount, shallowMount } from '@vue/test-utils'
import { describe, expect, it, test } from 'vitest'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import AvatarWrapper from './AvatarWrapper.vue'
import { ATTENDEE, AVATAR } from '../../constants.ts'

describe('AvatarWrapper.vue', () => {
	const USER_ID = 'user-id'
	const USER_NAME = 'John Doe'
	const PRELOADED_USER_STATUS = { status: 'online', message: null, icon: null }

	describe('render user avatar', () => {
		test('component renders NcAvatar with standard size by default', () => {
			const wrapper = shallowMount(AvatarWrapper, {
				props: {
					name: USER_NAME,
				},
			})

			const avatar = wrapper.findComponent(NcAvatar)
			expect(avatar.exists()).toBeTruthy()
			expect(avatar.props('size')).toBe(AVATAR.SIZE.DEFAULT)
		})

		test('component does render NcAvatar for non-users', () => {
			const wrapper = shallowMount(AvatarWrapper, {
				props: {
					name: 'Email Guest',
					source: ATTENDEE.ACTOR_TYPE.EMAILS,
				},
			})

			const avatar = wrapper.findComponent(NcAvatar)
			expect(avatar.exists()).toBeTruthy()
		})

		test('component does render NcAvatar for federated users', () => {
			const wrapper = shallowMount(AvatarWrapper, {
				props: {
					token: 'XXXTOKENXXX',
					name: 'Federated User',
					source: ATTENDEE.ACTOR_TYPE.FEDERATED_USERS,
				},
			})

			const avatar = wrapper.findComponent(NcAvatar)
			expect(avatar.exists()).toBeTruthy()
		})

		test('component renders NcAvatar with specified size', () => {
			const size = 22
			const wrapper = shallowMount(AvatarWrapper, {
				props: {
					name: USER_NAME,
					size,
				},
			})

			const avatar = wrapper.findComponent(NcAvatar)
			expect(avatar.props('size')).toBe(size)
		})

		test('component pass props to NcAvatar correctly', async () => {
			const wrapper = shallowMount(AvatarWrapper, {
				props: {
					id: USER_ID,
					name: USER_NAME,
					source: ATTENDEE.ACTOR_TYPE.USERS,
					showUserStatus: true,
					preloadedUserStatus: PRELOADED_USER_STATUS,
				},
			})

			const avatar = wrapper.findComponent(NcAvatar)
			await avatar.vm.$nextTick()

			expect(avatar.props('user')).toBe(USER_ID)
			expect(avatar.props('displayName')).toBe(USER_NAME)
			expect(avatar.props('hideStatus')).toBe(false)
			expect(avatar.props('verboseStatus')).toBe(true)
			expect(avatar.props('preloadedUserStatus')).toStrictEqual(PRELOADED_USER_STATUS)
			expect(avatar.props('size')).toBe(AVATAR.SIZE.DEFAULT)
		})
	})

	describe('render specific icons', () => {
		const testCases = [
			[null, ATTENDEE.CHANGELOG_BOT_ID, 'Talk updates', ATTENDEE.ACTOR_TYPE.BOTS, 'icon-changelog'],
			[null, ATTENDEE.SAMPLE_BOT_ID, 'Nextcloud', ATTENDEE.ACTOR_TYPE.BOTS, 'icon-changelog'],
			[null, 'federated_user/id', USER_NAME, ATTENDEE.ACTOR_TYPE.FEDERATED_USERS, 'icon-user'],
			[null, 'guest/id', '', ATTENDEE.ACTOR_TYPE.GUESTS, 'icon-user'],
			[null, 'guest/id', t('spreed', 'Guest'), ATTENDEE.ACTOR_TYPE.GUESTS, 'icon-user'],
			[null, 'guest/id', t('spreed', 'Guest'), ATTENDEE.ACTOR_TYPE.EMAILS, 'icon-user'],
			[null, 'deleted_users', '', ATTENDEE.ACTOR_TYPE.DELETED_USERS, 'icon-user'],
			['new', 'guest/id', 'test@mail.com', ATTENDEE.ACTOR_TYPE.EMAILS, 'icon-mail'],
			[null, 'sha-phone', '+12345...', ATTENDEE.ACTOR_TYPE.PHONES, 'icon-phone'],
			[null, 'team/id', 'Team', ATTENDEE.ACTOR_TYPE.CIRCLES, 'icon-team'],
			[null, 'group/id', 'Group', ATTENDEE.ACTOR_TYPE.GROUPS, 'icon-contacts'],
		]

		it.each(testCases)('renders for token \'%s\', id \'%s\', name \'%s\' and source \'%s\' icon \'%s\'', (token, id, name, source, result) => {
			const wrapper = shallowMount(AvatarWrapper, {
				props: { token, id, name, source },
			})

			const avatar = wrapper.find('.avatar')
			expect(avatar.exists()).toBeTruthy()
			expect(avatar.attributes('iconclass')).toContain(result)
		})
	})

	describe('render specific symbols', () => {
		const testCases = [
			['guest/id', USER_NAME, ATTENDEE.ACTOR_TYPE.GUESTS, USER_NAME.charAt(0)],
			['guest/id', USER_NAME, ATTENDEE.ACTOR_TYPE.EMAILS, USER_NAME.charAt(0)],
			['bot-id', USER_NAME, ATTENDEE.ACTOR_TYPE.BOTS, '>_'],
		]

		it.each(testCases)('renders for id \'%s\', name \'%s\' and source \'%s\' symbol \'%s\'', (id, name, source, result) => {
			const wrapper = mount(AvatarWrapper, {
				props: { name, source },
			})

			const avatar = wrapper.find('.avatar')
			expect(avatar.exists()).toBeTruthy()
			expect(avatar.text()).toBe(result)
		})
	})

	describe('federation badge presence', () => {
		it('tints the federation badge with the presence status', () => {
			const wrapper = mount(AvatarWrapper, {
				props: { source: ATTENDEE.ACTOR_TYPE.FEDERATED_USERS, id: 'bill@cloud.test', name: 'Bill', showUserStatus: true, preloadedUserStatus: { status: 'away' } },
			})
			expect(wrapper.find('.avatar-wrapper__user-status').classes()).toContain('avatar-wrapper__user-status--away')
		})

		it.each(['online', 'dnd', 'offline'])('tints the federation badge for %s', (status) => {
			const wrapper = mount(AvatarWrapper, {
				props: { source: ATTENDEE.ACTOR_TYPE.FEDERATED_USERS, id: 'bill@cloud.test', name: 'Bill', showUserStatus: true, preloadedUserStatus: { status } },
			})
			expect(wrapper.find('.avatar-wrapper__user-status').classes()).toContain(`avatar-wrapper__user-status--${status}`)
		})

		const mountBadge = (preloadedUserStatus) => mount(AvatarWrapper, {
			props: { source: ATTENDEE.ACTOR_TYPE.FEDERATED_USERS, id: 'bill@cloud.test', name: 'Bill', showUserStatus: true, preloadedUserStatus },
		})
		const badgeLabel = (wrapper) => wrapper.find('.avatar-wrapper__user-status').attributes('aria-label')

		it.each([
			['online', 'Online'],
			['away', 'Away'],
			['dnd', 'Do not disturb'],
			['offline', 'Offline'],
		])('labels a %s badge with the translated status, not the raw token', (status, word) => {
			const label = badgeLabel(mountBadge({ status }))
			expect(label).toBe(t('spreed', 'Federated user — {status}', { status: t('spreed', word) }))
			expect(label).toContain(t('spreed', word))
			if (status === 'dnd') {
				expect(label).not.toContain('dnd')
			}
		})

		it.each(['garbage', 'invisible', 'constructor', '__proto__', ''])('falls back to the plain label for the unrecognized token "%s"', (status) => {
			const label = badgeLabel(mountBadge({ status }))
			expect(label).toBe(t('spreed', 'Federated user'))
		})

		it('hides the status mark NcAvatar would draw in the badge corner for federated users', () => {
			// NcAvatar would otherwise paint its own mark where the globe sits
			expect(mountBadge({ status: 'away' }).findComponent(NcAvatar).props('hideStatus')).toBe(true)
			const local = mount(AvatarWrapper, {
				props: { source: ATTENDEE.ACTOR_TYPE.USERS, id: 'bill', name: 'Bill', showUserStatus: true, preloadedUserStatus: { status: 'away' } },
			})
			expect(local.findComponent(NcAvatar).props('hideStatus')).toBe(false)
		})

		it('uses the plain label when there is no status at all', () => {
			expect(badgeLabel(mountBadge(undefined))).toBe(t('spreed', 'Federated user'))
			expect(badgeLabel(mountBadge({}))).toBe(t('spreed', 'Federated user'))
		})

		it('leaves the badge untinted when the status is unknown', () => {
			const wrapper = mount(AvatarWrapper, {
				props: { source: ATTENDEE.ACTOR_TYPE.FEDERATED_USERS, id: 'bill@cloud.test', name: 'Bill', showUserStatus: true },
			})
			const classes = wrapper.find('.avatar-wrapper__user-status').classes()
			expect(classes.some((c) => c.startsWith('avatar-wrapper__user-status--'))).toBe(false)
		})
	})
})
