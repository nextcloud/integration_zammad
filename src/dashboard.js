/**
 * Nextcloud - zammad
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('zammad_notifications', async (el, { widget }) => {
		const { createApp } = await import('vue')
		const { default: Dashboard } = await import('./views/Dashboard.vue')
		const app = createApp(Dashboard, {
			title: widget.title,
		})
		app.mixin({ methods: { t, n } })
		app.mount(el)
	})
})
