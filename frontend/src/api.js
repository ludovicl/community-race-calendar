const config = () => window.CommunityRaceCalendarConfig || {}

export const DEFAULT_API_TIMEOUT_MS = 15_000

export class ApiError extends Error {
  constructor(message, { code = 'api_error', status = 0, cause } = {}) {
    super(message, cause ? { cause } : undefined)
    this.name = 'ApiError'
    this.code = code
    this.status = status
  }
}

function parsePayload(response, rawPayload) {
  if (!rawPayload.trim()) {
    throw new ApiError('Le serveur a renvoyé une réponse vide inattendue.', {
      code: 'empty_response',
      status: response.status,
    })
  }

  let payload
  try {
    payload = JSON.parse(rawPayload)
  } catch (cause) {
    throw new ApiError('Le serveur a renvoyé une réponse invalide (JSON attendu).', {
      code: 'invalid_json_response',
      status: response.status,
      cause,
    })
  }

  if (payload === null || (typeof payload !== 'object')) {
    throw new ApiError('Le serveur a renvoyé un format de données inattendu.', {
      code: 'invalid_response_schema',
      status: response.status,
    })
  }

  return payload
}

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)

function validateReadPayload(pathname, method, payload, status) {
  if (method !== 'GET') return payload

  const checks = [
    { match: /^bootstrap$/, valid: () => typeof payload.accessGranted === 'boolean' },
    { match: /^courses(?:\/deleted)?$/, valid: () => Array.isArray(payload.courses) },
    { match: /^courses\/duplicates$/, valid: () => Array.isArray(payload.duplicates) },
    { match: /^courses\/editions$/, valid: () => Array.isArray(payload.editions) },
    { match: /^courses\/\d+$/, valid: () => isRecord(payload.course) },
    { match: /^locations$/, valid: () => Array.isArray(payload.locations) },
    { match: /^members$/, valid: () => Array.isArray(payload.members) },
    { match: /^season$/, valid: () => isRecord(payload.season) },
    { match: /^review$/, valid: () => isRecord(payload.review) && Array.isArray(payload.review.courses) },
  ]
  const schema = checks.find(({ match }) => match.test(pathname))

  if (schema && !schema.valid()) {
    throw new ApiError(`Le serveur a renvoyé des données incomplètes pour /${pathname}.`, {
      code: 'invalid_response_schema',
      status,
    })
  }

  return payload
}

export async function api(path, options = {}) {
  const { restRoot, nonce } = config()
  if (!restRoot) throw new Error('Configuration REST absente.')

  const {
    signal: callerSignal,
    timeout = DEFAULT_API_TIMEOUT_MS,
    ...fetchOptions
  } = options
  const [pathname, query = ''] = path.split('?')
  const cleanPathname = pathname.replace(/^\/+|\/+$/g, '')
  const normalizedPath = `${cleanPathname}/`
  const method = String(fetchOptions.method || 'GET').toUpperCase()
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData
  const controller = new AbortController()
  let timeoutReached = false
  const timeoutId = Number.isFinite(timeout) && timeout > 0
    ? setTimeout(() => {
      timeoutReached = true
      controller.abort()
    }, timeout)
    : null
  const abortFromCaller = () => controller.abort(callerSignal?.reason)
  callerSignal?.addEventListener('abort', abortFromCaller, { once: true })

  if (callerSignal?.aborted) abortFromCaller()

  try {
    const response = await fetch(`${restRoot}${normalizedPath}${query ? `?${query}` : ''}`, {
      credentials: 'same-origin',
      ...fetchOptions,
      signal: controller.signal,
      headers: {
        ...(options.body && !isFormData ? { 'Content-Type': 'application/json' } : {}),
        ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
        ...options.headers,
      },
    })

    if (response.status === 204) return null
    const payload = parsePayload(response, await response.text())
    if (!response.ok) {
      throw new ApiError(
        typeof payload.message === 'string' && payload.message.trim()
          ? payload.message
          : `Le serveur a refusé la requête (HTTP ${response.status}).`,
        {
          code: typeof payload.code === 'string' ? payload.code : 'http_error',
          status: response.status,
        },
      )
    }
    return validateReadPayload(cleanPathname, method, payload, response.status)
  } catch (cause) {
    if (cause instanceof ApiError) throw cause
    if (timeoutReached) {
      const message = ['GET', 'HEAD'].includes(method)
        ? 'Le serveur met trop de temps à répondre. Réessayez.'
        : 'Le serveur met trop de temps à répondre. Vérifiez si l’action a abouti avant de réessayer pour éviter un doublon.'
      throw new ApiError(message, {
        code: 'request_timeout',
        cause,
      })
    }
    if (controller.signal.aborted) {
      const error = new ApiError('La requête a été annulée.', {
        code: 'request_aborted',
        cause,
      })
      error.name = 'AbortError'
      throw error
    }
    throw new ApiError('Connexion au serveur impossible. Réessayez.', {
      code: 'network_error',
      cause,
    })
  } finally {
    if (timeoutId !== null) clearTimeout(timeoutId)
    callerSignal?.removeEventListener('abort', abortFromCaller)
  }
}
