import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, DEFAULT_API_TIMEOUT_MS } from './api'

describe('api', () => {
  beforeEach(() => {
    window.CommunityRaceCalendarConfig = {
      restRoot: '/wp-json/community-race-calendar/v1/',
      nonce: 'nonce-test',
    }
    global.fetch = vi.fn()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('normalise la route et renvoie un objet JSON', async () => {
    fetch.mockResolvedValue(new Response(JSON.stringify({ courses: [] }), { status: 200 }))

    await expect(api('/courses?perPage=100')).resolves.toEqual({ courses: [] })
    expect(fetch).toHaveBeenCalledWith(
      '/wp-json/community-race-calendar/v1/courses/?perPage=100',
      expect.objectContaining({
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': 'nonce-test' },
      }),
    )
  })

  it('conserve le message, le code et le statut des erreurs REST WordPress', async () => {
    fetch.mockResolvedValue(new Response(JSON.stringify({
      code: 'rest_forbidden',
      message: 'Accès refusé.',
    }), { status: 403 }))

    await expect(api('courses')).rejects.toMatchObject({
      message: 'Accès refusé.',
      code: 'rest_forbidden',
      status: 403,
    })
  })

  it('signale explicitement une réponse 200 non JSON', async () => {
    fetch.mockResolvedValue(new Response('<html>Erreur proxy</html>', { status: 200 }))

    await expect(api('courses')).rejects.toMatchObject({
      message: 'Le serveur a renvoyé une réponse invalide (JSON attendu).',
      code: 'invalid_json_response',
      status: 200,
    })
  })

  it('refuse un schéma JSON primitif inattendu', async () => {
    fetch.mockResolvedValue(new Response('true', { status: 200 }))

    await expect(api('courses')).rejects.toMatchObject({
      code: 'invalid_response_schema',
      status: 200,
    })
  })

  it.each([
    ['bootstrap', {}],
    ['courses', {}],
    ['courses/12', {}],
    ['locations?query=Lyon', {}],
    ['members?search=Ana', {}],
    ['season?year=2026', {}],
  ])('valide le schéma minimal de lecture pour %s', async (path, payload) => {
    fetch.mockResolvedValue(new Response(JSON.stringify(payload), { status: 200 }))

    await expect(api(path)).rejects.toMatchObject({
      code: 'invalid_response_schema',
      status: 200,
    })
  })

  it('annule une requête trop lente avec un message actionnable', async () => {
    vi.useFakeTimers()
    fetch.mockImplementation((_url, options) => new Promise((_resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
    }))

    const expectation = expect(api('courses')).rejects.toMatchObject({
      message: 'Le serveur met trop de temps à répondre. Réessayez.',
      code: 'request_timeout',
      status: 0,
    })
    await vi.advanceTimersByTimeAsync(DEFAULT_API_TIMEOUT_MS)
    await expectation
  })

  it('distingue une panne réseau d’une erreur HTTP', async () => {
    fetch.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(api('courses')).rejects.toMatchObject({
      message: 'Connexion au serveur impossible. Réessayez.',
      code: 'network_error',
      status: 0,
    })
  })

  it('demande de vérifier le résultat avant de relancer une mutation en timeout', async () => {
    vi.useFakeTimers()
    fetch.mockImplementation((_url, options) => new Promise((_resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
    }))

    const expectation = expect(api('courses/12/comments', {
      method: 'POST',
      body: JSON.stringify({ body: 'Bravo !' }),
    })).rejects.toMatchObject({
      message: 'Le serveur met trop de temps à répondre. Vérifiez si l’action a abouti avant de réessayer pour éviter un doublon.',
      code: 'request_timeout',
    })
    await vi.advanceTimersByTimeAsync(DEFAULT_API_TIMEOUT_MS)
    await expectation
  })

  it('conserve AbortError pour les vues qui annulent une requête devenue inutile', async () => {
    const controller = new AbortController()
    fetch.mockImplementation((_url, options) => new Promise((_resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
    }))

    const expectation = expect(api('courses/12', { signal: controller.signal })).rejects.toMatchObject({
      name: 'AbortError',
      code: 'request_aborted',
    })
    controller.abort()
    await expectation
  })

  it('accepte une suppression sans corps', async () => {
    fetch.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(api('courses/12', { method: 'DELETE' })).resolves.toBeNull()
  })
})
