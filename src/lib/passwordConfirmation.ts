import { createApp, h } from 'vue'
import PasswordConfirm from '../components/ui/PasswordConfirm.vue'

/**
 * Drop-in replacement for @nextcloud/password-confirmation's confirmPassword().
 *
 * Same signature and same rejection value — it rejects with the string
 * 'cancelled' when the user backs out, which several call sites already test
 * for (`if (error !== 'cancelled')`).
 *
 * Why not use the upstream package: it declares @nextcloud/vue as a direct
 * dependency, so importing it pulled the entire component library into the
 * bundle even after every Nc* component was gone from this app's source. It
 * also renders an unthemed dialog. superadminpage talks to /login/confirm
 * directly for the same reasons.
 *
 * Mounts imperatively into a detached host so it can be awaited from plain
 * async code rather than needing a component in every caller's template.
 */
export function confirmPassword(): Promise<void> {
	return new Promise<void>((resolve, reject) => {
		const host = document.createElement('div')
		// The prompt is a fixed-position overlay; the host itself is inert.
		document.body.appendChild(host)

		let app: ReturnType<typeof createApp> | null = null

		const teardown = () => {
			app?.unmount()
			host.remove()
			app = null
		}

		app = createApp({
			render: () => h(PasswordConfirm, {
				onConfirmed: () => { teardown(); resolve() },
				onCancelled: () => { teardown(); reject('cancelled') },
			}),
		})

		// The overlay lives outside the app root, so it needs its own iz-app
		// ancestor or the .iz-app-scoped primitives are inert.
		host.classList.add('iz-app')
		app.mount(host)
	})
}
