<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<ZammadIcon class="icon" />
			{{ t('integration_zammad', 'Zammad integration') }}
		</h2>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_zammad', 'To create an access token yourself, go to the "Token Access" section of your Zammad profile page.') }}
		</p>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_zammad', 'Create a "Personal Access Token" and give it "TICKET -> AGENT", "ADMIN -> OBJECT" and "USER_PREFERENCES -> NOTIFICATIONS" permissions.') }}
		</p>
		<div id="zammad-content">
			<div class="line">
				<label for="zammad-url">
					<EarthIcon :size="20" class="icon" />
					{{ t('integration_zammad', 'Zammad instance address') }}
				</label>
				<input id="zammad-url"
					v-model="state.url"
					type="text"
					:disabled="connected === true"
					:placeholder="t('integration_zammad', 'https://my.zammad.org')"
					@input="onInput">
			</div>
			<div v-show="!showOAuth" class="line">
				<label for="zammad-token">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_zammad', 'Access token') }}
				</label>
				<input id="zammad-token"
					v-model="state.token"
					type="password"
					:disabled="connected === true"
					:placeholder="t('integration_zammad', 'Zammad access token')"
					@input="onInput">
			</div>
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
			<div v-if="connected" id="zammad-search-block">
				<br>
				<p v-if="state.search_enabled" class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_zammad', 'Warning, everything you type in the search bar will be sent to your Zammad instance.') }}
				</p>
				<NcCheckboxRadioSwitch
					:checked="state.search_enabled"
					@update:checked="onCheckboxChanged($event, 'search_enabled')">
					{{ t('integration_zammad', 'Enable unified search for tickets') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.notification_enabled"
					@update:checked="onCheckboxChanged($event, 'notification_enabled')">
					{{ t('integration_zammad', 'Enable notifications for open tickets') }}
				</NcCheckboxRadioSwitch>
			</div>
			<NcCheckboxRadioSwitch
				:checked="state.navigation_enabled"
				@update:checked="onCheckboxChanged($event, 'navigation_enabled')">
				{{ t('integration_zammad', 'Enable navigation link') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				:checked="state.link_preview_enabled"
				@update:checked="onCheckboxChanged($event, 'link_preview_enabled')">
				{{ t('integration_zammad', 'Enable Zammad link previews in Talk') }}
			</NcCheckboxRadioSwitch>
		</div>
	</div>
</template>

<script>
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'

import ZammadIcon from './icons/ZammadIcon.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
		NcCheckboxRadioSwitch,
		NcButton,
		ZammadIcon,
		EarthIcon,
		KeyIcon,
		LoginVariantIcon,
		CloseIcon,
		CheckIcon,
		InformationOutlineIcon,
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
			this.saveOptions({ [key]: this.state[key] ? '1' : '0' })
		},
		onInput() {
			this.loading = true
			if (this.state.url !== '' && !this.state.url.startsWith('https://')) {
				if (this.state.url.startsWith('http://')) {
					this.state.url = this.state.url.replace('http://', 'https://')
				} else {
					this.state.url = 'https://' + this.state.url
				}
			}
			delay(() => {
				// check the domain name has at least one dot
				const pattern = /^(https?:\/\/)?[^.]+\.[^.].*/
				if (pattern.test(this.state.url)) {
					this.saveOptions({ url: this.state.url, token: this.state.token, token_type: this.showOAuth ? 'oauth' : 'access' })
				} else {
					this.saveOptions({ url: '', token: this.state.token, token_type: this.showOAuth ? 'oauth' : 'access' })
				}
			}, 2000)()
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_zammad/config')
			axios.put(url, req)
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
					console.debug(error)
					showError(
						t('integration_zammad', 'Failed to save Zammad options')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
					this.loading = false
				})
		},
		onOAuthClick() {
			const oauthState = Math.random().toString(36).substring(3)
			const requestUrl = this.state.url + '/oauth/authorize'
				+ '?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				+ '&response_type=code'
				+ '&state=' + encodeURIComponent(oauthState)

			const req = {
				values: {
					oauth_state: oauthState,
					redirect_uri: this.redirect_uri,
				},
			}
			const url = generateUrl('/apps/integration_zammad/config')
			axios.put(url, req)
				.then((response) => {
					window.location.replace(requestUrl)
				})
				.catch((error) => {
					showError(
						t('integration_zammad', 'Failed to save Zammad OAuth state')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
#zammad_prefs {
	#zammad-content {
		margin-left: 40px;
	}
	h2,
	.line,
	.settings-hint {
		display: flex;
		align-items: center;
		.icon {
			margin-right: 4px;
		}
	}

	h2 .icon {
		margin-right: 8px;
	}

	.line {
		> label {
			width: 300px;
			display: flex;
			align-items: center;
		}
		> input {
			width: 250px;
		}
	}
}
</style>
