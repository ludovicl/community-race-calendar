import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  AlertCircle, CalendarDays, Car, ChartBar, Check, ChevronDown, ChevronRight,
  ClipboardCopy, ExternalLink, ImagePlus, List, Map as MapIcon, MapPin, Medal, Newspaper,
  MessageSquare, Pencil, Plus, RotateCcw, Search, Trash2, UserPlus, Users, X,
} from 'lucide-react'
import { api } from './api.js'
import MapCanvas from './MapCanvas.jsx'

const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre']

function monthWindow(period) {
  const from = period?.from ? new Date(`${period.from}T00:00:00`) : new Date()
  const to = period?.to ? new Date(`${period.to}T00:00:00`) : new Date(from.getFullYear(), from.getMonth() + 11, 1)
  const count = (to.getFullYear() - from.getFullYear()) * 12 + to.getMonth() - from.getMonth() + 1
  const currentDate = new Date()
  return Array.from({ length: count }, (_, index) => {
    const date = new Date(from.getFullYear(), from.getMonth() + index, 1)
    const offset = (date.getFullYear() - currentDate.getFullYear()) * 12 + date.getMonth() - currentDate.getMonth()
    return {
      year: date.getFullYear(), month: date.getMonth(), offset,
      key: `${date.getFullYear()}-${date.getMonth()}`,
    }
  })
}

function registrationTotal(course) {
  return course.formats.reduce((total, format) => total + (format.registrationCount ?? format.registrations?.length ?? 0), 0)
}

function formatDate(value) {
  return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  })
}

function courseMonthKey(course) {
  const date = new Date(`${course.eventDate}T00:00:00`)
  return `${date.getFullYear()}-${date.getMonth()}`
}

function shortDate(value) {
  return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

function editionDate(value) {
  return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
}

function mergeEditionSuggestions(remote, local) {
  const seen = new Set(remote.map((edition) => normalizeSearchText(edition.name)))
  return [...remote, ...local.filter((candidate) => !seen.has(normalizeSearchText(candidate.name)))].slice(0, 5)
}

function localDateKey(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function normalizeSearchText(value) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase('fr')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function courseNameSuggestions(courses, query, excludedCourseId) {
  const normalizedQuery = normalizeSearchText(query)
  if (normalizedQuery.length < 2) return []

  const queryTokens = normalizedQuery.split(' ')
  return courses
    .filter((candidate) => candidate.id !== excludedCourseId)
    .map((candidate) => {
      const normalizedName = normalizeSearchText(candidate.name)
      const nameTokens = normalizedName.split(' ')
      const matchingTokens = queryTokens.filter((token) => nameTokens.some((nameToken) => nameToken.startsWith(token))).length
      const startsWithQuery = normalizedName.startsWith(normalizedQuery)
      return { candidate, matchingTokens, startsWithQuery }
    })
    .filter(({ matchingTokens }) => matchingTokens === queryTokens.length)
    .sort((left, right) => Number(right.startsWithQuery) - Number(left.startsWithQuery) || right.matchingTokens - left.matchingTokens || left.candidate.name.localeCompare(right.candidate.name, 'fr'))
    .slice(0, 5)
    .map(({ candidate }) => candidate)
}

function mapDateWindow(now = new Date(), months = 4) {
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const to = new Date(now.getFullYear(), now.getMonth() + months, 0)
  const label = `Du ${from.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} au ${to.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}`
  return { from, to, label }
}

function previousWeekRange(now = new Date()) {
  const currentMonday = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const day = currentMonday.getDay() || 7
  currentMonday.setDate(currentMonday.getDate() - day + 1)
  const from = new Date(currentMonday)
  from.setDate(from.getDate() - 7)
  const to = new Date(from)
  to.setDate(to.getDate() + 6)
  return { from: localDateKey(from), to: localDateKey(to) }
}

function resultSummary(result) {
  if (!result) return 'Résultat à compléter'
  return [result.time, result.ranking].filter(Boolean).join(' · ') || result.note || 'Résultat renseigné'
}

export default function App() {
  const [bootstrap, setBootstrap] = useState(null)
  const [courses, setCourses] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [selectedId, setSelectedId] = useState(null)
  const [selectedCourse, setSelectedCourse] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [editor, setEditor] = useState(null)
  const [view, setView] = useState('calendar')
  const [seasonYear, setSeasonYear] = useState(new Date().getFullYear())
  const [season, setSeason] = useState(null)
  const [seasonLoading, setSeasonLoading] = useState(false)
  const [reviewRange, setReviewRange] = useState(() => previousWeekRange())
  const [review, setReview] = useState(null)
  const [reviewLoading, setReviewLoading] = useState(false)
  const [deletedCourses, setDeletedCourses] = useState([])
  const [trashLoading, setTrashLoading] = useState(false)
  const noticeTimer = useRef(null)
  const selectionRef = useRef(0)
  const detailCacheRef = useRef(new Map())
  const detailRequestsRef = useRef(new Map())

  const fetchCourseDetail = (id) => {
    const pending = detailRequestsRef.current.get(id)
    if (pending) return pending
    const request = api(`courses/${id}`)
      .then((payload) => {
        if (detailRequestsRef.current.get(id) === request) detailCacheRef.current.set(id, payload.course)
        return payload.course
      })
      .finally(() => { if (detailRequestsRef.current.get(id) === request) detailRequestsRef.current.delete(id) })
    detailRequestsRef.current.set(id, request)
    return request
  }

  const prefetchCourse = (id) => {
    if (!detailCacheRef.current.has(id)) fetchCourseDetail(id).catch(() => {})
  }

  const forgetCourseDetails = () => {
    detailCacheRef.current.clear()
    detailRequestsRef.current.clear()
  }

  const refresh = async () => {
    forgetCourseDetails()
    let page = 1
    const loaded = []
    do {
      const payload = await api(`courses?page=${page}&perPage=100`)
      loaded.push(...payload.courses)
      page = payload.nextPage
    } while (page)
    setCourses(loaded)
  }

  const loadCourse = async (id) => {
    const token = ++selectionRef.current
    const cached = detailCacheRef.current.get(id) || null
    setSelectedId(id)
    setSelectedCourse(cached)
    setDetailLoading(!cached)
    setError('')
    try {
      const course = await fetchCourseDetail(id)
      if (token !== selectionRef.current) return
      setSelectedCourse(course)
    } catch (caught) {
      if (token !== selectionRef.current || cached) return
      setSelectedId(null)
      setError(caught.message)
    } finally {
      if (token === selectionRef.current) setDetailLoading(false)
    }
  }

  const closeCourse = () => {
    selectionRef.current++
    setDetailLoading(false)
    setSelectedId(null)
    setSelectedCourse(null)
  }

  useEffect(() => {
    let active = true
    ;(async () => {
      try {
        const initial = await api('bootstrap')
        if (!active) return
        setBootstrap(initial)
        if (initial.accessGranted) await refresh()
      } catch (caught) {
        if (active) setError(caught.message)
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => { active = false; clearTimeout(noticeTimer.current) }
  }, [])

  useEffect(() => {
    if (view !== 'summary' || !bootstrap?.accessGranted) return undefined
    const controller = new AbortController()
    setSeasonLoading(true)
    setError('')
    api(`season?year=${seasonYear}`, { signal: controller.signal })
      .then((payload) => setSeason(payload.season))
      .catch((caught) => { if (caught.name !== 'AbortError') setError(caught.message) })
      .finally(() => { if (!controller.signal.aborted) setSeasonLoading(false) })
    return () => controller.abort()
  }, [view, seasonYear, bootstrap?.accessGranted])

  useEffect(() => {
    if (view !== 'results' || !bootstrap?.accessGranted) return undefined
    const controller = new AbortController()
    setReviewLoading(true)
    setError('')
    api(`review?from=${reviewRange.from}&to=${reviewRange.to}`, { signal: controller.signal })
      .then((payload) => setReview(payload.review))
      .catch((caught) => { if (caught.name !== 'AbortError') setError(caught.message) })
      .finally(() => { if (!controller.signal.aborted) setReviewLoading(false) })
    return () => controller.abort()
  }, [view, reviewRange.from, reviewRange.to, bootstrap?.accessGranted])

  useEffect(() => () => { selectionRef.current++ }, [])

  const flash = (message) => {
    setNotice(message)
    clearTimeout(noticeTimer.current)
    noticeTimer.current = setTimeout(() => setNotice(''), 3500)
  }

  const mutate = async (callback, success, onError = setError) => {
    onError('')
    try {
      await callback()
      const [, detail] = await Promise.all([
        refresh(),
        selectedId ? api(`courses/${selectedId}`).then((payload) => payload.course).catch((caught) => {
          if (caught.status !== 404) throw caught
          return null
        }) : undefined,
      ])
      if (selectedId) {
        if (detail) detailCacheRef.current.set(selectedId, detail)
        setSelectedCourse(detail)
      }
      if (success) flash(success)
      return true
    } catch (caught) {
      onError(caught.message)
      return false
    }
  }

  const openTrash = async () => {
    setView('trash')
    setTrashLoading(true)
    setError('')
    try {
      const payload = await api('courses/deleted')
      setDeletedCourses(payload.courses)
    } catch (caught) {
      setError(caught.message)
    } finally {
      setTrashLoading(false)
    }
  }

  const restoreCourse = async (course) => {
    setError('')
    try {
      await api(`courses/${course.id}/restore`, { method: 'POST' })
      const payload = await api('courses/deleted')
      setDeletedCourses(payload.courses)
      await refresh()
      flash(`« ${course.name} » a été restaurée.`)
    } catch (caught) {
      setError(caught.message)
    }
  }

  const settings = bootstrap?.settings || {}
  const style = {
    '--crc-brand': settings.brandColor || '#1d4ed8',
    '--crc-background': settings.backgroundColor || '#f5f5f5',
    '--crc-surface': settings.surfaceColor || '#ffffff',
    '--crc-ink': settings.textColor || '#1f2933',
    '--crc-max-width': settings.contentMaxWidth || '1200px',
    '--crc-font': settings.fontFamily || 'inherit',
  }
  themeStyle = style

  if (loading) return <div className="crc-shell" style={style}><SkeletonState /></div>
  if (!bootstrap) return <div className="crc-shell" style={style}><State icon={AlertCircle} title="Calendrier indisponible" body={error} /></div>
  if (!bootstrap.accessGranted) {
    return (
      <div className="crc-shell" style={style}>
        <BrandHeader settings={settings} />
        <State
          icon={AlertCircle}
          title={bootstrap.accessCode === 'authentication_required' ? 'Connexion requise' : 'Accès indisponible'}
          body={bootstrap.accessMessage}
          action={bootstrap.accessCode === 'authentication_required'
            ? <a className="crc-button crc-button--primary" href={bootstrap.loginUrl}>Se connecter</a>
            : <a className="crc-button crc-button--primary" href={settings.membershipUrl}>Gérer mon adhésion</a>}
        />
      </div>
    )
  }

  return (
    <div className="crc-shell" style={style}>
      <BrandHeader settings={settings} user={bootstrap.user} />
      <div className="crc-main">
        <ViewNavigation view={view} onChange={setView} />
        {notice && <div className="crc-notice" role="status"><Check size={18} />{notice}</div>}
        {error && <div className="crc-error" role="alert"><AlertCircle size={18} /><span>{error}</span><button aria-label="Fermer l’erreur" onClick={() => setError('')}><X size={18} /></button></div>}
        {view === 'calendar' && <CalendarView courses={courses} period={bootstrap.period} canCreate={bootstrap.capabilities.createCourses} onOpen={loadCourse} onPrefetch={prefetchCourse} onCreate={() => setEditor({ mode: 'create' })} onTrash={openTrash} />}
        {view === 'map' && <MapView mapEnabled={settings.mapEnabled === true} courses={courses} onOpen={loadCourse} onPrefetch={prefetchCourse} />}
        {view === 'results' && <ReviewView review={review} loading={reviewLoading} range={reviewRange} brandName={settings.brandName} currentUser={bootstrap.user} onRange={setReviewRange} onOpen={loadCourse} flash={flash} />}
        {view === 'summary' && <SeasonView season={season} loading={seasonLoading} year={seasonYear} onYear={setSeasonYear} onOpen={loadCourse} />}
        {view === 'trash' && <TrashView courses={deletedCourses} loading={trashLoading} onBack={() => setView('calendar')} onRestore={restoreCourse} />}
      </div>

      {editor && (
        <CourseEditor
          course={editor.course}
          courses={courses}
          geocodingAvailable={bootstrap.geocoding?.available}
          mapEnabled={settings.mapEnabled === true}
          period={bootstrap.period}
          onClose={() => setEditor(null)}
          onSubmit={async ({ payload, imageFile, removeImage }) => {
            const body = new FormData()
            body.append('payload', JSON.stringify({ ...payload, removeImage }))
            if (imageFile) body.append('image', imageFile, imageFile.name)
            try {
              await api(editor.mode === 'edit' ? `courses/${editor.course.id}` : 'courses', {
                method: 'POST', body,
              })
              await refresh()
              flash(editor.mode === 'edit' ? 'Course modifiée.' : 'Course créée.')
              setEditor(null)
              return ''
            } catch (caught) {
              return caught.message
            }
          }}
        />
      )}

      {selectedId && detailLoading && <CourseLoading course={courses.find((course) => course.id === selectedId)} onClose={closeCourse} />}

      {selectedCourse && !editor && (
        <CourseDetail
          course={selectedCourse}
          currentUser={bootstrap.user}
          canManage={bootstrap.capabilities.manage}
          onClose={closeCourse}
          onEdit={() => {
            const course = selectedCourse
            closeCourse()
            setEditor({ mode: 'edit', course })
          }}
          mutate={mutate}
          onDeleted={() => { closeCourse(); flash('Course déplacée dans les éléments supprimés.') }}
        />
      )}
    </div>
  )
}

function ViewNavigation({ view, onChange }) {
  return (
    <nav className="crc-view-tabs" aria-label="Affichage du calendrier">
      <button type="button" className={view === 'calendar' ? 'is-active' : ''} aria-current={view === 'calendar' ? 'page' : undefined} onClick={() => onChange('calendar')}><List />Calendrier</button>
      <button type="button" className={view === 'map' ? 'is-active' : ''} aria-current={view === 'map' ? 'page' : undefined} onClick={() => onChange('map')}><MapIcon />Carte</button>
      <button type="button" className={view === 'results' ? 'is-active' : ''} aria-current={view === 'results' ? 'page' : undefined} onClick={() => onChange('results')}><Newspaper />Revue de presse</button>
      <button type="button" className={view === 'summary' ? 'is-active' : ''} aria-current={view === 'summary' ? 'page' : undefined} onClick={() => onChange('summary')}><ChartBar />Bilan</button>
    </nav>
  )
}

function ReviewView({ review, loading, range, brandName, currentUser, onRange, onOpen, flash }) {
  const [draft, setDraft] = useState(range)
  const [rangeError, setRangeError] = useState('')
  useEffect(() => setDraft(range), [range])
  const submit = (event) => {
    event.preventDefault()
    if (!draft.from || !draft.to || draft.from > draft.to) {
      setRangeError('La date de début doit précéder la date de fin.')
      return
    }
    setRangeError('')
    onRange(draft)
  }
  return (
    <section className="crc-season" aria-labelledby="crc-review-title">
      <header className="crc-season__header">
        <div><span className="crc-eyebrow">Résultats de la communauté</span><h2 id="crc-review-title">Revue de presse</h2><p>Sélectionnez les dates des courses à inclure. La semaine précédente est proposée automatiquement.</p></div>
        <form className="crc-date-range" onSubmit={submit}>
          <Field label="Du"><input type="date" required value={draft.from} onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))} /></Field>
          <Field label="Au"><input type="date" required value={draft.to} onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))} /></Field>
          <button className="crc-button crc-button--secondary" disabled={loading}>Afficher</button>
          {rangeError && <p className="crc-field-error" role="alert">{rangeError}</p>}
        </form>
      </header>
      {loading && <p className="crc-loading-inline" role="status">Actualisation…</p>}
      {!review && loading ? <State icon={CalendarDays} spinning title="Chargement de la revue…" /> : review
        ? <ReviewResults review={review} brandName={brandName} currentUser={currentUser} onOpen={onOpen} flash={flash} />
        : <State icon={AlertCircle} title="Revue indisponible" />}
    </section>
  )
}

function SeasonView({ season, loading, year, onYear, onOpen }) {
  if (loading && !season) return <State icon={CalendarDays} spinning title="Chargement de la saison…" />
  const years = season?.years?.length ? season.years : [year]
  return (
    <section className="crc-season" aria-labelledby="crc-season-title">
      <header className="crc-season__header">
        <div><span className="crc-eyebrow">Saison {year}</span><h2 id="crc-season-title">Bilan de la saison</h2><p>La saison en un coup d’œil : courses, participants et temps forts.</p></div>
        <label className="crc-year-select"><span>Année</span><select value={year} onChange={(event) => onYear(Number(event.target.value))}>{years.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      </header>
      {loading && <p className="crc-loading-inline" role="status">Actualisation…</p>}
      {!season ? <State icon={AlertCircle} title="Bilan indisponible" /> : <SeasonSummary stats={season.stats} onOpen={onOpen} />}
    </section>
  )
}

function ReviewResults({ review, brandName, currentUser, onOpen, flash }) {
  const previousWeek = previousWeekRange()
  const isPreviousWeek = review.from === previousWeek.from && review.to === previousWeek.to
  const copyReview = async () => {
    const lines = [`Revue de presse ${brandName} · du ${shortDate(review.from)} au ${shortDate(review.to)}`]
    review.courses.forEach((course) => {
      lines.push('', `${course.name} · ${shortDate(course.eventDate)} · ${course.location}`)
      course.participants.forEach((participant) => lines.push(`• ${participant.displayName} · ${participant.result?.formatLabel || participant.formats.join(', ')} · ${resultSummary(participant.result)}`))
    })
    try {
      await navigator.clipboard.writeText(lines.join('\n'))
      flash('Récapitulatif copié.')
    } catch {
      flash('Copie impossible sur ce navigateur.')
    }
  }
  if (!review.courses.length) return <div className="crc-season-empty"><Medal /><h3>Aucune course sur cette période</h3><p>Modifiez les dates pour préparer une autre revue.</p></div>
  return <div className="crc-week-list">
    <article className={`crc-week ${isPreviousWeek ? 'is-featured' : ''}`}>
      <header><div><span>{isPreviousWeek ? 'À publier · semaine précédente' : 'Période sélectionnée'}</span><strong>Du {shortDate(review.from)} au {shortDate(review.to)}</strong></div><button type="button" className="crc-button crc-button--ghost" onClick={copyReview}><ClipboardCopy />Copier la revue</button></header>
      <div className="crc-week__courses">{review.courses.map((course) => {
        const mine = course.participants.find((participant) => participant.userId === currentUser.id)
        return <section key={course.id}><div className="crc-week__course-title"><div><strong>{course.name}</strong><span>{shortDate(course.eventDate)} · {course.location}</span></div>{mine && <button type="button" className="crc-text-button" onClick={() => onOpen(course.id)}>{mine.result ? 'Modifier mon résultat' : 'Renseigner mon résultat'}<ChevronRight /></button>}</div>{course.participants.length ? <ul>{course.participants.map((participant) => <li key={participant.userId}><span><b>{participant.displayName}</b><small>{participant.result?.formatLabel || participant.formats.join(' · ')}</small></span><strong className={participant.result ? '' : 'is-missing'}>{resultSummary(participant.result)}</strong></li>)}</ul> : <p className="crc-empty-inline">Aucun membre inscrit.</p>}</section>
      })}</div>
    </article>
  </div>
}

function SeasonSummary({ stats, onOpen }) {
  const monthCounts = new Map((stats.racesByMonth || []).map((item) => [item.month, item.count]))
  const maxMonth = Math.max(1, ...monthCounts.values())
  return <>
    <div className="crc-kpis"><article><CalendarDays /><span>Courses</span><strong>{stats.coursesCount}</strong></article><article><Users /><span>Inscriptions</span><strong>{stats.registrationsCount}</strong></article><article><Medal /><span>Participants</span><strong>{stats.participantsCount}</strong></article></div>
    <div className="crc-summary-grid">
      <section className="crc-summary-card crc-summary-card--wide"><header><h3>Courses par mois</h3></header><div className="crc-month-bars">{MONTHS.map((month, index) => { const count = monthCounts.get(index + 1) || 0; return <div key={month}><div className="crc-month-bar"><span style={{ height: `${Math.max(count ? 12 : 2, (count / maxMonth) * 100)}%` }} /></div><small>{month.slice(0, 3)}</small><b>{count}</b></div> })}</div></section>
      <RankingCard title="Les plus actifs" items={stats.topParticipants} render={(item) => <><span>{item.displayName}</span><strong>{item.racesCount} course{item.racesCount === 1 ? '' : 's'}</strong></>} />
      <RankingCard title="Les courses fédératrices" items={stats.topCourses} onOpen={onOpen} render={(item) => <><span>{item.name}<small>{shortDate(item.eventDate)}</small></span><strong>{item.registrationsCount}</strong></>} />
    </div>
  </>
}

function RankingCard({ title, items = [], render, onOpen }) {
  return <section className="crc-summary-card"><header><h3>{title}</h3></header>{items.length ? <ol>{items.map((item, index) => <li key={item.id || item.userId}><span className="crc-rank">{index + 1}</span>{onOpen ? <button type="button" onClick={() => onOpen(item.id)}>{render(item)}</button> : <div>{render(item)}</div>}</li>)}</ol> : <p className="crc-empty-inline">Pas encore de données.</p>}</section>
}

function BrandHeader({ settings, user }) {
  return (
    <header className="crc-header">
      <div className="crc-header__inner">
        <div className="crc-brandmark"><span className="crc-brandmark__icon"><CalendarDays aria-hidden="true" /></span><div><strong>{settings.brandName}</strong><span>{settings.tagline}</span></div></div>
        {user && <div className="crc-header__meta"><span className="crc-user">{user.displayName}</span></div>}
      </div>
    </header>
  )
}

function CalendarView({ courses, period, canCreate, onOpen, onPrefetch, onCreate, onTrash }) {
  const months = useMemo(() => monthWindow(period), [period])
  const current = months.find((month) => month.offset === 0)
  const [openKey, setOpenKey] = useState(current?.key)
  const [query, setQuery] = useState('')
  const normalizedQuery = query.trim().toLocaleLowerCase('fr')
  const results = normalizedQuery
    ? courses.filter((course) => `${course.name} ${course.location}`.toLocaleLowerCase('fr').includes(normalizedQuery))
    : null

  if (results) {
    return (
      <section className="crc-calendar-view" aria-label="Calendrier des courses">
        <CalendarTools query={query} onQuery={setQuery} canCreate={canCreate} onCreate={onCreate} onTrash={onTrash} />
        <p className="crc-results-count" role="status" aria-live="polite" aria-atomic="true">{results.length} résultat{results.length === 1 ? '' : 's'} pour « {query} »</p>
        {results.length ? <div className="crc-card-grid">{results.map((course) => <CourseCard key={course.id} course={course} onOpen={onOpen} onPrefetch={onPrefetch} />)}</div> : <EmptyState />}
      </section>
    )
  }

  let previousGroup = null
  return (
    <section className="crc-calendar-view" aria-label="Calendrier des courses">
      <CalendarTools query={query} onQuery={setQuery} canCreate={canCreate} onCreate={onCreate} onTrash={onTrash} />
      <div className="crc-months">
        {months.map((month) => {
          const monthCourses = courses.filter((course) => courseMonthKey(course) === month.key)
          const group = month.offset < 0 ? 'Le mois dernier' : month.offset === 0 ? 'Ce mois-ci' : 'Les prochains mois'
          const showGroup = group !== previousGroup
          previousGroup = group
          const open = openKey === month.key
          return (
            <div key={month.key}>
              {showGroup && <h2 className={`crc-group-label ${month.offset === 0 ? 'is-current' : ''}`}>{group}</h2>}
              <article className={`crc-month ${month.offset === 0 ? 'is-current' : ''}`}>
                <button type="button" className="crc-month__toggle" aria-expanded={open} onClick={() => setOpenKey(open ? null : month.key)}>
                  <span><strong>{MONTHS[month.month]} {month.year}</strong><small>{monthCourses.length} course{monthCourses.length === 1 ? '' : 's'}</small></span>
                  {open ? <ChevronDown aria-hidden="true" /> : <ChevronRight aria-hidden="true" />}
                </button>
                {open && (
                  <div className="crc-month__content">
                    {monthCourses.length ? <div className="crc-card-grid">{monthCourses.map((course) => <CourseCard key={course.id} course={course} onOpen={onOpen} onPrefetch={onPrefetch} />)}</div> : <p className="crc-empty-inline">Aucune course ce mois-ci.</p>}
                  </div>
                )}
              </article>
            </div>
          )
        })}
      </div>
    </section>
  )
}

function CalendarTools({ query, onQuery, canCreate, onCreate, onTrash }) {
  return <div className="crc-calendar-tools">
    <SearchField value={query} onChange={onQuery} />
    <button type="button" className="crc-trash-button" aria-label="Ouvrir les courses supprimées récemment" title="Supprimées récemment" onClick={onTrash}><Trash2 /></button>
    {canCreate && <button type="button" className="crc-button crc-button--primary crc-create-button" onClick={onCreate}><Plus />Ajouter une course</button>}
  </div>
}

function TrashView({ courses, loading, onBack, onRestore }) {
  return (
    <section className="crc-trash" aria-labelledby="crc-trash-title">
      <header className="crc-trash__header">
        <div><h2 id="crc-trash-title">Supprimées récemment</h2><p>Vos courses restent ici avec leurs inscrits, commentaires et résultats.</p></div>
        <button type="button" className="crc-button crc-button--ghost" onClick={onBack}>Retour au calendrier</button>
      </header>
      {loading ? <State icon={Trash2} spinning title="Chargement…" /> : courses.length ? <div className="crc-trash-list">{courses.map((course) => (
        <article className="crc-trash-item" key={course.id}>
          {course.image && <img src={course.image.cardUrl} alt="" loading="lazy" decoding="async" />}
          <div><strong>{course.name}</strong><span>{formatDate(course.eventDate)} · {course.location}</span><small>Supprimée le {new Date(course.deletedAt).toLocaleDateString('fr-FR')}</small></div>
          <button type="button" className="crc-button crc-button--secondary" onClick={() => onRestore(course)}><RotateCcw />Restaurer</button>
        </article>
      ))}</div> : <div className="crc-season-empty"><Trash2 /><h3>Aucune course supprimée</h3><p>Les courses que vous supprimez apparaîtront ici.</p></div>}
    </section>
  )
}

function MapView({ mapEnabled, courses, onOpen, onPrefetch }) {
  const [periodMonths, setPeriodMonths] = useState(4)
  const dateWindow = useMemo(() => mapDateWindow(new Date(), periodMonths), [periodMonths])
  const coursesInWindow = useMemo(() => courses.filter((course) => {
    const eventDate = new Date(`${course.eventDate}T00:00:00`)
    return eventDate >= dateWindow.from && eventDate <= dateWindow.to
  }), [courses, dateWindow])
  const located = useMemo(() => coursesInWindow.filter((course) => Number.isFinite(course.latitude) && Number.isFinite(course.longitude)), [coursesInWindow])
  const missing = coursesInWindow.length - located.length
  const [highlightedId, setHighlightedId] = useState(null)
  const highlightedCourse = located.find((course) => course.id === highlightedId) || null

  useEffect(() => {
    if (highlightedId !== null && !highlightedCourse) setHighlightedId(null)
  }, [highlightedCourse, highlightedId])

  useEffect(() => {
    if (highlightedId !== null) onPrefetch?.(highlightedId)
  }, [highlightedId, onPrefetch])

  return (
    <section className="crc-map-view" aria-labelledby="crc-map-title">
      <div className="crc-map-view__intro">
        <div><h2 id="crc-map-title">Les courses sur la carte</h2>{mapEnabled && <p>Sélectionnez un logo pour découvrir la course et ouvrir sa fiche.</p>}</div>
        {mapEnabled && <div className="crc-map-period-control"><div className="crc-map-period" role="group" aria-label="Période affichée sur la carte"><CalendarDays />{[4, 6, 12].map((months) => <button key={months} type="button" aria-pressed={periodMonths === months} onClick={() => setPeriodMonths(months)}>{months} mois</button>)}</div><span className="crc-map-period-label">{dateWindow.label}</span></div>}
      </div>
      {!mapEnabled && <aside className="crc-map-prompt crc-map-disabled" aria-live="polite"><MapPin aria-hidden="true" /><div><strong>La carte n’est pas activée sur ce site.</strong><p>Les courses restent consultables dans le calendrier et dans les autres vues.</p></div></aside>}
      {mapEnabled && <div className="crc-map-layout">
        <div className="crc-map-stage">
          <MapCanvas points={located} activePointId={highlightedId} onSelect={setHighlightedId} label="Carte des prochaines courses" />
        </div>
        {highlightedCourse
          ? <MapCoursePreview course={highlightedCourse} onClose={() => setHighlightedId(null)} onOpen={() => onOpen(highlightedCourse.id)} />
          : <aside className="crc-map-prompt" aria-live="polite"><MapPin aria-hidden="true" /><div><strong>Choisissez une course</strong><p>Cliquez sur un marqueur pour afficher sa date, ses formats et les participants.</p></div></aside>}
      </div>}
      {mapEnabled && missing > 0 && <p className="crc-map-help">{missing} {missing === 1 ? 'course de cette période sans coordonnées reste disponible' : 'courses de cette période sans coordonnées restent disponibles'} dans le calendrier.</p>}
    </section>
  )
}

function MapCoursePreview({ course, onClose, onOpen }) {
  const registrations = registrationTotal(course)
  const visibleFormats = course.formats.slice(0, 3).map((format) => format.label)
  const remainingFormats = course.formats.length - visibleFormats.length
  const formats = visibleFormats.length ? `${visibleFormats.join(' · ')}${remainingFormats > 0 ? ` · +${remainingFormats}` : ''}` : 'Formats à venir'

  return (
    <aside className="crc-map-preview" aria-label={`Aperçu de ${course.name}`} aria-live="polite">
      <button type="button" className="crc-map-preview__close" aria-label="Fermer l’aperçu" onClick={onClose}><X /></button>
      {course.image && <figure className="crc-map-preview__image" onClick={onOpen}><img src={course.image.cardUrl} srcSet={course.image.srcSet || undefined} alt="" loading="lazy" decoding="async" /></figure>}
      <div className="crc-map-preview__content">
        <time dateTime={course.eventDate}>{formatDate(course.eventDate)}</time>
        <strong>{course.name}</strong>
        <span className="crc-map-preview__location"><MapPin />{course.location}</span>
        <button type="button" className="crc-map-preview__action" onClick={onOpen}>Ouvrir la fiche complète<ChevronRight /></button>
        <div className="crc-map-preview__facts">
          <span><small>Formats</small><b>{formats}</b></span>
          <span><small>Participants</small><b><Users />{registrations} inscrit{registrations === 1 ? '' : 's'}</b></span>
        </div>
      </div>
    </aside>
  )
}

function SearchField({ value, onChange }) {
  return (
    <div className="crc-search">
      <label className="crc-sr-only" htmlFor="crc-course-search">Rechercher une course par nom ou lieu</label>
      <Search aria-hidden="true" />
      <input id="crc-course-search" type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder="Rechercher une course ou un lieu…" />
      {value && <button type="button" aria-label="Effacer la recherche" onClick={() => onChange('')}><X /></button>}
    </div>
  )
}

function CourseLoading({ course, onClose }) {
  if (!course) return <Modal title="Chargement de la course" onClose={onClose}><State icon={CalendarDays} spinning title="Chargement…" /></Modal>
  return (
    <Modal title={course.name} onClose={onClose} wide>
      <div className="crc-course-loading">
        {course.image && <img className="crc-course-loading__image" src={course.image.cardUrl} srcSet={course.image.srcSet || undefined} alt="" decoding="async" />}
        <p className="crc-course-loading__meta"><time dateTime={course.eventDate}>{formatDate(course.eventDate)}</time><span><MapPin />{course.location}</span></p>
        <State icon={Users} spinning title="Chargement des inscrits et des échanges…" />
      </div>
    </Modal>
  )
}

function CourseCard({ course, onOpen, onPrefetch }) {
  const registrations = registrationTotal(course)
  return (
    <button type="button" className={`crc-card ${course.image ? 'has-image' : ''}`} onPointerEnter={() => onPrefetch?.(course.id)} onFocus={() => onPrefetch?.(course.id)} onClick={() => onOpen(course.id)}>
      {course.image && <span className="crc-card__media"><img src={course.image.cardUrl} srcSet={course.image.srcSet || undefined} sizes="88px" alt="" loading="lazy" decoding="async" /></span>}
      <span className="crc-card__content">
        <time dateTime={course.eventDate}>{formatDate(course.eventDate)}</time>
        <strong>{course.name}</strong>
        <span className="crc-card__location"><MapPin size={15} />{course.location}</span>
        <span className="crc-card__footer"><span>{course.formats.map((format) => format.label).join(' · ')}</span><span className="crc-count" aria-label={`${registrations} inscrit${registrations === 1 ? '' : 's'}`}><Users size={15} aria-hidden="true" />{registrations}</span></span>
      </span>
    </button>
  )
}

function CourseEditor({ course, courses, geocodingAvailable, mapEnabled, period, onClose, onSubmit }) {
  const defaultDate = localDateKey(new Date())
  const [form, setForm] = useState({
    name: course?.name || '', eventDate: course?.eventDate || defaultDate,
    location: course?.location || '', latitude: course?.latitude ?? null,
    longitude: course?.longitude ?? null, eventUrl: course?.eventUrl || '',
    formats: course?.formats.map(({ id, label }) => ({ id, label })) || [{ id: 0, label: '' }],
  })
  const [pending, setPending] = useState(false)
  const [locationDirty, setLocationDirty] = useState(false)
  const [searchingLocation, setSearchingLocation] = useState(false)
  const [locationError, setLocationError] = useState('')
  const [locationResults, setLocationResults] = useState([])
  const [activeLocationIndex, setActiveLocationIndex] = useState(-1)
  const [imageFile, setImageFile] = useState(null)
  const [imagePreview, setImagePreview] = useState('')
  const [removeImage, setRemoveImage] = useState(false)
  const [imageError, setImageError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [duplicates, setDuplicates] = useState([])
  const [nameSuggestionsOpen, setNameSuggestionsOpen] = useState(false)
  const [remoteEditions, setRemoteEditions] = useState([])
  const [reusedImage, setReusedImage] = useState(null)
  const [prefilledFrom, setPrefilledFrom] = useState(null)
  const [activeNameSuggestionIndex, setActiveNameSuggestionIndex] = useState(-1)
  const set = (key, value) => setForm((current) => ({ ...current, [key]: value }))
  const setFormat = (index, label) => set('formats', form.formats.map((format, position) => position === index ? { ...format, label } : format))
  const title = course ? 'Modifier la course' : 'Nouvelle course'
  const hasCoordinates = Number.isFinite(form.latitude) && Number.isFinite(form.longitude)
  const selectedPoint = useMemo(() => hasCoordinates ? { latitude: form.latitude, longitude: form.longitude } : null, [form.latitude, form.longitude, hasCoordinates])
  const nameSuggestions = useMemo(() => {
    const local = courseNameSuggestions(courses, form.name, course?.id)
    return course ? local : mergeEditionSuggestions(remoteEditions, local)
  }, [course, courses, form.name, remoteEditions])

  useEffect(() => {
    const search = form.name.trim()
    if (course || !nameSuggestionsOpen || normalizeSearchText(search).length < 2) {
      setRemoteEditions([])
      return undefined
    }
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      try {
        const payload = await api(`courses/editions?search=${encodeURIComponent(search)}`, { signal: controller.signal })
        setRemoteEditions(payload.editions || [])
      } catch (caught) {
        if (caught.name !== 'AbortError') setRemoteEditions([])
      }
    }, 250)
    return () => { clearTimeout(timer); controller.abort() }
  }, [course, form.name, nameSuggestionsOpen])

  const selectNameSuggestion = (suggestion) => {
    setNameSuggestionsOpen(false)
    setActiveNameSuggestionIndex(-1)
    if (course) {
      set('name', suggestion.name)
      return
    }
    setForm((current) => ({
      ...current,
      name: suggestion.name,
      location: suggestion.location || current.location,
      latitude: suggestion.latitude ?? null,
      longitude: suggestion.longitude ?? null,
      eventUrl: suggestion.eventUrl || '',
      formats: suggestion.formats?.length ? suggestion.formats.map((format) => ({ id: 0, label: format.label })) : current.formats,
    }))
    setLocationDirty(false)
    setLocationResults([])
    setLocationError('')
    setImageFile(null)
    setRemoveImage(false)
    setImageError('')
    setReusedImage(suggestion.image || null)
    setPrefilledFrom(suggestion)
  }

  const handleNameKeyDown = (event) => {
    if (!nameSuggestionsOpen || !nameSuggestions.length) return
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveNameSuggestionIndex((current) => current < nameSuggestions.length - 1 ? current + 1 : 0)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveNameSuggestionIndex((current) => current > 0 ? current - 1 : nameSuggestions.length - 1)
    } else if (event.key === 'Enter' && activeNameSuggestionIndex >= 0) {
      event.preventDefault()
      selectNameSuggestion(nameSuggestions[activeNameSuggestionIndex])
    } else if (event.key === 'Escape') {
      setNameSuggestionsOpen(false)
      setActiveNameSuggestionIndex(-1)
    }
  }

  const selectLocation = (result) => {
    setForm((current) => ({ ...current, location: result.label, latitude: result.latitude, longitude: result.longitude }))
    setLocationResults([])
    setActiveLocationIndex(-1)
    setLocationDirty(false)
    setLocationError('')
  }

  const handleLocationKeyDown = (event) => {
    if (!locationResults.length) return
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveLocationIndex((current) => current < locationResults.length - 1 ? current + 1 : 0)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveLocationIndex((current) => current > 0 ? current - 1 : locationResults.length - 1)
    } else if (event.key === 'Enter' && activeLocationIndex >= 0) {
      event.preventDefault()
      selectLocation(locationResults[activeLocationIndex])
    } else if (event.key === 'Escape') {
      event.preventDefault()
      setLocationResults([])
      setActiveLocationIndex(-1)
    }
  }

  useEffect(() => {
    if (!imageFile) {
      setImagePreview('')
      return undefined
    }
    const preview = URL.createObjectURL(imageFile)
    setImagePreview(preview)
    return () => URL.revokeObjectURL(preview)
  }, [imageFile])

  const chooseImage = (file) => {
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      setImageError('Utilisez un logo au format JPG, PNG ou WebP.')
      return
    }
    if (file.size > 5 * 1024 * 1024) {
      setImageError('Le logo doit peser moins de 5 Mo.')
      return
    }
    setImageFile(file)
    setReusedImage(null)
    setRemoveImage(false)
    setImageError('')
  }

  const clearImage = () => {
    setImageFile(null)
    setReusedImage(null)
    setRemoveImage(Boolean(course?.image))
    setImageError('')
  }

  useEffect(() => {
    if (!geocodingAvailable || !locationDirty || form.location.trim().length < 3) {
      return undefined
    }
    const controller = new AbortController()
    const query = form.location.trim()
    const timer = setTimeout(async () => {
      setSearchingLocation(true)
      setLocationError('')
      setLocationResults([])
      setActiveLocationIndex(-1)
      try {
        const payload = await api(`locations?query=${encodeURIComponent(query)}`, { signal: controller.signal })
        const results = payload.locations || []
        setLocationResults(results)
        if (!results.length) {
          setLocationError('Aucune proposition trouvée. Le lieu saisi sera tout de même enregistré.')
        }
      } catch (caught) {
        if (caught.name !== 'AbortError') setLocationError('La recherche automatique est indisponible. Le lieu saisi sera tout de même enregistré.')
      } finally {
        if (!controller.signal.aborted) setSearchingLocation(false)
      }
    }, 350)
    return () => { clearTimeout(timer); controller.abort() }
  }, [form.location, geocodingAvailable, locationDirty])

  useEffect(() => {
    const name = form.name.trim()
    if (!name || !/^\d{4}-\d{2}-\d{2}$/.test(form.eventDate)) {
      setDuplicates([])
      return undefined
    }
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      try {
        const exclude = course?.id ? `&exclude=${course.id}` : ''
        const payload = await api(`courses/duplicates?name=${encodeURIComponent(name)}&eventDate=${form.eventDate}${exclude}`, { signal: controller.signal })
        setDuplicates(payload.duplicates || [])
      } catch (caught) {
        if (caught.name !== 'AbortError') setDuplicates([])
      }
    }, 350)
    return () => { clearTimeout(timer); controller.abort() }
  }, [course?.id, form.eventDate, form.name])

  return (
    <Modal title={title} onClose={onClose} wide locked={pending}>
      <form className="crc-form" onSubmit={async (event) => {
        event.preventDefault()
        if (pending) return
        setSubmitError('')
        setPending(true)
        const mutationError = await onSubmit({
          payload: {
            ...form,
            formats: form.formats.filter((format) => format.label.trim()).map((format) => course ? format : format.label),
            ...(!course && reusedImage && !imageFile ? { reuseImageId: reusedImage.id } : {}),
          },
          imageFile,
          removeImage,
        })
        if (mutationError) setSubmitError(mutationError)
        setPending(false)
      }}>
        <div className="crc-form__row crc-form__row--primary">
          <div className="crc-name-field"><Field label="Nom de la course"><input required maxLength="191" autoComplete="off" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="crc-course-name-results" aria-expanded={nameSuggestionsOpen && nameSuggestions.length > 0} aria-activedescendant={activeNameSuggestionIndex >= 0 ? `crc-course-name-option-${activeNameSuggestionIndex}` : undefined} value={form.name} onFocus={() => setNameSuggestionsOpen(true)} onKeyDown={handleNameKeyDown} onChange={(event) => { set('name', event.target.value); setNameSuggestionsOpen(true); setActiveNameSuggestionIndex(-1) }} /></Field>{nameSuggestionsOpen && nameSuggestions.length > 0 && <ul id="crc-course-name-results" className="crc-name-results" role="listbox" aria-label="Suggestions de courses existantes">{nameSuggestions.map((suggestion, index) => <li role="none" key={suggestion.id}><button id={`crc-course-name-option-${index}`} type="button" role="option" aria-selected={activeNameSuggestionIndex === index} tabIndex={-1} onMouseDown={(event) => event.preventDefault()} onMouseEnter={() => setActiveNameSuggestionIndex(index)} onClick={() => selectNameSuggestion(suggestion)}><strong>{suggestion.name}</strong><span>{editionDate(suggestion.eventDate)} · {suggestion.location}</span></button></li>)}</ul>}{prefilledFrom ? <p className="crc-name-help crc-name-help--prefilled" role="status">Lieu, site, formats et logo repris de l’édition du {editionDate(prefilledFrom.eventDate)}. Pensez à changer la date.</p> : <p className="crc-name-help">{course ? 'Commencez à saisir : une suggestion reprend le nom complet d’une course existante.' : 'Commencez à saisir : choisir une course existante reprend son lieu, ses formats et son logo.'}</p>}</div>
          <Field label="Date"><input required type="date" min={period?.from} max={period?.to} value={form.eventDate} onChange={(event) => set('eventDate', event.target.value)} /></Field>
        </div>
        {duplicates.length > 0 && <div className="crc-duplicate-warning" role="status"><AlertCircle /><span><strong>Cette course semble déjà présente.</strong> {duplicates.map((duplicate) => `${duplicate.name}, le ${shortDate(duplicate.eventDate)} à ${duplicate.location}`).join(' ; ')}</span></div>}
        <div className="crc-form__row crc-form__row--location"><div className="crc-location-field"><Field label="Lieu de départ"><input required maxLength="191" autoComplete="off" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-describedby={hasCoordinates ? 'crc-location-status' : 'crc-location-help crc-location-status'} aria-controls="crc-location-results" aria-expanded={locationResults.length > 0} aria-activedescendant={activeLocationIndex >= 0 ? `crc-location-option-${activeLocationIndex}` : undefined} placeholder="Ville, lieu ou adresse" value={form.location} onKeyDown={handleLocationKeyDown} onChange={(event) => { setForm((current) => ({ ...current, location: event.target.value, latitude: null, longitude: null })); setLocationDirty(true); setLocationResults([]); setActiveLocationIndex(-1); setLocationError('') }} /></Field><span id="crc-location-status" className="crc-location-progress" role="status" aria-live="polite">{searchingLocation ? 'Recherche…' : locationResults.length ? `${locationResults.length} proposition${locationResults.length === 1 ? '' : 's'}` : ''}</span>{locationResults.length > 0 && <ul id="crc-location-results" className="crc-location-results" role="listbox" aria-label="Propositions de lieux">{locationResults.map((result, index) => <li role="none" key={`${result.latitude}-${result.longitude}`}><button id={`crc-location-option-${index}`} type="button" role="option" aria-selected={activeLocationIndex === index} tabIndex={-1} onMouseDown={(event) => event.preventDefault()} onMouseEnter={() => setActiveLocationIndex(index)} onClick={() => selectLocation(result)}>{result.label}</button></li>)}</ul>}{!hasCoordinates && <div id="crc-location-help" className="crc-location-help">{geocodingAvailable ? 'Saisissez au moins 3 caractères pour rechercher un lieu. Utilisez les flèches pour parcourir les propositions.' : 'Saisissez la ville, le lieu ou l’adresse de départ.'}</div>}{locationError && <p className="crc-location-note" role="status">{locationError}</p>}</div><Field label="Site officiel (optionnel)"><input type="url" placeholder="https://…" value={form.eventUrl} onChange={(event) => set('eventUrl', event.target.value)} /></Field></div>
        {mapEnabled && selectedPoint && <MapCanvas selectedPoint={selectedPoint} label={`Aperçu du départ à ${form.location}`} />}
        <CourseImageField
          currentImage={course?.image || reusedImage}
          preview={imagePreview}
          removed={removeImage}
          error={imageError}
          onChoose={chooseImage}
          onRemove={clearImage}
        />
        <fieldset className="crc-formats"><legend>Formats de course</legend><p className="crc-formats__help">Un champ par distance ou parcours : 50 km, 100 km, OCC…</p>{form.formats.map((format, index) => (
          <div key={format.id || `new-${index}`}><input aria-label={`Format ${index + 1}`} required maxLength="100" value={format.label} onChange={(event) => setFormat(index, event.target.value)} placeholder="Ex. 50 km" />{form.formats.length > 1 && <button type="button" aria-label={`Supprimer le format ${index + 1}`} onClick={() => set('formats', form.formats.filter((_, position) => position !== index))}><X /></button>}</div>
        ))}{form.formats.length < 20 && <button type="button" className="crc-text-button" onClick={() => set('formats', [...form.formats, { id: 0, label: '' }])}><Plus size={18} />Ajouter un format</button>}</fieldset>
        {submitError && <p className="crc-field-error" role="alert">{submitError}</p>}
        <div className="crc-actions"><button type="button" disabled={pending} className="crc-button crc-button--ghost" onClick={onClose}>Annuler</button><button disabled={pending} className="crc-button crc-button--primary">{pending ? 'Enregistrement…' : 'Enregistrer'}</button></div>
      </form>
    </Modal>
  )
}

function CourseDetail({ course, currentUser, canManage, onClose, onEdit, mutate, onDeleted }) {
  const [pending, setPending] = useState(false)
  const [comment, setComment] = useState('')
  const [members, setMembers] = useState([])
  const [memberQuery, setMemberQuery] = useState('')
  const [memberCursor, setMemberCursor] = useState(null)
  const [memberLoading, setMemberLoading] = useState(false)
  const [memberError, setMemberError] = useState('')
  const [mutationError, setMutationError] = useState('')
  const [targetUser, setTargetUser] = useState('')
  const [targetFormat, setTargetFormat] = useState(course.formats[0]?.id || '')
  const [openFormats, setOpenFormats] = useState(() => new Set(course.formats.filter((format) => format.registrations.length <= 8).map((format) => format.id)))
  const ownFormats = course.formats.filter((format) => format.registrations.some((registration) => registration.userId === currentUser.id))
  const ownRegistration = ownFormats.length > 0
  const ownCarpool = (course.carpools || []).find((item) => item.userId === currentUser.id)
  const ownResult = (course.results || []).find((item) => item.userId === currentUser.id)
  const [carpool, setCarpool] = useState({ type: ownCarpool?.type || 'driver', seats: ownCarpool?.seats || 1, departure: ownCarpool?.departure || '', note: ownCarpool?.note || '' })
  const [result, setResult] = useState({ formatId: ownResult?.formatId || ownFormats[0]?.id || '', time: ownResult?.time || '', ranking: ownResult?.ranking || '', note: ownResult?.note || '' })

  useEffect(() => {
    setCarpool({ type: ownCarpool?.type || 'driver', seats: ownCarpool?.seats || 1, departure: ownCarpool?.departure || '', note: ownCarpool?.note || '' })
  }, [ownCarpool?.id, ownCarpool?.updatedAt])

  useEffect(() => {
    setResult({ formatId: ownResult?.formatId || ownFormats[0]?.id || '', time: ownResult?.time || '', ranking: ownResult?.ranking || '', note: ownResult?.note || '' })
  }, [ownResult?.id, ownResult?.updatedAt, ownFormats[0]?.id])

  useEffect(() => {
    if (memberQuery.trim().length < 2) {
      setMembers([])
      setMemberCursor(null)
      setMemberError('')
      return undefined
    }
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      setMemberLoading(true)
      setMemberError('')
      try {
        const payload = await api(`members?search=${encodeURIComponent(memberQuery.trim())}`, { signal: controller.signal })
        setMembers(payload.members || [])
        setMemberCursor(payload.nextCursor ?? null)
      } catch (caught) {
        if (caught.name !== 'AbortError') setMemberError(caught.message)
      } finally {
        if (!controller.signal.aborted) setMemberLoading(false)
      }
    }, 250)
    return () => { clearTimeout(timer); controller.abort() }
  }, [memberQuery])

  const run = async (callback, message) => {
    if (pending) return false
    setPending(true)
    const ok = await mutate(callback, message, setMutationError)
    setPending(false)
    return ok
  }

  const confirmAndRun = async (question, callback, message) => {
    if (!window.confirm(question)) return false
    return run(callback, message)
  }

  return (
    <Modal title={course.name} onClose={onClose} wide locked={pending}>
      {course.image && <figure className="crc-course-image"><img src={course.image.url} srcSet={course.image.srcSet || undefined} sizes="(max-width: 820px) calc(100vw - 32px), 732px" width={course.image.width} height={course.image.height} alt={course.image.alt} decoding="async" /></figure>}
      <div className="crc-course-meta"><span><MapPin />{course.location}</span><time dateTime={course.eventDate}>{formatDate(course.eventDate)}</time>{course.eventUrl && <a href={course.eventUrl} target="_blank" rel="noreferrer">Site officiel<ExternalLink /></a>}</div>
      {(course.permissions.edit || course.permissions.delete) && <div className="crc-toolbar">{course.permissions.edit && <button onClick={onEdit}><Pencil />Modifier</button>}{course.permissions.delete && <button className="is-danger" onClick={async () => { if (window.confirm(`Placer la course « ${course.name} » dans les éléments supprimés ? Vous pourrez la restaurer.`) && await run(() => api(`courses/${course.id}`, { method: 'DELETE' }), 'Course supprimée.')) onDeleted() }}><Trash2 />Supprimer</button>}</div>}

      {mutationError && <p className="crc-field-error" role="alert">{mutationError}</p>}

      <div className="crc-format-list">{course.formats.map((format) => {
        const mine = format.registrations.find((registration) => registration.userId === currentUser.id)
        const open = openFormats.has(format.id)
        return (
          <section className={`crc-format ${open ? 'is-open' : ''}`} key={format.id}>
            <button type="button" className="crc-format__toggle" aria-expanded={open} onClick={() => setOpenFormats((current) => { const next = new Set(current); if (next.has(format.id)) next.delete(format.id); else next.add(format.id); return next })}>
              <span><strong>{format.label}</strong><small>{format.registrations.length} inscrit{format.registrations.length === 1 ? '' : 's'}</small></span>
              {open ? <ChevronDown aria-hidden="true" /> : <ChevronRight aria-hidden="true" />}
            </button>
            {open && <div className="crc-format__content">
              {format.registrations.length ? <ul>{format.registrations.map((registration) => <li key={registration.id}><span>{registration.displayName}</span>{(canManage || registration.userId === currentUser.id) && <button disabled={pending} aria-label={`Désinscrire ${registration.displayName}`} onClick={() => confirmAndRun(`Désinscrire ${registration.displayName} du format ${format.label} ?`, () => api(`registrations/${registration.id}`, { method: 'DELETE' }), 'Inscription supprimée.')}><X /></button>}</li>)}</ul> : <p>Aucun inscrit pour l’instant.</p>}
              {!mine && course.permissions.register && <button disabled={pending} className="crc-button crc-button--secondary" onClick={() => run(() => api(`courses/${course.id}/registrations`, { method: 'POST', body: JSON.stringify({ formatId: format.id }) }), 'Inscription confirmée.')}>S’inscrire sur {format.label}</button>}
            </div>}
          </section>
        )
      })}</div>

      <section className="crc-community-section crc-carpool">
        <div className="crc-section-heading"><div className="crc-section-icon"><Car /></div><div><span className="crc-eyebrow">Organisation</span><h3>Covoiturage</h3><p>Proposez vos places ou signalez que vous cherchez une voiture.</p></div></div>
        {(course.carpools || []).length ? <div className="crc-carpool-list">{(course.carpools || []).map((item) => <article key={item.id}><span className={`crc-carpool-type is-${item.type}`}>{item.type === 'driver' ? 'Conducteur' : 'Cherche une place'}</span><strong>{item.displayName}</strong>{item.departure && <span><MapPin />Départ : {item.departure}</span>}{item.type === 'driver' && <span><Users />{item.seats} place{item.seats === 1 ? '' : 's'} disponible{item.seats === 1 ? '' : 's'}</span>}{item.note && <p>{item.note}</p>}</article>)}</div> : <p className="crc-empty-inline">Aucune proposition pour l’instant.</p>}
        {course.permissions.carpool && <form className="crc-contribution-form" onSubmit={async (event) => { event.preventDefault(); await run(() => api(`courses/${course.id}/carpool`, { method: 'PUT', body: JSON.stringify(carpool) }), ownCarpool ? 'Covoiturage modifié.' : 'Covoiturage ajouté.') }}>
          <fieldset className="crc-choice"><legend className="crc-sr-only">Type de covoiturage</legend><label><input type="radio" name={`crc-carpool-type-${course.id}`} value="driver" checked={carpool.type === 'driver'} disabled={pending} onChange={() => setCarpool((current) => ({ ...current, type: 'driver' }))} /><span>Je conduis</span></label><label><input type="radio" name={`crc-carpool-type-${course.id}`} value="passenger" checked={carpool.type === 'passenger'} disabled={pending} onChange={() => setCarpool((current) => ({ ...current, type: 'passenger' }))} /><span>Je cherche une place</span></label></fieldset>
          <div className="crc-contribution-grid"><Field label="Départ ou zone"><input maxLength="191" value={carpool.departure} onChange={(event) => setCarpool((current) => ({ ...current, departure: event.target.value }))} placeholder="Ex. Lyon Part-Dieu" /></Field>{carpool.type === 'driver' && <Field label="Places disponibles"><input type="number" min="1" max="8" required value={carpool.seats} onChange={(event) => setCarpool((current) => ({ ...current, seats: Number(event.target.value) }))} /></Field>}</div>
          <Field label="Précision (optionnel)"><textarea rows="2" maxLength="500" value={carpool.note} onChange={(event) => setCarpool((current) => ({ ...current, note: event.target.value }))} placeholder="Horaires, détour possible, contact…" /></Field>
          <div className="crc-contribution-actions">{ownCarpool && <button type="button" disabled={pending} className="crc-text-button is-danger" onClick={() => confirmAndRun('Supprimer votre proposition de covoiturage ?', () => api(`courses/${course.id}/carpool`, { method: 'DELETE' }), 'Covoiturage supprimé.')}>Supprimer</button>}<button disabled={pending} className="crc-button crc-button--secondary">{ownCarpool ? 'Mettre à jour' : 'Publier'}</button></div>
        </form>}
      </section>

      <section className="crc-community-section crc-results">
        <div className="crc-section-heading"><div className="crc-section-icon"><Medal /></div><div><span className="crc-eyebrow">Après la course</span><h3>Résultats</h3><p>Centralisez les performances de la communauté pour le récapitulatif hebdomadaire.</p></div></div>
        {(course.results || []).length ? <div className="crc-result-list">{(course.results || []).map((item) => <article key={item.id}><div><strong>{item.displayName}</strong><span>{item.formatLabel}</span></div><p>{resultSummary(item)}</p>{item.note && <small>{item.note}</small>}</article>)}</div> : <p className="crc-empty-inline">Aucun résultat renseigné.</p>}
        {ownRegistration && <form className="crc-contribution-form" onSubmit={async (event) => { event.preventDefault(); await run(() => api(`courses/${course.id}/result`, { method: 'PUT', body: JSON.stringify(result) }), ownResult ? 'Résultat modifié.' : 'Résultat ajouté.') }}>
          <div className="crc-result-fields"><Field label="Format"><select required value={result.formatId} onChange={(event) => setResult((current) => ({ ...current, formatId: Number(event.target.value) }))}>{ownFormats.map((format) => <option key={format.id} value={format.id}>{format.label}</option>)}</select></Field><Field label="Temps"><input maxLength="64" value={result.time} onChange={(event) => setResult((current) => ({ ...current, time: event.target.value }))} placeholder="Ex. 10 h 42" /></Field><Field label="Classement par genre"><input maxLength="100" value={result.ranking} onChange={(event) => setResult((current) => ({ ...current, ranking: event.target.value }))} placeholder="Ex. 24e femme" /></Field></div>
          <Field label="Mot de la course (optionnel)"><textarea rows="2" maxLength="500" value={result.note} onChange={(event) => setResult((current) => ({ ...current, note: event.target.value }))} placeholder="Une anecdote, une perf, un souvenir…" /></Field>
          <div className="crc-contribution-actions">{ownResult && <button type="button" disabled={pending} className="crc-text-button is-danger" onClick={() => confirmAndRun('Supprimer votre résultat pour cette course ?', () => api(`courses/${course.id}/result`, { method: 'DELETE' }), 'Résultat supprimé.')}>Supprimer</button>}<button disabled={pending || (!result.time.trim() && !result.ranking.trim() && !result.note.trim())} className="crc-button crc-button--secondary">{ownResult ? 'Mettre à jour' : 'Publier mon résultat'}</button></div>
        </form>}
      </section>

      <section className="crc-admin-register"><h3><UserPlus />Ajouter une personne</h3><label className="crc-field"><span>Rechercher un membre actif</span><input type="search" minLength="2" maxLength="100" value={memberQuery} onChange={(event) => { setMemberQuery(event.target.value); setTargetUser('') }} placeholder="Au moins 2 caractères" /></label><p className="crc-sr-only" role="status" aria-live="polite">{memberLoading ? 'Recherche des membres en cours.' : `${members.length} membre${members.length === 1 ? '' : 's'} actif${members.length === 1 ? '' : 's'} trouvé${members.length === 1 ? '' : 's'}.`}</p>{memberError && <p className="crc-field-error" role="alert">{memberError}</p>}{memberQuery.trim().length < 2 && <p className="crc-empty-inline">Saisissez au moins 2 caractères pour rechercher une personne.</p>}{memberQuery.trim().length >= 2 && !memberLoading && !memberError && members.length === 0 && <p className="crc-empty-inline">Aucun membre actif trouvé.</p>}{members.length > 0 && <div><select aria-label="Membre actif" value={targetUser} onChange={(event) => setTargetUser(event.target.value)}><option value="">Choisir un membre…</option>{members.map((member) => <option key={member.id} value={member.id}>{member.label || member.displayName}</option>)}</select><select aria-label="Format" value={targetFormat} onChange={(event) => setTargetFormat(event.target.value)}>{course.formats.map((format) => <option key={format.id} value={format.id}>{format.label}</option>)}</select><button disabled={pending || !targetUser} className="crc-button crc-button--secondary" onClick={() => run(() => api(`courses/${course.id}/registrations`, { method: 'POST', body: JSON.stringify({ formatId: Number(targetFormat), userId: Number(targetUser) }) }), 'Membre ajouté à la course.')}>Ajouter</button></div>}{memberCursor !== null && <button type="button" disabled={memberLoading} className="crc-text-button" onClick={async () => { setMemberLoading(true); setMemberError(''); try { const payload = await api(`members?search=${encodeURIComponent(memberQuery.trim())}&cursor=${memberCursor}`); setMembers((current) => [...current, ...(payload.members || [])]); setMemberCursor(payload.nextCursor ?? null) } catch (caught) { setMemberError(caught.message) } finally { setMemberLoading(false) } }}>Charger plus de membres</button>}</section>

      <section className="crc-comments"><h3><MessageSquare />Commentaires</h3>{course.comments.length ? <div className="crc-comments__list">{course.comments.map((item) => <article key={item.id}><div><strong>{item.displayName}</strong><time>{new Date(item.createdAt).toLocaleDateString('fr-FR')}</time>{(canManage || item.userId === currentUser.id) && <button disabled={pending} aria-label={`Supprimer le commentaire de ${item.displayName}`} onClick={() => confirmAndRun(`Supprimer le commentaire de ${item.displayName} ?`, () => api(`comments/${item.id}`, { method: 'DELETE' }), 'Commentaire supprimé.')}><Trash2 /></button>}</div><p>{item.body}</p></article>)}</div> : <p className="crc-empty-inline">Aucun commentaire pour l’instant.</p>}
        {course.permissions.comment && <form className="crc-comment-form" onSubmit={async (event) => { event.preventDefault(); if (!comment.trim()) return; const ok = await run(() => api(`courses/${course.id}/comments`, { method: 'POST', body: JSON.stringify({ body: comment.trim() }) }), 'Commentaire ajouté.'); if (ok) setComment('') }}><label className="crc-sr-only" htmlFor={`crc-comment-${course.id}`}>Ajouter un commentaire</label><textarea id={`crc-comment-${course.id}`} rows="2" maxLength="1000" value={comment} onChange={(event) => setComment(event.target.value)} placeholder="Ajouter un commentaire…" /><button disabled={pending || !comment.trim()} className="crc-button crc-button--primary">Envoyer</button></form>}
      </section>
    </Modal>
  )
}

function CourseImageField({ currentImage, preview, removed, error, onChoose, onRemove }) {
  const imageUrl = preview || (!removed ? currentImage?.url : '')
  const input = (
    <input
      className="crc-file-input"
      type="file"
      aria-label={imageUrl ? 'Remplacer le logo' : 'Ajouter un logo'}
      accept="image/jpeg,image/png,image/webp"
      onChange={(event) => { onChoose(event.target.files?.[0]); event.target.value = '' }}
    />
  )

  return (
    <div className={`crc-image-field ${imageUrl ? 'has-image' : ''}`}>
      <div className="crc-image-field__summary">
        {imageUrl ? <figure className="crc-image-preview"><img src={imageUrl} alt="Aperçu du logo de la course" /></figure> : <span className="crc-image-field__icon"><ImagePlus aria-hidden="true" /></span>}
        <div className="crc-image-field__heading"><strong>Logo de la course</strong><span>Facultatif · JPG, PNG ou WebP · 5 Mo maximum</span></div>
      </div>
      <div className="crc-image-actions">
        <label className="crc-button crc-button--ghost"><ImagePlus />{imageUrl ? 'Remplacer' : 'Choisir un fichier'}{input}</label>
        {imageUrl && <button type="button" className="crc-button crc-button--ghost is-danger" onClick={onRemove}><Trash2 />Supprimer</button>}
      </div>
      {error && <p className="crc-field-error" role="alert">{error}</p>}
    </div>
  )
}

// Theme variables of the running calendar, shared with dialogs rendered in a portal outside the shell.
let themeStyle = {}

function Modal({ title, children, onClose, wide = false, locked = false }) {
  const closeRef = useRef(null)
  const dialogRef = useRef(null)
  const onCloseRef = useRef(onClose)
  const lockedRef = useRef(locked)
  useEffect(() => { onCloseRef.current = onClose }, [onClose])
  useEffect(() => { lockedRef.current = locked }, [locked])
  useEffect(() => {
    const previouslyFocused = document.activeElement
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const handler = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        if (!lockedRef.current) onCloseRef.current()
        return
      }
      if (event.key !== 'Tab' || !dialogRef.current) return
      const focusable = [...dialogRef.current.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])')]
      if (!focusable.length) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
    }
    document.addEventListener('keydown', handler)
    const shell = previouslyFocused?.closest?.('.crc-shell')
    if (shell) shell.inert = true
    closeRef.current?.focus()
    return () => {
      document.removeEventListener('keydown', handler)
      document.body.style.overflow = previousOverflow
      if (shell) shell.inert = false
      previouslyFocused?.focus?.()
    }
  }, [])
  return createPortal(<div className="crc-app crc-modal" style={themeStyle} role="presentation" onMouseDown={(event) => { if (!locked && event.target === event.currentTarget) onClose() }}><div ref={dialogRef} className={`crc-dialog ${wide ? 'crc-dialog--wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby="crc-dialog-title" aria-busy={locked || undefined} tabIndex="-1"><div className="crc-dialog__header"><h2 id="crc-dialog-title">{title}</h2><button className="crc-dialog__close" type="button" ref={closeRef} disabled={locked} aria-label="Fermer" onClick={onClose}><X /></button></div><div className="crc-dialog__body">{children}</div></div></div>, document.body)
}

function Field({ label, children }) { return <label className="crc-field"><span>{label}</span>{children}</label> }

function State({ icon: Icon, spinning, title, body, action }) {
  return <div className="crc-state"><Icon className={spinning ? 'is-spinning' : ''} /><h2>{title}</h2>{body && <p>{body}</p>}{action}</div>
}

function SkeletonState() {
  return <div className="crc-skeleton" role="status" aria-label="Chargement du calendrier"><div className="crc-skeleton__header" /><div className="crc-skeleton__body"><div className="crc-skeleton__tabs" /><div className="crc-skeleton__search" /><div className="crc-skeleton__row" /><div className="crc-skeleton__row" /><div className="crc-skeleton__row" /></div><span className="crc-sr-only">Chargement du calendrier…</span></div>
}

function EmptyState() { return <div className="crc-empty"><Search /><h3>Aucune course trouvée</h3><p>Vérifiez l’orthographe ou essayez avec une ville voisine.</p></div> }
