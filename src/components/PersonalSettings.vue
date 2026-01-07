<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<ZammadIcon class="icon" />
			{{ t('integration_zammad', 'Zammad integration') }}
		</h2>
		<div id="zammad-content">
			<NcNoteCard v-if="!showOAuth && !connected" type="info">
				{{ t('integration_zammad', 'To create an access token yourself, go to the "Token Access" section of your Zammad profile page.') }}
				<br>
				{{ t('integration_zammad', 'Create a "Personal Access Token" and give it "TICKET -> AGENT", "ADMIN -> OBJECT" and "USER_PREFERENCES -> NOTIFICATIONS" permissions.') }}
			</NcNoteCard>
			<NcTextField
				v-model="state.url"
				:label="t('integration_zammad', 'Zammad instance address')"
				placeholder="https://my.zammad.org"
				:disabled="connected === true"
				:show-trailing-button="!!state.url"
				@trailing-button-click="state.url = ''; onInput()"
				@update:model-value="onInput">
				<template #icon>
					<EarthIcon :size="20" />
				</template>
			</NcTextField>
			<NcTextField v-show="!showOAuth"
				v-model="state.token"
				type="password"
				:label="t('integration_zammad', 'Access token')"
				:placeholder="t('integration_zammad', 'Zammad access token')"
				:disabled="connected === true"
				:show-trailing-button="!!state.token"
				@trailing-button-click="state.token = ''; onInput()"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<NcButton v-if="showOAuth && !connected"
				id="zammad-oauth"
				:disabled="loading === true"
				:class="{ loading }"
				@click="onOAuthClick">
				<template #icon>
					<LoginVariantIcon :size="20" />
				</template>
				{{ t('integration_zammad', 'Connect to Zammad') }}
			</NcButton>
			<div v-if="connected" class="line">
				<label class="zammad-connected">
					<CheckIcon :size="20" class="icon" />
					{{ t('integration_zammad', 'Connected as {user}', { user: state.user_name }) }}
				</label>
				<NcButton id="zammad-rm-cred"
					@click="onLogoutClick">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
					{{ t('integration_zammad', 'Disconnect from Zammad') }}
				</NcButton>
			</div>
			<NcFormBox>
				<NcFormBoxSwitch v-if="connected"
					:model-value="state.search_enabled"
					@update:model-value="onCheckboxChanged($event, 'search_enabled')">
					{{ t('integration_zammad', 'Enable unified search for tickets') }}
				</NcFormBoxSwitch>
				<NcFormBoxSwitch v-if="connected"
					:model-value="state.notification_enabled"
					@update:model-value="onCheckboxChanged($event, 'notification_enabled')">
					{{ t('integration_zammad', 'Enable notifications for open tickets') }}
				</NcFormBoxSwitch>
				<NcFormBoxSwitch
					:model-value="state.navigation_enabled"
					@update:model-value="onCheckboxChanged($event, 'navigation_enabled')">
					{{ t('integration_zammad', 'Enable navigation link') }}
				</NcFormBoxSwitch>
				<NcFormBoxSwitch
					:model-value="state.link_preview_enabled"
					@update:model-value="onCheckboxChanged($event, 'link_preview_enabled')">
					{{ t('integration_zammad', 'Enable Zammad link previews') }}
				</NcFormBoxSwitch>
			</NcFormBox>
			<NcNoteCard v-if="connected && state.search_enabled" type="warning">
				{{ t('integration_zammad', 'Warning, everything you type in the search bar will be sent to your Zammad instance.') }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

import ZammadIcon from './icons/ZammadIcon.vue'

import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

import { delay } from '../utils.js'

export default {
	name: 'PersonalSettings',

	components: {
		NcFormBox,
		NcFormBoxSwitch,
		NcButton,
		NcNoteCard,
		NcTextField,
		ZammadIcon,
		EarthIcon,
		KeyOutlineIcon,
		LoginVariantIcon,
		CloseIcon,
		CheckIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zammad', 'user-config'),
			initialToken: loadState('integration_zammad', 'user-config').token,
			loading: false,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_zammad/oauth-redirect'),
		}
	},

	computed: {
		showOAuth() {
			return this.state.url === this.state.oauth_instance_url
				&& this.state.client_id
				&& this.state.client_secret
		},
		connected() {
			return this.state.token && this.state.token !== ''
				&& this.state.url && this.state.url !== ''
				&& this.state.user_name && this.state.user_name !== ''
		},
	},

	mounted() {
		const paramString = window.location.search.slice(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('zammadToken')
		if (zmToken === 'success') {
			showSuccess(t('integration_zammad', 'Successfully connected to Zammad!'))
		} else if (zmToken === 'error') {
			showError(t('integration_zammad', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onLogoutClick() {
			this.state.token = ''
			this.saveOptions({ token: this.state.token, token_type: '' })
		},
		onCheckboxChanged(newValue, key) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] ? '1' : '0' }, false)
		},
		onInput() {
			this.loading = true
			delay(() => {
				const values = {
					url: this.state.url,
				}
				if (this.state.token !== 'dummyToken') {
					values.token = this.state.token
					values.token_type = this.showOAuth ? 'oauth' : 'access'
				}
				this.saveOptions(values)
			}, 2000)()
		},
		async saveOptions(values, sensitive = true) {
			if (sensitive) {
				await confirmPassword()
			}
			const req = {
				values,
			}
			const url = sensitive
				? generateUrl('/apps/integration_zammad/sensitive-config')
				: generateUrl('/apps/integration_zammad/config')
			return axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_zammad', 'Zammad options saved'))
					if (response.data.user_name !== undefined) {
						this.state.user_name = response.data.user_name
						if (this.state.token && response.data.user_name === '') {
							showError(t('integration_zammad', 'Incorrect access token'))
						}
					}
				})
				.catch((error) => {
					console.error(error)
					showError(t('integration_zammad', 'Failed to save Zammad options'))
				})
				.then(() => {
					this.loading = false
				})
		},
		onOAuthClick() {
			const oauthState = Math.random().toString(36).substring(3)
			const oauthAuthorizeUrl = this.state.url + '/oauth/authorize'
				+ '?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				+ '&response_type=code'
				+ '&state=' + encodeURIComponent(oauthState)

			const values = {
				oauth_state: oauthState,
				redirect_uri: this.redirect_uri,
			}
			this.saveOptions(values)
				.then(() => {
					window.location.replace(oauthAuthorizeUrl)
				})
		},
	},
}
</script>

<style scoped lang="scss">
#zammad_prefs {
	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		justify-content: start;
	}

	#zammad-content {
		margin-left: 40px;
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 800px;

		.line {
			display: flex;
			align-items: center;
			gap: 4px;
			label {
				display: flex;
				align-items: center;
				gap: 4px;
			}
		}
	}
}
</style>
