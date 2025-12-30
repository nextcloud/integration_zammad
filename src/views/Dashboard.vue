<template>
	<NcDashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent
				v-if="emptyContentMessage"
				:title="emptyContentMessage">
				<template #icon>
					<component :is="emptyContentIcon" />
				</template>
				<template #action>
					<div v-if="state === 'no-token' || state === 'error'" class="connect-button">
						<a :href="settingsUrl">
							<NcButton>
								<template #icon>
									<LoginVariantIcon />
								</template>
								{{ t('integration_zammad', 'Connect to Zammad') }}
							</NcButton>
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant.vue'

import ZammadIcon from '../components/icons/ZammadIcon.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'

export default {
	name: 'Dashboard',

	components: {
		NcDashboardWidget,
		NcEmptyContent,
		NcButton,
		LoginVariantIcon,
		CheckIcon,
		CloseIcon,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			zammadUrl: null,
			notifications: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			themingColor: OCA.Theming ? OCA.Theming.color.replace('#', '') : '0082C9',
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.zammadUrl + '/#dashboard'
		},
		items() {
			return this.notifications.map((n) => {
				return {
					id: this.getUniqueKey(n),
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getAuthorAvatarUrl(n),
					avatarUsername: this.getAuthorShortName(n),
					avatarIsNoUser: true,
					overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: this.getTargetTitle(n),
					subText: this.getSubline(n),
				}
			})
		},
		lastDate() {
			const nbNotif = this.notifications.length
			return (nbNotif > 0) ? this.notifications[0].updated_at : null
		},
		lastMoment() {
			return moment(this.lastDate)
		},
		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('integration_zammad', 'No Zammad account connected')
			} else if (this.state === 'error') {
				return t('integration_zammad', 'Error connecting to Zammad')
			} else if (this.state === 'ok') {
				return t('integration_zammad', 'No Zammad notifications!')
			}
			return ''
		},
		emptyContentIcon() {
			if (this.state === 'no-token') {
				return ZammadIcon
			} else if (this.state === 'error') {
				return CloseIcon
			} else if (this.state === 'ok') {
				return CheckIcon
			}
			return CheckIcon
		},
	},

	watch: {
		windowVisibility(newValue) {
			if (newValue) {
				this.launchLoop()
			} else {
				this.stopLoop()
			}
		},
	},

	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.changeWindowVisibility)
	},

	beforeMount() {
		this.launchLoop()
		document.addEventListener('visibilitychange', this.changeWindowVisibility)
	},

	mounted() {
	},

	methods: {
		changeWindowVisibility() {
			this.windowVisibility = !document.hidden
		},
		stopLoop() {
			clearInterval(this.loop)
		},
		async launchLoop() {
			// get zammad URL first
			try {
				const response = await axios.get(generateUrl('/apps/integration_zammad/url'))
				this.zammadUrl = response.data.replace(/\/+$/, '')
			} catch (error) {
				console.debug(error)
			}
			// then launch the loop
			this.fetchNotifications()
			this.loop = setInterval(() => this.fetchNotifications(), 60000)
		},
		fetchNotifications() {
			const req = {}
			if (this.lastDate) {
				req.params = {
					since: this.lastDate,
				}
			}
			axios.get(generateUrl('/apps/integration_zammad/notifications'), req).then((response) => {
				if (Array.isArray(response.data)) {
					this.processNotifications(response.data)
				}
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_zammad', 'Failed to get Zammad notifications'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					console.debug(error)
				}
			})
		},
		processNotifications(newNotifications) {
			if (this.lastDate) {
				// just add those which are more recent than our most recent one
				let i = 0
				while (i < newNotifications.length && this.lastMoment.isBefore(newNotifications[i].updated_at)) {
					i++
				}
				if (i > 0) {
					const toAdd = this.filter(newNotifications.slice(0, i))
					this.notifications = toAdd.concat(this.notifications)
				}
			} else {
				// first time we don't check the date
				this.notifications = this.filter(newNotifications)
			}
		},
		filter(notifications) {
			return notifications
		},
		getNotificationTarget(n) {
			return this.zammadUrl + '/#ticket/zoom/' + n.o_id
		},
		getUniqueKey(n) {
			return n.id + ':' + n.updated_at
		},
		getAuthorShortName(n) {
			if (!n.firstname && !n.lastname) {
				return '?'
			} else {
				return (n.firstname ? n.firstname[0] : '')
					+ (n.lastname ? n.lastname[0] : '')
			}
		},
		getAuthorFullName(n) {
			return n.firstname + ' ' + n.lastname
		},
		getAuthorAvatarUrl(n) {
			return (n.image)
				? generateUrl('/apps/integration_zammad/avatar/{imageId}', { imageId: n.image })
				: undefined
		},
		getNotificationProjectName(n) {
			return ''
		},
		getNotificationContent(n) {
			return ''
		},
		getNotificationTypeImage(n) {
			if (n.type_lookup_id === 2 || n.type === 'update') {
				return imagePath('integration_zammad', 'rename.svg')
			} else if (n.type_lookup_id === 3 || n.type === 'create') {
				return imagePath('integration_zammad', 'add.svg')
			}
			return imagePath('integration_zammad', 'sound-border.svg')
		},
		getSubline(n) {
			return this.getAuthorFullName(n) + ' #' + n.o_id
		},
		getTargetTitle(n) {
			return n.title
		},
		getTargetIdentifier(n) {
			return n.o_id
		},
		getFormattedDate(n) {
			return moment(n.updated_at).format('LLL')
		},
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
