<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<a class="icon icon-zammad" />
			{{ t('integration_zammad', 'Zammad integration') }}
		</h2>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_zammad', 'To create an access token yourself, go to the "Token Access" section of your Zammad profile page.') }}
			<br>
			{{ t('integration_zammad', 'Create a "Personal Access Token" and give it "TICKET -> AGENT", "ADMIN -> OBJECT" and "USER_PREFERENCES -> NOTIFICATIONS" permissions.') }}
		</p>
		<div id="zammad-content">
			<div class="zammad-grid-form">
				<label for="zammad-url">
					<a class="icon icon-link" />
					{{ t('integration_zammad', 'Zammad instance address') }}
				</label>
				<input id="zammad-url"
					v-model="state.url"
					type="text"
					:disabled="connected === true"
					:placeholder="t('integration_zammad', 'https://my.zammad.org')"
					@input="onInput">
				<label v-show="!showOAuth"
					for="zammad-token">
					<a class="icon icon-category-auth" />
					{{ t('integration_zammad', 'Access token') }}
				</label>
				<input v-show="!showOAuth"
					id="zammad-token"
					v-model="state.token"
					type="password"
					:disabled="connected === true"
					:placeholder="t('integration_zammad', 'Zammad access token')"
					@input="onInput">
			</div>
			<button v-if="showOAuth && !connected" id="zammad-oauth" @click="onOAuthClick">
				<span class="icon icon-external" />
				{{ t('integration_zammad', 'Connect to Zammad') }}
			</button>
			<div v-if="connected" class="zammad-grid-form">
				<label class="zammad-connected">
					<a class="icon icon-checkmark-color" />
					{{ t('integration_zammad', 'Connected as {user}', { user: state.user_name }) }}
				</label>
				<button id="zammad-rm-cred" @click="onLogoutClick">
					<span class="icon icon-close" />
					{{ t('integration_zammad', 'Disconnect from Zammad') }}
				</button>
			</div>
			<div v-if="connected" id="zammad-search-block">
				<input
					id="search-zammad"
					type="checkbox"
					class="checkbox"
					:checked="state.search_enabled"
					@input="onSearchChange">
				<label for="search-zammad">{{ t('integration_zammad', 'Enable unified search for tickets.') }}</label>
				<br><br>
				<p v-if="state.search_enabled" class="settings-hint">
					<span class="icon icon-details" />
					{{ t('integration_zammad', 'Warning, everything you type in the search bar will be sent to your Zammad instance.') }}
				</p>
				<input
					id="notification-zammad"
					type="checkbox"
					class="checkbox"
					:checked="state.notification_enabled"
					@input="onNotificationChange">
				<label for="notification-zammad">{{ t('integration_zammad', 'Enable notifications for open tickets.') }}</label>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zammad', 'user-config'),
			initialToken: loadState('integration_zammad', 'user-config').token,
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
		const paramString = window.location.search.substr(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('zammadToken')
		if (zmToken === 'success') {
			showSuccess(t('integration_zammad', 'OAuth access token successfully retrieved!'))
		} else if (zmToken === 'error') {
			showError(t('integration_zammad', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onLogoutClick() {
			this.state.token = ''
			this.saveOptions()
		},
		onNotificationChange(e) {
			this.state.notification_enabled = e.target.checked
			this.saveOptions()
		},
		onSearchChange(e) {
			this.state.search_enabled = e.target.checked
			this.saveOptions()
		},
		onInput() {
			const that = this
			delay(function() {
				that.saveOptions()
			}, 2000)()
		},
		saveOptions() {
			if (this.state.url !== '' && !this.state.url.startsWith('https://')) {
				if (this.state.url.startsWith('http://')) {
					this.state.url = this.state.url.replace('http://', 'https://')
				} else {
					this.state.url = 'https://' + this.state.url
				}
			}
			const req = {
				values: {
					token: this.state.token,
					url: this.state.url,
					search_enabled: this.state.search_enabled ? '1' : '0',
					notification_enabled: this.state.notification_enabled ? '1' : '0',
				},
			}
			if (this.showOAuth) {
				req.values.token_type = 'oauth'
			} else {
				req.values.token_type = 'access'
			}
			const url = generateUrl('/apps/integration_zammad/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_zammad', 'Zammad options saved.'))
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
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		onOAuthClick() {
			const redirectEndpoint = generateUrl('/apps/integration_zammad/oauth-redirect')
			const redirectUri = window.location.protocol + '//' + window.location.host + redirectEndpoint
			const oauthState = Math.random().toString(36).substring(3)
			const requestUrl = this.state.url + '/oauth/authorize?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(redirectUri)
				+ '&response_type=code'
				+ '&state=' + encodeURIComponent(oauthState)

			const req = {
				values: {
					oauth_state: oauthState,
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
#zammad-search-block {
	margin-top: 30px;
}
.zammad-grid-form label {
	line-height: 38px;
}
.zammad-grid-form input {
	width: 100%;
}
.zammad-grid-form {
	max-width: 600px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	button .icon {
		margin-bottom: -1px;
	}
}
#zammad_prefs .icon {
	display: inline-block;
	width: 32px;
}
#zammad_prefs .grid-form .icon {
	margin-bottom: -3px;
}
.icon-zammad {
	background-image: url(./../../img/app-dark.svg);
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
}
body.theme--dark .icon-zammad {
	background-image: url(./../../img/app.svg);
}
#zammad-content {
	margin-left: 40px;
}
#zammad-search-block .icon {
	width: 22px;
}
</style>
