<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<ZammadIcon class="icon" />
			{{ t('integration_zammad', 'Zammad integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_zammad', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a Zammad instance, create an application in your Zammad admin settings and put the application ID (AppId) and secret below.') }}
		</p>
		<p class="settings-hint">
			<InformationOutlineIcon :size="20" class="icon" />
			{{ t('integration_zammad', 'Make sure you set the "Callback URL" to') }}
		</p>
		<strong>{{ redirect_uri }}</strong>
		<br><br>
		<div id="zammad-content">
			<div class="line">
				<label for="zammad-oauth-instance">
					<EarthIcon :size="20" class="icon" />
					{{ t('integration_zammad', 'Zammad instance address') }}
				</label>
				<input id="zammad-oauth-instance"
					v-model="state.oauth_instance_url"
					type="text"
					:placeholder="t('integration_zammad', 'Zammad address')"
					@input="onInput">
			</div>
			<div class="line">
				<label for="zammad-client-id">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_zammad', 'Application ID') }}
				</label>
				<input id="zammad-client-id"
					v-model="state.client_id"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_zammad', 'ID of your application')"
					@focus="readonly = false"
					@input="onInput">
			</div>
			<div class="line">
				<label for="zammad-client-secret">
					<KeyIcon :size="20" class="icon" />
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
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'

import ZammadIcon from './icons/ZammadIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'AdminSettings',

	components: {
		ZammadIcon,
		InformationOutlineIcon,
		KeyIcon,
		EarthIcon,
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
					showSuccess(t('integration_zammad', 'Zammad admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_zammad', 'Failed to save Zammad admin options')
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
	#zammad-content{
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
			width: 300px;
		}
	}
}
</style>
