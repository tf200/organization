import { ref, shallowRef } from 'vue'
import { ApiError } from '../lib/api'

/**
 * One place for loading and error state.
 *
 * `pending` is cleared in `finally`, which preserves the existing behaviour
 * that a button always re-enables after a failure. What changes is that
 * `error` is now something the caller is expected to render.
 * @param fn
 */
export function useAsync<A extends unknown[], T>(fn: (...args: A) => Promise<T>) {
	const data = shallowRef<T | null>(null)
	const error = ref<string>('')
	const pending = ref(false)

	/**
	 *
	 * @param {...any} args
	 */
	async function run(...args: A): Promise<T | null> {
		pending.value = true
		error.value = ''
		try {
			const result = await fn(...args)
			data.value = result
			return result
		} catch (e) {
			error.value = e instanceof ApiError ? e.message : String(e)
			return null
		} finally {
			pending.value = false
		}
	}

	function clearError() {
		error.value = ''
	}

	return { data, error, pending, run, clearError }
}
