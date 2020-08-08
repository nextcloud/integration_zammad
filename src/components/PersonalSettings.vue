<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<a class="icon icon-zammad" />
			{{ t('zammad', 'Zammad') }}
		</h2>
		<p class="settings-hint">
			{{ t('zammad', 'When you create an access token yourself, give it "TICKET -> AGENT" and "USER_PREFERENCES -> NOTIFICATIONS" permissions.') }}
		</p>
		<div class="zammad-grid-form">
			<label for="zammad-url">
				<a class="icon icon-link" />
				{{ t('zammad', 'Zammad instance address') }}
			</label>
			<input id="zammad-url"
				v-model="state.url"
				type="text"
				:placeholder="t('zammad', 'https://my.zammad.org')"
				@input="onInput">
			<button v-if="showOAuth" id="zammad-oauth" @click="onOAuthClick">
				<span class="icon icon-external" />
				{{ t('zammad', 'Get access with OAuth') }}
			</button>
			<span v-else />
			<label for="zammad-token">
				<a class="icon icon-category-auth" />
				{{ t('zammad', 'Zammad access token') }}
			</label>
			<input id="zammad-token"
				v-model="state.token"
				type="password"
				:placeholder="t('zammad', 'Get a token in Zammad settings')"
				@input="onInput">
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
			state: loadState('zammad', 'user-config'),
			initialToken: loadState('zammad', 'user-config').token,
		}
	},

	computed: {
		showOAuth() {
			return this.state.url === this.state.oauth_instance_url
				&& this.state.client_id
				&& this.state.client_secret
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('zammadToken')
		if (zmToken === 'success') {
			showSuccess(t('zammad', 'OAuth access token successfully retrieved!'))
		} else if (zmToken === 'error') {
			showError(t('zammad', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
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
				},
			}
			// if manually set, this is not an oauth access token
			if (this.state.token !== this.initialToken) {
				req.values.token_type = 'access'
			}
			const url = generateUrl('/apps/zammad/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('zammad', 'Zammad options saved.'))
				})
				.catch((error) => {
					showError(
						t('zammad', 'Failed to save Zammad options')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		onOAuthClick() {
			const redirectEndpoint = generateUrl('/apps/zammad/oauth-redirect')
			const redirectUri = OC.getProtocol() + '://' + OC.getHostName() + redirectEndpoint
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
			const url = generateUrl('/apps/zammad/config')
			axios.put(url, req)
				.then((response) => {
					window.location.replace(requestUrl)
				})
				.catch((error) => {
					showError(
						t('zammad', 'Failed to save Zammad OAuth state')
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
.zammad-grid-form label {
	line-height: 38px;
}
.zammad-grid-form input {
	width: 100%;
}
.zammad-grid-form {
	max-width: 900px;
	display: grid;
	grid-template: 1fr / 1fr 1fr 1fr;
	margin-left: 30px;
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
body.dark .icon-zammad {
	background-image: url(./../../img/app.svg);
}
</style>
