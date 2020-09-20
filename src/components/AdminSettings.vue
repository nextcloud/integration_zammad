<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<a class="icon icon-zammad" />
			{{ t('integration_zammad', 'Zammad integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_zammad', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a Zammad instance, create an application in your Zammad admin settings and put the application ID (AppId) and secret below.') }}
			<br><br>
			<span class="icon icon-details" />
			{{ t('integration_zammad', 'Make sure you set the "Callback URL" to') }}
			<b> {{ redirect_uri }} </b>
		</p>
		<div class="grid-form">
			<label for="zammad-oauth-instance">
				<a class="icon icon-link" />
				{{ t('integration_zammad', 'Zammad instance address') }}
			</label>
			<input id="zammad-oauth-instance"
				v-model="state.oauth_instance_url"
				type="text"
				:placeholder="t('integration_zammad', 'Zammad address')"
				@input="onInput">
			<label for="zammad-client-id">
				<a class="icon icon-category-auth" />
				{{ t('integration_zammad', 'Application ID') }}
			</label>
			<input id="zammad-client-id"
				v-model="state.client_id"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_zammad', 'ID of your application')"
				@focus="readonly = false"
				@input="onInput">
			<label for="zammad-client-secret">
				<a class="icon icon-category-auth" />
				{{ t('integration_zammad', 'Application secret') }}
			</label>
			<input id="zammad-client-secret"
				v-model="state.client_secret"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_zammad', 'Client secret of your application')"
				@focus="readonly = false"
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
	name: 'AdminSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zammad', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_zammad/oauth-redirect'),
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onInput() {
			const that = this
			delay(() => {
				that.saveOptions()
			}, 2000)()
		},
		saveOptions() {
			const req = {
				values: {
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
					oauth_instance_url: this.state.oauth_instance_url,
				},
			}
			const url = generateUrl('/apps/integration_zammad/admin-config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_zammad', 'Zammad admin options saved.'))
				})
				.catch((error) => {
					showError(
						t('integration_zammad', 'Failed to save Zammad admin options.')
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
.grid-form label {
	line-height: 38px;
}
.grid-form input {
	width: 100%;
}
.grid-form {
	max-width: 500px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	margin-left: 30px;
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
</style>
