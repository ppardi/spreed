/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { getPreloadedUserStatus } from '../userStatus.ts'

describe('userStatus', () => {
	describe('getPreloadedUserStatus', () => {
		it('reads the status of a federated participant straight from a backend participant row', () => {
			// Protects the backend-to-badge coupling. getPreloadedUserStatus() only takes the
			// "preloaded via participants API" branch when the row has a 'statusMessage' key, which
			// is why RoomController writes statusMessage: null for federated participants. Drop
			// that key and this returns undefined: the federation badge goes untinted for everyone
			// while every test that mounts a ready-made preloadedUserStatus object stays green.
			expect(getPreloadedUserStatus({
				actorType: 'federated_users',
				actorId: 'bill@cloud.test',
				status: 'away',
				statusIcon: null,
				statusMessage: null,
				statusClearAt: null,
			})).toEqual({ status: 'away', message: null, icon: null })
		})

		it('reads nothing from a federated row that carries no statusMessage key', () => {
			// The same row without the key falls through every branch, which is the failure the
			// test above guards against on the backend side.
			expect(getPreloadedUserStatus({
				actorType: 'federated_users',
				actorId: 'bill@cloud.test',
				status: 'away',
			})).toBeUndefined()
		})
	})
})
