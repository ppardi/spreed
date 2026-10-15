<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="avatar-wrapper" :class="avatarClass" :style="avatarStyle">
		<NcAvatar
			v-if="isSpecialAvatar"
			:key="(isDarkTheme ? 'dark-' : 'light-') + '_' + id"
			class="avatar"
			:user="id"
			:url="!isFederatedUser ? undefined : avatarUrl"
			:iconClass="iconClass"
			:displayName="name"
			:disableTooltip="disableTooltip"
			disableMenu
			isNoUser
			:hideStatus="!showUserStatus || isFederatedUser"
			:verboseStatus="false"
			:preloadedUserStatus="preloadedUserStatus ?? {}"
			:size="size">
			<template v-if="characterIcon" #icon>
				<div class="avatar" :class="characterIconClass">
					{{ characterIcon }}
				</div>
			</template>
		</NcAvatar>
		<NcAvatar
			v-else
			:key="id + (isDarkTheme ? '-dark' : '-light')"
			:user="id"
			:displayName="name"
			:menuContainer="menuContainer"
			:disableTooltip="disableTooltip"
			:disableMenu="disableMenu"
			:hideStatus="!showUserStatus || isFederatedUser"
			:verboseStatus="!showUserStatusCompact"
			:preloadedUserStatus="preloadedUserStatus"
			:size="size" />
		<!-- NcAvatar's own status mark is hidden for federated users above: it would sit in the
		     same corner as this span, and the local user_status API cannot resolve a cloud id.
		     Override user status for federated users: the globe still says "federated",
		     its color says whether they are around. No color means unknown, which is
		     deliberately distinct from offline. -->
		<span
			v-if="showUserStatus && isFederatedUser"
			class="avatar-wrapper__user-status"
			:class="federatedStatusClass"
			role="img"
			aria-hidden="false"
			:aria-label="federatedStatusLabel">
			<WebIcon :size="14" />
		</span>
		<NcLoadingIcon
			v-if="loading"
			:size="size / 2"
			class="loading-avatar" />
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { useIsDarkTheme } from '@nextcloud/vue/composables/useIsDarkTheme'
import { ref } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import WebIcon from 'vue-material-design-icons/Web.vue'
import { ATTENDEE, AVATAR } from '../../constants.ts'
import { getUserProxyAvatarOcsUrl } from '../../services/avatarService.ts'

export default {

	name: 'AvatarWrapper',

	components: {
		NcAvatar,
		WebIcon,
		NcLoadingIcon,
	},

	props: {
		token: {
			type: String,
			default: null,
		},

		name: {
			type: String,
			required: true,
		},

		id: {
			type: String,
			default: null,
		},

		source: {
			type: String,
			default: null,
		},

		size: {
			type: Number,
			default: AVATAR.SIZE.DEFAULT,
		},

		condensed: {
			type: Boolean,
			default: false,
		},

		condensedOverlap: {
			type: Number,
			default: 2,
		},

		offline: {
			type: Boolean,
			default: false,
		},

		highlighted: {
			type: Boolean,
			default: false,
		},

		disableTooltip: {
			type: Boolean,
			default: false,
		},

		disableMenu: {
			type: Boolean,
			default: false,
		},

		showUserStatus: {
			type: Boolean,
			default: false,
		},

		showUserStatusCompact: {
			type: Boolean,
			default: false,
		},

		preloadedUserStatus: {
			type: Object,
			default: undefined,
		},

		menuContainer: {
			type: String,
			default: undefined,
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	setup() {
		const isDarkTheme = useIsDarkTheme()

		const failed = ref(false)

		return {
			isDarkTheme,
			failed,
		}
	},

	computed: {
		// Determines which icon is displayed
		iconClass() {
			if (!this.source) {
				return ''
			}
			switch (this.source) {
				case ATTENDEE.ACTOR_TYPE.USERS:
				case ATTENDEE.ACTOR_TYPE.BRIDGED:
					return !this.failed ? '' : 'icon-user'
				case ATTENDEE.ACTOR_TYPE.EMAILS:
					return this.token === 'new' ? 'icon-mail' : !this.hasCustomName ? 'icon-user' : ''
				case ATTENDEE.ACTOR_TYPE.GUESTS:
					return !this.hasCustomName ? 'icon-user' : ''
				case ATTENDEE.ACTOR_TYPE.FEDERATED_USERS:
					return (this.token && !this.failed) ? '' : 'icon-user'
				case ATTENDEE.ACTOR_TYPE.DELETED_USERS:
					return 'icon-user'
				case ATTENDEE.ACTOR_TYPE.PHONES:
					return 'icon-phone'
				case ATTENDEE.ACTOR_TYPE.BOTS:
					return [ATTENDEE.CHANGELOG_BOT_ID, ATTENDEE.SAMPLE_BOT_ID].includes(this.id) ? 'icon-changelog' : ''
				case ATTENDEE.ACTOR_TYPE.CIRCLES:
					return 'icon-team'
				case ATTENDEE.ACTOR_TYPE.GROUPS:
				default:
					return 'icon-contacts'
			}
		},

		characterIconClass() {
			if (this.source === ATTENDEE.ACTOR_TYPE.EMAILS && this.token !== 'new' && this.hasCustomName) {
				return 'guest'
			} else if (this.source === ATTENDEE.ACTOR_TYPE.GUESTS && this.hasCustomName) {
				return 'guest'
			} else if (this.isBot) {
				return 'bot'
			}

			return undefined
		},

		avatarClass() {
			return {
				'avatar-wrapper--dark': this.isDarkTheme,
				'avatar-wrapper--offline': this.offline,
				'avatar-wrapper--condensed': this.condensed,
				'avatar-wrapper--highlighted': this.highlighted,
			}
		},

		avatarStyle() {
			return {
				'--avatar-size': this.size + 'px',
				'--condensed-overlap': this.condensedOverlap,
			}
		},

		isFederatedUser() {
			return this.source === ATTENDEE.ACTOR_TYPE.FEDERATED_USERS
		},

		federatedStatusClass() {
			const status = this.preloadedUserStatus?.status
			return status ? `avatar-wrapper__user-status--${status}` : null
		},

		federatedStatusLabel() {
			// Only known tokens are shown, translated; anything else falls back to the plain label
			const statusLabels = {
				online: t('spreed', 'Online'),
				away: t('spreed', 'Away'),
				dnd: t('spreed', 'Do not disturb'),
				busy: t('spreed', 'Busy'),
				offline: t('spreed', 'Offline'),
			}
			const token = this.preloadedUserStatus?.status
			const status = Object.hasOwn(statusLabels, token) ? statusLabels[token] : null
			return status
				? t('spreed', 'Federated user — {status}', { status })
				: t('spreed', 'Federated user')
		},

		isBot() {
			return this.source === ATTENDEE.ACTOR_TYPE.BOTS && this.id !== ATTENDEE.CHANGELOG_BOT_ID && this.id !== ATTENDEE.SAMPLE_BOT_ID
		},

		isGuestUser() {
			return [ATTENDEE.ACTOR_TYPE.GUESTS, ATTENDEE.ACTOR_TYPE.EMAILS].includes(this.source)
		},

		hasCustomName() {
			return this.name?.trim() && this.name !== t('spreed', 'Guest')
		},

		characterIcon() {
			if (this.isBot) {
				return '>_'
			}

			if (!this.isGuestUser || !this.hasCustomName || this.token === 'new') {
				return ''
			}
			return this.name?.trim()?.toUpperCase()?.charAt(0) ?? '?'
		},

		avatarUrl() {
			return getUserProxyAvatarOcsUrl(this.token, this.id, this.isDarkTheme, this.size > AVATAR.SIZE.MEDIUM ? 512 : 64)
		},

		isSpecialAvatar() {
			return this.isGuestUser || this.iconClass || this.isBot || (this.isFederatedUser && this.token)
		},
	},

	watch: {
		avatarUrl() {
			this.failed = false
		},
	},

	methods: {
		t,
	},
}
</script>

<style lang="scss" scoped>
.avatar-wrapper {
	position: relative;
	height: var(--avatar-size);
	width: var(--avatar-size);
	border-radius: var(--avatar-size);

	&--dark .avatar {
		background-color: #3B3B3B !important;
	}

	.avatar {
		position: sticky;
		top: 0;
		display: block;
		width: var(--avatar-size);
		height: var(--avatar-size);
		max-height: var(--avatar-size);
		max-width: var(--avatar-size);
		line-height: var(--avatar-size);
		font-size: calc(var(--avatar-size) / 2);
		background-color: var(--color-text-maxcontrast-default);

		& :deep(.avatar-class-icon) {
			background-size: calc(var(--avatar-size) / 2);
			&.icon-changelog {
				background-size: cover !important;
			}
		}

		&.bot {
			padding-inline-start: 5px;
			background-color: var(--color-background-darker);
		}

		&.guest {
			color: #ffffff;
			padding: 0;
			display: block;
			text-align: center;
			margin-inline: auto;
		}
	}

	&--condensed {
		width: unset;
		height: unset;
		margin-inline-start: calc(var(--condensed-overlap) * -1px);
		display: flex;

		& :deep(.avatardiv) {
			outline: 2px solid var(--color-main-background);
		}
	}

	&--offline {
		// Dim the avatar, not the whole wrapper: the federated status badge sits
		// outside .avatardiv and must stay legible. The dim means "not in this
		// call"; the badge means "their status on their own server". Core's local
		// status icon renders inside .avatardiv, so it keeps dimming as before.
		& :deep(.avatardiv) {
			opacity: .4;
			background: rgba(var(--color-main-background-rgb), .4) !important;
		}
	}

	&--highlighted {
		outline: 2px solid var(--color-primary-element);
	}

	&__user-status {
		position: absolute;
		inset-inline-end: -4px;
		bottom: -4px;
		height: 18px;
		width: 18px;
		border: 2px solid var(--color-main-background);
		background-color: var(--color-main-background);
		border-radius: 50%;

		// The globe's shape says "federated"; its color says whether they are around.
		// No modifier means unknown, which must stay distinct from offline.
		// These are the same colors core fills its own status icons with, so a
		// federated "away" reads as the same amber as a local one. The literals are
		// the fallback because the theme's --color-success/warning/error are panel
		// fills that go near-black in dark mode; only --user-status-color-* carries
		// a color that works against both backgrounds.
		&--online {
			color: var(--user-status-color-online, #2D7B41);
		}

		&--away {
			color: var(--user-status-color-away, #C88800);
		}

		&--dnd,
		&--busy {
			color: var(--user-status-color-busy, #DB0606);
		}

		&--offline {
			color: var(--user-status-color-offline, #6B6B6B);
		}
	}
}

.loading-avatar {
	position: absolute;
	top: 0;
	inset-inline-start: 0;
	width: 100%;
	height: 100%;
}

.avatar-wrapper:not(.avatar-wrapper--dark) {
	// FIXME: update the used color in NcAvatar
	// TOREMOVE: when fixed in @nextcloud/vue
	:deep(.avatar-class-icon) {
		background-color: var(--color-text-maxcontrast-default);
	}
}
</style>
