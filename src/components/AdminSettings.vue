<template>
    <div id="zammad_prefs" class="section">
            <h2>
                <a class="icon icon-zammad"></a>
                {{ t('zammad', 'Zammad') }}
            </h2>
            <p class="settings-hint">
                {{ t('zammad', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a Zammad instance, create an application in your Zammad admin settings and set the ID and secret here.') }}
                <br/>
                {{ t('zammad', 'Make sure you set the "redirect_uri" to') }}
                <br/><b> {{ redirect_uri }} </b>
            </p>
            <div class="grid-form">
                <label for="zammad-oauth-instance">
                    <a class="icon icon-link"></a>
                    {{ t('zammad', 'Zammad instance address') }}
                </label>
                <input id="zammad-oauth-instance" type="text" v-model="state.oauth_instance_url" @input="onInput"
                    :placeholder="t('zammad', 'Zammad address')" />
                <label for="zammad-client-id">
                    <a class="icon icon-category-auth"></a>
                    {{ t('zammad', 'Application ID') }}
                </label>
                <input id="zammad-client-id" type="password" v-model="state.client_id" @input="onInput"
                    :readonly="readonly"
                    @focus="readonly = false"
                    :placeholder="t('zammad', 'ID of your application')" />
                <label for="zammad-client-secret">
                    <a class="icon icon-category-auth"></a>
                    {{ t('zammad', 'Application secret') }}
                </label>
                <input id="zammad-client-secret" type="password" v-model="state.client_secret" @input="onInput"
                    :readonly="readonly"
                    @focus="readonly = false"
                    :placeholder="t('zammad', 'Client secret of your application')" />
            </div>
    </div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl, imagePath } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
    name: 'AdminSettings',

    props: [],
    components: {
    },

    mounted() {
    },

    data() {
        return {
            state: loadState('zammad', 'admin-config'),
            // to prevent some browsers to fill fields with remembered passwords
            readonly: true,
            redirect_uri: OC.getProtocol() + '://' + OC.getHostName() + generateUrl('/apps/zammad/oauth-redirect')
        }
    },

    watch: {
    },

    methods: {
        onInput() {
            const that = this
            delay(function() {
                that.saveOptions()
            }, 2000)()
        },
        saveOptions() {
            const req = {
                values: {
                    client_id: this.state.client_id,
                    client_secret: this.state.client_secret,
                    oauth_instance_url: this.state.oauth_instance_url,
                }
            }
            const url = generateUrl('/apps/zammad/admin-config')
            axios.put(url, req)
                .then(function (response) {
                    showSuccess(t('zammad', 'Zammad admin options saved.'))
                })
                .catch(function (error) {
                    showError(t('zammad', 'Failed to save Zammad admin options') +
                        ': ' + error.response.request.responseText
                    )
                })
                .then(function () {
                })
        },
    }
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
    width: 500px;
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
body.dark .icon-zammad {
    background-image: url(./../../img/app.svg);
}
</style>