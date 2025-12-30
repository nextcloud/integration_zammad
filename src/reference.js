/**
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

import { registerWidget } from '@nextcloud/vue/components/NcRichText'

registerWidget('integration_zammad', async (el, { richObjectType, richObject, accessible }) => {
	const { createApp } = await import('vue')
	const { default: ReferenceZammadWidget } = await import('./views/ReferenceZammadWidget.vue')

	const app = createApp(
		ReferenceZammadWidget,
		{
			richObjectType,
			richObject,
			accessible,
		},
	)
	app.mixin({ methods: { t, n } })
	const { default: VueSecureHTML } = await import('vue-html-secure')
	app.use(VueSecureHTML)
	// app.provide('$safeHTML', VueSecureHTML.safeHTML)
	app.mount(el)
}, () => {}, { hasInteractiveView: false })
