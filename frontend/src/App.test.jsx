import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axe from 'axe-core'
import App from './App.jsx'

vi.mock('./MapCanvas.jsx', () => ({
  default: ({ points = [], selectedPoint, onPick, onSelect, label }) => <div data-testid="map-canvas" role="region" aria-label={label}>{points.length} point{points.length === 1 ? '' : 's'}{points.map((point) => <button key={point.id} onClick={() => onSelect?.(point.id)}>Sélectionner {point.name}</button>)}{selectedPoint && <span>Point sélectionné</span>}{onPick && <button onClick={() => onPick({ latitude: 45.764, longitude: 4.8357 })}>Placer le point test</button>}</div>,
}))

const bootstrap = {
  authenticated: true,
  accessGranted: true,
  accessCode: 'granted',
  user: { id: 2, displayName: 'Membre Actif' },
  capabilities: { manage: false, createCourses: true },
  settings: { brandName: 'Calendrier des courses', tagline: 'Les courses', brandColor: '#1d4ed8', backgroundColor: '#f5f5f5', surfaceColor: '#ffffff', mapEnabled: true },
  geocoding: { key: 'geoapify', label: 'Geoapify', available: true },
}

const waitForCourseDetail = () => waitFor(() => expect(screen.queryByText('Chargement des inscrits et des échanges…')).not.toBeInTheDocument())

describe('Community Race Calendar', () => {
  beforeEach(() => {
    window.CommunityRaceCalendarConfig = { restRoot: '/wp-json/community-race-calendar/v1/', nonce: 'nonce-test' }
    window.confirm = vi.fn(() => true)
    URL.createObjectURL = vi.fn(() => 'blob:course-preview')
    URL.revokeObjectURL = vi.fn()
    global.fetch = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
  })

  it('charge la session WordPress, le nonce et l’état vide du mois', async () => {
    render(<App />)
    expect(await screen.findByText('Calendrier des courses')).toBeInTheDocument()
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    expect(fetch.mock.calls[0][1].headers['X-WP-Nonce']).toBe('nonce-test')
    expect(screen.getByText('Aucune course ce mois-ci.')).toBeInTheDocument()
    expect(document.querySelector('.crc-main')).toHaveProperty('tagName', 'DIV')
  })

  it('propose une seule création globale avec la date du jour', async () => {
    const today = new Date()
    const expectedDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')

    const createButtons = screen.getAllByRole('button', { name: 'Ajouter une course' })
    expect(createButtons).toHaveLength(1)
    expect(screen.queryByRole('button', { name: /Ajouter une course en/ })).not.toBeInTheDocument()

    fireEvent.click(createButtons[0])
    expect(screen.getByRole('dialog', { name: 'Nouvelle course' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Fermer' })).toHaveClass('crc-dialog__close')
    expect(screen.getByLabelText('Date')).toHaveValue(expectedDate)
  })

  it('ne présente pas de violation d’accessibilité automatisée sur l’état principal', async () => {
    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    const results = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } })
    expect(results.violations).toEqual([])
  })

  it('affiche un refus sans charger les courses', async () => {
    fetch.mockReset().mockResolvedValueOnce(new Response(JSON.stringify({
      ...bootstrap, accessGranted: false, accessCode: 'membership_inactive', accessMessage: 'Votre adhésion n’est pas active.', settings: { ...bootstrap.settings, membershipUrl: '/adhesion/' },
    }), { status: 200 }))
    render(<App />)
    expect(await screen.findByText('Accès indisponible')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Gérer mon adhésion' })).toHaveAttribute('href', '/adhesion/')
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('dirige un visiteur déconnecté vers la page de compte', async () => {
    fetch.mockReset().mockResolvedValueOnce(new Response(JSON.stringify({
      ...bootstrap, authenticated: false, accessGranted: false, accessCode: 'authentication_required', accessMessage: 'Connexion requise.', loginUrl: '/mon-compte/', user: null,
    }), { status: 200 }))
    render(<App />)
    expect(await screen.findByText('Connexion requise')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Se connecter' })).toHaveAttribute('href', '/mon-compte/')
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('affiche une erreur explicite lorsque le calendrier ne répond pas', async () => {
    fetch.mockReset().mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Service temporairement indisponible.' }), { status: 503 }))
    render(<App />)
    expect(await screen.findByText('Calendrier indisponible')).toBeInTheDocument()
    expect(screen.getByText('Service temporairement indisponible.')).toBeInTheDocument()
  })

  it('recherche par lieu', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [{ id: 1, name: 'Trail des Crêtes', location: 'Lyon', eventDate, formats: [], comments: [], permissions: {} }] }), { status: 200 }))
    render(<App />)
    await screen.findByText('Trail des Crêtes')
    fireEvent.change(screen.getByPlaceholderText('Rechercher une course ou un lieu…'), { target: { value: 'Lyon' } })
    expect(screen.getByRole('status')).toHaveTextContent('1 résultat pour')
    expect(screen.getByRole('searchbox', { name: 'Rechercher une course par nom ou lieu' })).toBeInTheDocument()
    expect(screen.getByText('Trail des Crêtes')).toBeInTheDocument()
    fireEvent.change(screen.getByPlaceholderText('Rechercher une course ou un lieu…'), { target: { value: 'Marseille' } })
    expect(screen.getByText('Aucune course trouvée')).toBeInTheDocument()
    expect(screen.getByText(/ville voisine/)).toBeInTheDocument()
  })

  it('affiche les courses localisées dans la vue carte', async () => {
    const date = new Date()
    const futureDate = new Date(date.getFullYear(), date.getMonth() + 1, 15)
    const eventDate = `${futureDate.getFullYear()}-${String(futureDate.getMonth() + 1).padStart(2, '0')}-15`
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [
        { id: 1, name: 'Trail localisé', location: 'Lyon', latitude: 45.764, longitude: 4.8357, eventDate, formats: [{ id: 11, label: '25 km', registrations: [{ id: 20, userId: 4, displayName: 'Membre test' }] }], comments: [], permissions: {} },
        { id: 2, name: 'Ancienne course', location: 'Paris', latitude: null, longitude: null, eventDate, formats: [], comments: [], permissions: {} },
      ] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course: { id: 1, name: 'Trail localisé', location: 'Lyon', latitude: 45.764, longitude: 4.8357, eventDate, formats: [{ id: 11, label: '25 km', registrations: [{ id: 20, userId: 4, displayName: 'Membre test' }] }], comments: [], permissions: {} } }), { status: 200 }))
    render(<App />)
    await screen.findByText('Calendrier des courses')
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    fireEvent.click(screen.getByRole('button', { name: 'Carte' }))
    expect(screen.getByText(/Du .* au .*/)).toBeInTheDocument()
    const period = screen.getByRole('group', { name: 'Période affichée sur la carte' })
    expect(within(period).getByRole('button', { name: '4 mois' })).toHaveAttribute('aria-pressed', 'true')
    expect(within(period).getByRole('button', { name: '12 mois' })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByTestId('map-canvas')).toHaveTextContent('1 point')
    expect(screen.getByText('Choisissez une course')).toBeInTheDocument()
    expect(screen.queryByRole('complementary', { name: 'Aperçu de Trail localisé' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sélectionner Trail localisé' }))
    expect(screen.getByRole('complementary', { name: 'Aperçu de Trail localisé' })).toHaveTextContent('25 km')
    expect(screen.getByText('1 inscrit')).toBeInTheDocument()
    expect(screen.getByText(/1 course de cette période sans coordonnées/)).toBeInTheDocument()
    const accessibility = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } })
    expect(accessibility.violations).toEqual([])
    fireEvent.click(screen.getByRole('button', { name: 'Fermer l’aperçu' }))
    expect(screen.queryByRole('complementary', { name: 'Aperçu de Trail localisé' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sélectionner Trail localisé' }))
    fireEvent.click(screen.getByRole('button', { name: 'Ouvrir la fiche complète' }))
    await waitForCourseDetail()
    expect(screen.getByRole('dialog', { name: 'Trail localisé' })).toBeInTheDocument()
  })

  it('remplace la carte par un message neutre quand elle est désactivée, sans bloquer le calendrier', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const disabled = { ...bootstrap, settings: { ...bootstrap.settings, mapEnabled: false } }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(disabled), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [
        { id: 1, name: 'Trail localisé', location: 'Lyon', latitude: 45.764, longitude: 4.8357, eventDate, formats: [], comments: [], permissions: {} },
      ] }), { status: 200 }))
    render(<App />)
    expect(await screen.findByRole('button', { name: /Trail localisé/ })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Carte' }))
    expect(screen.getByText('La carte n’est pas activée sur ce site.')).toBeInTheDocument()
    expect(screen.queryByTestId('map-canvas')).not.toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Période affichée sur la carte' })).not.toBeInTheDocument()
    const accessibility = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } })
    expect(accessibility.violations).toEqual([])
    fireEvent.click(screen.getByRole('button', { name: 'Calendrier' }))
    expect(screen.getByRole('button', { name: /Trail localisé/ })).toBeInTheDocument()
  })

  it('n’affiche pas l’aperçu cartographique du formulaire quand la carte est désactivée', async () => {
    const disabled = { ...bootstrap, settings: { ...bootstrap.settings, mapEnabled: false } }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(disabled), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockImplementation((url) => {
        if (url.includes('/courses/duplicates/')) return Promise.resolve(new Response(JSON.stringify({ duplicates: [] }), { status: 200 }))
        if (url.includes('/locations/')) return Promise.resolve(new Response(JSON.stringify({ locations: [{ label: 'Lyon, France', latitude: 45.764, longitude: 4.8357 }] }), { status: 200 }))
        return Promise.resolve(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      })
    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: /Ajouter une course$/ }))
    fireEvent.change(screen.getByLabelText('Lieu de départ'), { target: { value: 'Lyon' } })
    fireEvent.click(await screen.findByRole('option', { name: 'Lyon, France' }))
    await waitFor(() => expect(screen.getByLabelText('Lieu de départ')).toHaveValue('Lyon, France'))
    expect(screen.queryByRole('region', { name: 'Aperçu du départ à Lyon, France' })).not.toBeInTheDocument()
    expect(screen.queryByTestId('map-canvas')).not.toBeInTheDocument()
  })

  it('applique les réglages de design aux variables CSS du calendrier', async () => {
    const designed = { ...bootstrap, settings: { ...bootstrap.settings, textColor: '#112233', fontFamily: "'Open Sans', Arial", contentMaxWidth: '900px' } }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(designed), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    const shell = document.querySelector('.crc-shell')
    expect(shell.style.getPropertyValue('--crc-ink')).toBe('#112233')
    expect(shell.style.getPropertyValue('--crc-font')).toBe("'Open Sans', Arial")
    expect(shell.style.getPropertyValue('--crc-max-width')).toBe('900px')
  })

  it('affiche l’image sur la carte de course et dans la fiche', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const course = { id: 3, name: 'Trail imagé', location: 'Annecy', eventDate, formats: [], comments: [], permissions: {}, image: { url: '/trail-large.webp', cardUrl: '/trail-card.webp', srcSet: null, alt: 'Départ du Trail imagé', width: 1200, height: 675 } }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [course] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course }), { status: 200 }))
    render(<App />)
    const card = await screen.findByRole('button', { name: /Trail imagé/ })
    expect(card.querySelector('img')).toHaveAttribute('src', '/trail-card.webp')
    fireEvent.click(card)
    expect(await screen.findByAltText('Départ du Trail imagé')).toHaveAttribute('src', '/trail-large.webp')
  })

  it('replie par défaut les formats avec beaucoup d’inscrits', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const registrations = Array.from({ length: 9 }, (_, index) => ({ id: 100 + index, userId: 10 + index, displayName: `Membre ${index + 1}` }))
    const course = {
      id: 4,
      name: 'Ultra très fréquenté',
      location: 'Chamonix',
      eventDate,
      formats: [{ id: 41, label: '175 km', registrations }],
      comments: [],
      permissions: {},
    }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [course] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course }), { status: 200 }))

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Ultra très fréquenté/ }))
    await waitForCourseDetail()
    const dialog = screen.getByRole('dialog', { name: 'Ultra très fréquenté' })
    const toggle = within(dialog).getByRole('button', { name: /175 km.*9 inscrits/ })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByText('Membre 1')).not.toBeInTheDocument()

    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('Membre 1')).toBeInTheDocument()
  })

  it('demande confirmation avant une désinscription', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const course = {
      id: 5,
      name: 'Course avec inscription',
      location: 'Lyon',
      eventDate,
      formats: [{ id: 51, label: '20 km', registrations: [{ id: 501, userId: 2, displayName: 'Membre Actif' }] }],
      comments: [],
      permissions: { register: true },
    }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [course] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course }), { status: 200 }))
    window.confirm.mockReturnValue(false)

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Course avec inscription/ }))
    await waitForCourseDetail()
    const dialog = screen.getByRole('dialog', { name: 'Course avec inscription' })
    expect(within(dialog).getByRole('button', { name: /20 km.*1 inscrit/ })).toHaveAttribute('aria-expanded', 'true')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Désinscrire Membre Actif' }))

    expect(window.confirm).toHaveBeenCalledWith('Désinscrire Membre Actif du format 20 km ?')
    expect(fetch).toHaveBeenCalledTimes(3)
  })

  it('affiche sans limitation un mois chargé de 30 courses', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const courses = Array.from({ length: 30 }, (_, index) => ({
      id: index + 1,
      name: `Course dense ${String(index + 1).padStart(2, '0')}`,
      location: 'Lyon',
      eventDate,
      formats: [{ id: index + 101, label: '25 km', registrations: [] }],
      comments: [],
      permissions: {},
      image: { url: '/course-large.webp', cardUrl: '/course-card.webp', srcSet: null, alt: '', width: 1200, height: 675 },
    }))
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses }), { status: 200 }))

    render(<App />)

    expect(await screen.findAllByText('30 courses')).toHaveLength(1)
    expect(screen.getAllByRole('button', { name: /Course dense/ })).toHaveLength(30)
    expect(document.querySelectorAll('.crc-card__media img')).toHaveLength(30)
  })

  it('affiche les résultats hebdomadaires, le bilan et copie la revue de la période', async () => {
    const year = new Date().getFullYear()
    const currentMonday = new Date()
    const currentDay = currentMonday.getDay() || 7
    currentMonday.setDate(currentMonday.getDate() - currentDay + 1)
    const previousMonday = new Date(currentMonday)
    previousMonday.setDate(previousMonday.getDate() - 7)
    const previousSunday = new Date(previousMonday)
    previousSunday.setDate(previousSunday.getDate() + 6)
    const dateKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
    const season = {
      year,
      years: [year, year - 1],
      stats: {
        coursesCount: 3,
        registrationsCount: 7,
        participantsCount: 4,
        racesByMonth: [{ month: 8, count: 3 }],
        topParticipants: [{ userId: 2, displayName: 'Membre Actif', racesCount: 3 }],
        topCourses: [{ id: 21, name: 'Trail des Alpes', eventDate: `${year}-08-15`, registrationsCount: 4 }],
      },
      weeks: [{
        key: `${year}-W33`, from: `${year}-08-10`, to: `${year}-08-16`,
        courses: [{
          id: 21, name: 'Trail des Alpes', eventDate: `${year}-08-15`, location: 'Annecy',
          participants: [
            { userId: 2, displayName: 'Membre Actif', formats: ['42 km'], result: { formatLabel: '42 km', time: '5 h 12', ranking: '18e', distance: '42 km' } },
            { userId: 3, displayName: 'Marie', formats: ['20 km'], result: null },
          ],
        }],
      }],
    }
    const review = {
      from: season.weeks[0].from,
      to: season.weeks[0].to,
      courses: season.weeks[0].courses,
      truncated: false,
    }
    const adjustedReview = { ...review, from: `${year}-08-11`, to: `${year}-08-15` }
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ review }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ review: adjustedReview }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ season }), { status: 200 }))

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: 'Revue de presse' }))
    expect(await screen.findByRole('heading', { name: 'Revue de presse' })).toBeInTheDocument()
    expect(await screen.findByText('Période sélectionnée')).toBeInTheDocument()
    expect(await screen.findByText('5 h 12 · 18e')).toBeInTheDocument()
    expect(screen.getByText('Résultat à compléter')).toBeInTheDocument()
    expect(screen.getByLabelText('Du')).toHaveValue(dateKey(previousMonday))
    expect(screen.getByLabelText('Au')).toHaveValue(dateKey(previousSunday))
    fireEvent.click(screen.getByRole('button', { name: 'Copier la revue' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledOnce())
    expect(writeText.mock.calls[0][0]).toContain('Trail des Alpes')
    expect(await screen.findByText('Récapitulatif copié.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Du'), { target: { value: `${year}-08-11` } })
    fireEvent.change(screen.getByLabelText('Au'), { target: { value: `${year}-08-15` } })
    fireEvent.click(screen.getByRole('button', { name: 'Afficher' }))
    await waitFor(() => expect(fetch.mock.calls[3][0]).toContain(`review/?from=${year}-08-11&to=${year}-08-15`))

    fireEvent.click(screen.getByRole('button', { name: 'Bilan' }))
    expect(await screen.findByRole('heading', { name: 'Bilan de la saison' })).toBeInTheDocument()
    expect(screen.getByText('Inscriptions').closest('article')).toHaveTextContent('7')
    expect(screen.getByRole('heading', { name: 'Les plus actifs' })).toBeInTheDocument()
  })

  it('permet à un inscrit de publier un covoiturage puis son résultat', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const summary = { id: 31, name: 'Ultra du test', location: 'Lyon', eventDate, formats: [{ id: 311, label: '50 km', registrationCount: 1 }], comments: null, permissions: {} }
    const detail = {
      ...summary,
      formats: [{ id: 311, label: '50 km', registrations: [{ id: 901, userId: 2, displayName: 'Membre Actif' }] }],
      comments: [], carpools: [], results: [],
      permissions: { edit: false, delete: false, register: true, comment: true, carpool: true, result: true },
    }
    const withCarpool = { ...detail, carpools: [{ id: 41, userId: 2, displayName: 'Membre Actif', type: 'driver', seats: 3, departure: 'Lyon Part-Dieu', note: 'Départ à 6 h', updatedAt: '2026-08-19T10:00:00Z' }] }
    const withResult = { ...withCarpool, results: [{ id: 51, userId: 2, displayName: 'Membre Actif', formatId: 311, formatLabel: '50 km', time: '6 h 45', ranking: '32e', distance: '51 km', note: null, updatedAt: '2026-08-19T11:00:00Z' }] }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [summary] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course: detail }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ carpool: withCarpool.carpools[0] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [summary] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course: withCarpool }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ result: withResult.results[0] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [summary] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course: withResult }), { status: 200 }))

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Ultra du test/ }))
    expect(await screen.findByRole('heading', { name: 'Covoiturage' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Je conduis' })).toBeChecked()
    fireEvent.click(screen.getByRole('radio', { name: 'Je cherche une place' }))
    expect(screen.getByRole('radio', { name: 'Je cherche une place' })).toBeChecked()
    fireEvent.click(screen.getByRole('radio', { name: 'Je conduis' }))
    fireEvent.change(screen.getByLabelText('Départ ou zone'), { target: { value: 'Lyon Part-Dieu' } })
    fireEvent.change(screen.getByLabelText('Places disponibles'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Précision (optionnel)'), { target: { value: 'Départ à 6 h' } })
    fireEvent.click(screen.getByRole('button', { name: 'Publier' }))
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(6))
    expect(fetch.mock.calls[3][0]).toBe('/wp-json/community-race-calendar/v1/courses/31/carpool/')
    expect(JSON.parse(fetch.mock.calls[3][1].body)).toEqual({ type: 'driver', seats: 3, departure: 'Lyon Part-Dieu', note: 'Départ à 6 h' })
    expect(await screen.findByText('Covoiturage ajouté.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Temps'), { target: { value: '6 h 45' } })
    fireEvent.change(screen.getByLabelText('Classement par genre'), { target: { value: '32e' } })
    expect(screen.queryByLabelText('Distance')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Publier mon résultat' }))
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(9))
    expect(fetch.mock.calls[6][0]).toBe('/wp-json/community-race-calendar/v1/courses/31/result/')
    expect(JSON.parse(fetch.mock.calls[6][1].body)).toEqual({ formatId: 311, time: '6 h 45', ranking: '32e', note: '' })
    expect(await screen.findByText('Résultat ajouté.')).toBeInTheDocument()
    expect(screen.getByText('6 h 45 · 32e')).toBeInTheDocument()
  })

  it('ouvre covoiturage et commentaires à un adhérent non inscrit', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-18`
    const summary = { id: 32, name: 'Trail accompagné', location: 'Annecy', eventDate, formats: [{ id: 321, label: '42 km', registrationCount: 1 }], comments: null, permissions: {} }
    const detail = {
      ...summary,
      formats: [{ id: 321, label: '42 km', registrations: [{ id: 902, userId: 8, displayName: 'Autre membre' }] }],
      comments: [], carpools: [], results: [],
      permissions: { register: true, comment: true, carpool: true, result: false },
    }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [summary] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course: detail }), { status: 200 }))

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Trail accompagné/ }))
    expect(await screen.findByRole('heading', { name: 'Covoiturage' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Je conduis' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Assistance et ravitaillement' })).not.toBeInTheDocument()
    expect(screen.getByPlaceholderText('Ajouter un commentaire…')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Publier mon résultat' })).not.toBeInTheDocument()
  })

  it('géocode et envoie les coordonnées lors de la création', async () => {
    const createdCourse = { id: 7, name: 'Trail GPS', location: 'Lyon, France', latitude: 45.764, longitude: 4.8357, eventDate: '2026-08-01', formats: [], comments: [], permissions: {} }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockImplementation((url, options = {}) => {
        if (url.includes('/courses/duplicates/')) return Promise.resolve(new Response(JSON.stringify({ duplicates: [] }), { status: 200 }))
        if (url.includes('/locations/')) return Promise.resolve(new Response(JSON.stringify({ locations: [{ label: 'Lyon, France', latitude: 45.764, longitude: 4.8357 }] }), { status: 200 }))
        if (options.method === 'POST') return Promise.resolve(new Response(JSON.stringify({ course: createdCourse }), { status: 201 }))
        return Promise.resolve(new Response(JSON.stringify({ courses: [createdCourse] }), { status: 200 }))
      })

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: /Ajouter une course$/ }))
    const modalAccessibility = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } })
    expect(modalAccessibility.violations).toEqual([])
    fireEvent.change(screen.getByLabelText('Nom de la course'), { target: { value: 'Trail GPS' } })
    fireEvent.change(screen.getByLabelText('Lieu de départ'), { target: { value: 'Lyon' } })
    fireEvent.change(screen.getByLabelText('Format 1'), { target: { value: '25 km' } })
    const image = new File(['image-test'], 'trail.webp', { type: 'image/webp' })
    fireEvent.change(screen.getByLabelText(/Ajouter un logo/), { target: { files: [image] } })
    const locationInput = screen.getByLabelText('Lieu de départ')
    const locationOption = await screen.findByRole('option', { name: 'Lyon, France' })
    expect(locationInput).toHaveAttribute('aria-expanded', 'true')
    fireEvent.keyDown(locationInput, { key: 'ArrowDown' })
    expect(locationOption).toHaveAttribute('aria-selected', 'true')
    expect(locationInput).toHaveAttribute('aria-activedescendant', locationOption.id)
    fireEvent.keyDown(locationInput, { key: 'Enter' })
    await waitFor(() => expect(screen.getByLabelText('Lieu de départ')).toHaveValue('Lyon, France'))
    expect(screen.queryByText('Lieu reconnu pour la vue Carte')).not.toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Aperçu du départ à Lyon, France' })).toHaveTextContent('Point sélectionné')
    expect(screen.queryByRole('button', { name: 'Placer le point test' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(fetch.mock.calls.some(([url, options]) => url.endsWith('/courses/') && options.method === 'POST')).toBe(true))
    expect(fetch.mock.calls.some(([url]) => url === '/wp-json/community-race-calendar/v1/locations/?query=Lyon')).toBe(true)
    const createOptions = fetch.mock.calls.find(([url, options]) => url.endsWith('/courses/') && options.method === 'POST')[1]
    expect(createOptions.method).toBe('POST')
    expect(createOptions.body).toBeInstanceOf(FormData)
    expect(createOptions.headers['Content-Type']).toBeUndefined()
    expect(JSON.parse(createOptions.body.get('payload'))).toMatchObject({ location: 'Lyon, France', latitude: 45.764, longitude: 4.8357, removeImage: false })
    expect(createOptions.body.get('image')).toEqual(image)
  })

  it('remplace la fiche par une seule modale lors de la modification', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const course = {
      id: 8,
      name: 'Trail à modifier',
      location: 'Lyon',
      latitude: 45.764,
      longitude: 4.8357,
      eventDate,
      eventUrl: '',
      formats: [{ id: 81, label: '50 km', registrations: [] }],
      comments: [],
      permissions: { edit: true, delete: true, register: true, comment: true },
    }
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [course] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course }), { status: 200 }))

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Trail à modifier/ }))
    await waitForCourseDetail()
    expect(screen.getByRole('dialog', { name: 'Trail à modifier' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }))

    expect(screen.getAllByRole('dialog')).toHaveLength(1)
    expect(screen.getByRole('dialog', { name: 'Modifier la course' })).toBeInTheDocument()
    expect(screen.queryByRole('dialog', { name: 'Trail à modifier' })).not.toBeInTheDocument()
    expect(screen.getByText('Un champ par distance ou parcours : 50 km, 100 km, OCC…')).toBeInTheDocument()
    expect(screen.getByLabelText('Format 1')).toHaveAttribute('placeholder', 'Ex. 50 km')
    expect(screen.getByText('Logo de la course')).toBeInTheDocument()
  })

  it('rouvre instantanément une fiche déjà chargée puis la met à jour', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const course = { id: 12, name: 'Course en cache', location: 'Annecy', eventDate, formats: [{ id: 121, label: '30 km', registrations: [] }], comments: [], permissions: {} }
    const updated = { ...course, formats: [{ id: 121, label: '30 km', registrations: [{ id: 1, userId: 5, displayName: 'Camille Durand' }] }] }
    let resolveRevalidation
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [course] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ course }), { status: 200 }))
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRevalidation = resolve }))

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Course en cache/ }))
    await waitForCourseDetail()
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Course en cache' })).getByRole('button', { name: 'Fermer' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: /Course en cache/ }))
    expect(screen.queryByText('Chargement des inscrits et des échanges…')).not.toBeInTheDocument()
    expect(within(screen.getByRole('dialog', { name: 'Course en cache' })).getByText('30 km')).toBeInTheDocument()
    resolveRevalidation(new Response(JSON.stringify({ course: updated }), { status: 200 }))
    expect(await screen.findByText('Camille Durand')).toBeInTheDocument()
  })

  it('annule le chargement d’une fiche fermée sans rouvrir une course obsolète', async () => {
    const date = new Date()
    const eventDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-15`
    const summary = { id: 9, name: 'Course lente', location: 'Lyon', eventDate, formats: [], comments: [], permissions: {} }
    let resolveDetail
    const detailResponse = new Promise((resolve) => { resolveDetail = resolve })
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [summary] }), { status: 200 }))
      .mockImplementationOnce(() => detailResponse)

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Course lente/ }))
    const loadingDialog = await screen.findByRole('dialog', { name: 'Course lente' })
    expect(loadingDialog).toHaveTextContent('Lyon')
    expect(loadingDialog).toHaveTextContent('Chargement des inscrits et des échanges…')
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }))
    resolveDetail(new Response(JSON.stringify({ course: summary }), { status: 200 }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.queryByRole('heading', { name: 'Course lente' })).not.toBeInTheDocument()
  })

  it('conserve un lieu introuvable sans inventer de coordonnées', async () => {
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ locations: [] }), { status: 200 }))

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: /Ajouter une course$/ }))
    fireEvent.change(screen.getByLabelText('Lieu de départ'), { target: { value: 'Lieu inconnu' } })

    expect(await screen.findByText(/Aucune proposition trouvée/)).toBeInTheDocument()
    expect(screen.queryByText('Lieu reconnu pour la vue Carte')).not.toBeInTheDocument()
  })

  it('enregistre un lieu libre sans Geoapify et affiche une erreur serveur sans fermer la modale', async () => {
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify({ ...bootstrap, geocoding: { ...bootstrap.geocoding, available: false } }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockImplementation((url, options = {}) => {
        if (options.method === 'POST') return Promise.resolve(new Response(JSON.stringify({ message: 'Écriture refusée par le serveur.' }), { status: 500 }))
        return Promise.resolve(new Response(JSON.stringify(String(url).includes('/courses/editions') ? { editions: [] } : { duplicates: [] }), { status: 200 }))
      })

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: /Ajouter une course$/ }))
    fireEvent.change(screen.getByLabelText('Nom de la course'), { target: { value: 'Course en erreur' } })
    fireEvent.change(screen.getByLabelText('Lieu de départ'), { target: { value: 'Lyon' } })
    fireEvent.change(screen.getByLabelText('Format 1'), { target: { value: '10 km' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Écriture refusée par le serveur.')
    expect(screen.getByRole('dialog', { name: /Nouvelle course/ })).toBeInTheDocument()
    const writes = fetch.mock.calls.filter(([, options = {}]) => options.method === 'POST')
    expect(writes).toHaveLength(1)
    expect(JSON.parse(writes[0][1].body.get('payload'))).toMatchObject({ location: 'Lyon', latitude: null, longitude: null })
  })

  it('signale un doublon du même mois sans bloquer la création', async () => {
    const today = new Date()
    const eventDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-12`
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockImplementation((url) => Promise.resolve(new Response(JSON.stringify(String(url).includes('/courses/editions')
        ? { editions: [] }
        : { duplicates: [{ id: 44, name: 'Trail du Lac', eventDate, location: 'Annecy' }] }), { status: 200 })))

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une course' }))
    fireEvent.change(screen.getByLabelText('Nom de la course'), { target: { value: 'Trail du Lac' } })

    const warningTitle = await screen.findByText('Cette course semble déjà présente.')
    expect(warningTitle.closest('.crc-duplicate-warning')).not.toHaveTextContent('Vous pouvez tout de même l’enregistrer.')
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeEnabled()
  })

  it('préremplit une nouvelle édition depuis la précédente et réutilise son logo', async () => {
    const previous = {
      id: 90, name: 'Swiss Peak', eventDate: '2025-09-04', location: 'Barrage de la Dixence', latitude: 46.08, longitude: 7.4,
      eventUrl: 'https://swisspeaks.ch', formats: [{ id: 901, label: 'Odyssey' }, { id: 902, label: '100 Miles' }],
      image: { id: 555, url: 'https://example.test/logo-swiss-peak.webp', cardUrl: 'https://example.test/logo-swiss-peak.webp' }, permissions: {},
    }
    const posted = []
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockImplementation((url, options = {}) => {
        const target = String(url)
        if (options.method === 'POST') {
          posted.push(options.body)
          return Promise.resolve(new Response(JSON.stringify({ course: { ...previous, id: 91 } }), { status: 201 }))
        }
        if (target.includes('/courses/editions')) return Promise.resolve(new Response(JSON.stringify({ editions: [previous] }), { status: 200 }))
        if (target.includes('/courses/duplicates')) return Promise.resolve(new Response(JSON.stringify({ duplicates: [] }), { status: 200 }))
        return Promise.resolve(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      })

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une course' }))
    fireEvent.change(screen.getByLabelText('Nom de la course'), { target: { value: 'swiss' } })
    fireEvent.click(await screen.findByRole('option', { name: /Swiss Peak.*2025/ }))

    expect(screen.getByLabelText('Nom de la course')).toHaveValue('Swiss Peak')
    expect(screen.getByPlaceholderText('Ville, lieu ou adresse')).toHaveValue('Barrage de la Dixence')
    expect(screen.getByPlaceholderText('https://…')).toHaveValue('https://swisspeaks.ch')
    expect(screen.getByLabelText('Format 1')).toHaveValue('Odyssey')
    expect(screen.getByLabelText('Format 2')).toHaveValue('100 Miles')
    expect(screen.getByAltText('Aperçu du logo de la course')).toHaveAttribute('src', previous.image.url)
    expect(screen.getByText(/repris de l’édition du 4 sept\. 2025/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(posted).toHaveLength(1))
    const payload = JSON.parse(posted[0].get('payload'))
    expect(payload).toMatchObject({ name: 'Swiss Peak', reuseImageId: 555, formats: ['Odyssey', '100 Miles'], latitude: 46.08, longitude: 7.4 })
    expect(posted[0].get('image')).toBeNull()
  })

  it('ne réutilise pas le logo précédent si un autre fichier est choisi', async () => {
    const previous = { id: 90, name: 'Swiss Peak', eventDate: '2025-09-04', location: 'Hérémence', formats: [{ id: 901, label: 'Odyssey' }], image: { id: 555, url: 'https://example.test/logo.webp' }, permissions: {} }
    const posted = []
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [] }), { status: 200 }))
      .mockImplementation((url, options = {}) => {
        if (options.method === 'POST') {
          posted.push(options.body)
          return Promise.resolve(new Response(JSON.stringify({ course: { ...previous, id: 92 } }), { status: 201 }))
        }
        if (String(url).includes('/courses/editions')) return Promise.resolve(new Response(JSON.stringify({ editions: [previous] }), { status: 200 }))
        return Promise.resolve(new Response(JSON.stringify({ duplicates: [], courses: [] }), { status: 200 }))
      })

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une course' }))
    fireEvent.change(screen.getByLabelText('Nom de la course'), { target: { value: 'swiss' } })
    fireEvent.click(await screen.findByRole('option', { name: /Swiss Peak/ }))
    const file = new File(['logo'], 'nouveau.png', { type: 'image/png' })
    fireEvent.change(screen.getByLabelText('Remplacer le logo'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(posted).toHaveLength(1))
    expect(JSON.parse(posted[0].get('payload'))).not.toHaveProperty('reuseImageId')
    expect(posted[0].get('image')).not.toBeNull()
  })

  it('suggère le nom complet d’une course existante pendant la saisie', async () => {
    const today = new Date()
    const eventDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-12`
    fetch.mockReset()
      .mockResolvedValueOnce(new Response(JSON.stringify(bootstrap), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ courses: [{ id: 77, name: 'Trail des Aiguilles Rouges', location: 'Chamonix', eventDate, formats: [], permissions: {} }] }), { status: 200 }))
      .mockResolvedValue(new Response(JSON.stringify({ duplicates: [] }), { status: 200 }))

    render(<App />)
    await screen.findByRole('button', { name: /Trail des Aiguilles Rouges/ })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une course' }))
    fireEvent.change(screen.getByLabelText('Nom de la course'), { target: { value: 'aig roug' } })

    const suggestion = await screen.findByRole('option', { name: /Trail des Aiguilles Rouges/ })
    fireEvent.click(suggestion)
    expect(screen.getByLabelText('Nom de la course')).toHaveValue('Trail des Aiguilles Rouges')
  })

  it('ouvre une corbeille discrète et restaure une course', async () => {
    const today = new Date()
    const eventDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-15`
    const course = { id: 51, name: 'Course à restaurer', location: 'Lyon', eventDate, deletedAt: '2026-08-20T08:00:00Z', formats: [{ id: 511, label: '20 km', registrationCount: 2 }], permissions: { restore: true } }
    let deleted = true
    fetch.mockReset().mockImplementation((url, options = {}) => {
      if (url.endsWith('/bootstrap/')) return Promise.resolve(new Response(JSON.stringify(bootstrap), { status: 200 }))
      if (url.endsWith('/courses/51/restore/') && options.method === 'POST') {
        deleted = false
        return Promise.resolve(new Response(JSON.stringify({ course: { ...course, deletedAt: null } }), { status: 200 }))
      }
      if (url.endsWith('/courses/deleted/')) return Promise.resolve(new Response(JSON.stringify({ courses: deleted ? [course] : [] }), { status: 200 }))
      if (url.includes('/courses/?')) return Promise.resolve(new Response(JSON.stringify({ courses: deleted ? [] : [{ ...course, deletedAt: null }] }), { status: 200 }))
      return Promise.resolve(new Response(JSON.stringify({ message: 'Route inattendue' }), { status: 500 }))
    })

    render(<App />)
    await screen.findByText('Aucune course ce mois-ci.')
    const trashButton = screen.getByRole('button', { name: 'Ouvrir les courses supprimées récemment' })
    expect(trashButton).toHaveClass('crc-trash-button')
    fireEvent.click(trashButton)
    expect(await screen.findByText('Course à restaurer')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Restaurer' }))
    expect(await screen.findByText('Aucune course supprimée')).toBeInTheDocument()
    expect(await screen.findByText('« Course à restaurer » a été restaurée.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retour au calendrier' }))
    expect(await screen.findByRole('button', { name: /Course à restaurer/ })).toBeInTheDocument()
  })

  it('permet à un adhérent non administrateur d’ajouter une autre personne', async () => {
    const today = new Date()
    const eventDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-15`
    const summary = { id: 61, name: 'Trail collectif', location: 'Lyon', eventDate, formats: [{ id: 611, label: '30 km', registrationCount: 0 }], comments: null, permissions: {} }
    const detail = { ...summary, formats: [{ id: 611, label: '30 km', registrations: [] }], comments: [], carpools: [], results: [], permissions: { register: true } }
    const registeredDetail = { ...detail, formats: [{ id: 611, label: '30 km', registrations: [{ id: 900, userId: 7, displayName: 'Marie Martin' }] }] }
    let registered = false
    fetch.mockReset().mockImplementation((url, options = {}) => {
      if (url.endsWith('/bootstrap/')) return Promise.resolve(new Response(JSON.stringify(bootstrap), { status: 200 }))
      if (url.includes('/members/')) return Promise.resolve(new Response(JSON.stringify({ members: [{ id: 7, displayName: 'Marie Martin', label: 'Marie Martin · @marie' }], nextCursor: null }), { status: 200 }))
      if (url.endsWith('/courses/61/registrations/') && options.method === 'POST') {
        registered = true
        return Promise.resolve(new Response(JSON.stringify({ course: registeredDetail }), { status: 201 }))
      }
      if (url.endsWith('/courses/61/')) return Promise.resolve(new Response(JSON.stringify({ course: registered ? registeredDetail : detail }), { status: 200 }))
      if (url.includes('/courses/?')) return Promise.resolve(new Response(JSON.stringify({ courses: [{ ...summary, formats: [{ ...summary.formats[0], registrationCount: registered ? 1 : 0 }] }] }), { status: 200 }))
      return Promise.resolve(new Response(JSON.stringify({ message: 'Route inattendue' }), { status: 500 }))
    })

    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: /Trail collectif/ }))
    expect(await screen.findByRole('heading', { name: 'Ajouter une personne' })).toBeInTheDocument()
    fireEvent.change(screen.getByPlaceholderText('Au moins 2 caractères'), { target: { value: 'Marie' } })
    const memberSelect = await screen.findByRole('combobox', { name: 'Membre actif' })
    fireEvent.change(memberSelect, { target: { value: '7' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    expect(await screen.findByText('Membre ajouté à la course.')).toBeInTheDocument()
    expect(screen.getByText('Marie Martin')).toBeInTheDocument()
    const registrationCall = fetch.mock.calls.find(([url]) => url.endsWith('/courses/61/registrations/'))
    expect(JSON.parse(registrationCall[1].body)).toEqual({ formatId: 611, userId: 7 })
  })
})
