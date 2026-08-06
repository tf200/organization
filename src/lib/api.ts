import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Carries the server's own message so it can be shown.
 *
 * Before this, every failure in the app went to console.error and the user
 * just watched a spinner stop — including messages worth reading, like
 * "Cannot delete plan: it is used by N subscriptions".
 */
export class ApiError extends Error {
	constructor(message: string, readonly status: number) {
		super(message)
		this.name = 'ApiError'
	}
}

export interface OcsInit {
	method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
	params?: Record<string, unknown>
	body?: Record<string, unknown>
	/** Send as application/x-www-form-urlencoded — the backup endpoints expect it. */
	form?: boolean
	headers?: Record<string, string>
}

function messageFrom(e: unknown): { message: string; status: number } {
	// @nextcloud/password-confirmation rejects with the bare string 'cancelled'.
	if (e === 'cancelled') {
		return { message: 'Password confirmation was cancelled.', status: 0 }
	}
	const err = e as {
		response?: { status?: number; data?: { ocs?: { meta?: { message?: string } } } }
		message?: string
	}
	const serverMessage = err.response?.data?.ocs?.meta?.message
	return {
		message: serverMessage || err.message || 'The server could not complete that request.',
		status: err.response?.status ?? 0,
	}
}

/** Unwraps ocs.data and turns every failure into a readable ApiError. */
export async function ocs<T>(path: string, init: OcsInit = {}): Promise<T> {
	const { method = 'GET', params, body, form = false, headers = {} } = init
	try {
		const { data } = await axios.request({
			url: generateOcsUrl(`apps/organization/${path}`),
			method,
			params,
			data: form && body
				? new URLSearchParams(body as Record<string, string>)
				: body,
			headers: form
				? { 'Content-Type': 'application/x-www-form-urlencoded', ...headers }
				: headers,
		})
		return data?.ocs?.data as T
	} catch (e: unknown) {
		const { message, status } = messageFrom(e)
		throw new ApiError(message, status)
	}
}
