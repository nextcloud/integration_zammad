<template>
	<div id="zammad_prefs" class="section">
		<h2>
			<ZammadIcon class="icon" />
			{{ t('integration_zammad', 'Zammad integration') }}
		</h2>
		<div id="zammad-content">
			<NcNoteCard type="info">
				{{ t('integration_zammad', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a Zammad instance, create an application in your Zammad admin settings and put the application ID (AppId) and secret below.') }}
				<br>
				{{ t('integration_zammad', 'Make sure you set the "Callback URL" to') }}
				<br>
				<strong>{{ redirect_uri }}</strong>
			</NcNoteCard>
			<NcTextField
				v-model="state.oauth_instance_url"
				:label="t('integration_zammad', 'Zammad instance address')"
				:placeholder="t('integration_zammad', 'Zammad address')"
				:show-trailing-button="!!state.oauth_instance_url"
				@trailing-button-click="state.oauth_instance_url = ''; onInput()"
				@update:model-value="onInput">
				<template #icon>
					<EarthIcon :size="20" />
				</template>
			</NcTextField>
			<NcTextField
				v-model="state.client_id"
				type="password"
				:label="t('integration_zammad', 'Application ID')"
				:placeholder="t('integration_zammad', 'ID of your application')"
				:readonly="readonly"
				:show-trailing-button="!!state.client_id"
				@trailing-button-click="state.client_id = ''; onInput()"
				@focus="readonly = false"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<NcTextField
				v-model="state.client_secret"
				type="password"
				:label="t('integration_zammad', 'Application secret')"
				:placeholder="t('integration_zammad', 'Client secret of your application')"
				:readonly="readonly"
				:show-trailing-button="!!state.client_secret"
				@trailing-button-click="state.client_secret = ''; onInput()"
				@focus="readonly = false"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<NcFormBoxSwitch
				:model-value="state.link_preview_enabled"
				@update:model-value="onCheckboxChanged($event, 'link_preview_enabled')">
				{{ t('integration_zammad', 'Enable Zammad link previews') }}
			</NcFormBoxSwitch>
		</div>
	</div>
</template>

<script>
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'

import ZammadIcon from './icons/ZammadIcon.vue'

import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',

	components: {
		NcFormBoxSwitch,
		NcNoteCard,
		NcTextField,
		ZammadIcon,
		KeyOutlineIcon,
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
			delay(() => {
				const values = {
					client_id: this.state.client_id,
					oauth_instance_url: this.state.oauth_instance_url,
				}
				if (this.state.client_secret !== 'dummySecret') {
					values.client_secret = this.state.client_secret
				}
				this.saveOptions(values)
			}, 2000)()
		},
		onCheckboxChanged(newValue, key) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] ? '1' : '0' }, false)
		},
		async saveOptions(values, sensitive = true) {
			if (sensitive) {
				await confirmPassword()
			}
			const req = {
				values,
			}
			const url = sensitive
				? generateUrl('/apps/integration_zammad/sensitive-admin-config')
				: generateUrl('/apps/integration_zammad/admin-config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_zammad', 'Zammad admin options saved'))
				})
				.catch((error) => {
					showError(t('integration_zammad', 'Failed to save Zammad admin options'))
					console.error(error)
				})
		},
	},
}
</script>

<style scoped lang="scss">
#zammad_prefs {
	h2 {
		display: flex;
		justify-content: start;
		align-items: center;
		gap: 8px;
	}
	#zammad-content{
		margin-left: 40px;
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 800px;
	}
}
</style>
