import { useEffect, useRef, useState } from 'react'
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url'

const DEFAULT_STYLE = 'https://tiles.openfreemap.org/styles/liberty'
const MAPLIBRE_WORKER_URL = new URL(maplibreWorkerUrl, import.meta.url).href

function motionDuration(duration = 250) {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 0 : duration
}

function overlappingMarkerOffset(index, count) {
  if (count < 2) return [0, 0]
  const angle = (Math.PI * 2 * index) / count
  const radius = 27
  return [Math.round(Math.cos(angle) * radius), Math.round(Math.sin(angle) * radius)]
}

export default function MapCanvas({ points = [], activePointId = null, selectedPoint = null, onPick, onSelect, label }) {
  const containerRef = useRef(null)
  const mapRef = useRef(null)
  const libraryRef = useRef(null)
  const courseMarkersRef = useRef([])
  const selectedMarkerRef = useRef(null)
  const [ready, setReady] = useState(false)
  const [error, setError] = useState('')
  const readOnlyPreview = Boolean(selectedPoint && !onPick && points.length === 0)

  useEffect(() => {
    let disposed = false
    import('maplibre-gl').then((maplibregl) => {
      if (disposed || !containerRef.current) return
      maplibregl.setWorkerUrl(MAPLIBRE_WORKER_URL)
      libraryRef.current = maplibregl
      const map = new maplibregl.Map({
        container: containerRef.current,
        style: DEFAULT_STYLE,
        center: [2.4, 46.6],
        zoom: 5,
        attributionControl: false,
        interactive: !readOnlyPreview,
      })
      map.on('load', () => { if (!disposed) setReady(true) })
      map.on('error', (event) => {
        if (!disposed && !event?.error?.message?.includes('AJAXError')) setError('La carte ne peut pas être affichée.')
      })
      mapRef.current = map
    }).catch(() => { if (!disposed) setError('La carte ne peut pas être chargée.') })

    return () => {
      disposed = true
      selectedMarkerRef.current?.remove()
      mapRef.current?.remove()
      mapRef.current = null
      libraryRef.current = null
    }
  }, [])

  useEffect(() => {
    if (!ready || !mapRef.current || !libraryRef.current) return
    const located = points.filter((point) => Number.isFinite(point.latitude) && Number.isFinite(point.longitude))
    const pointsByLocation = new Map()
    located.forEach((point) => {
      const key = `${point.latitude.toFixed(6)}:${point.longitude.toFixed(6)}`
      const group = pointsByLocation.get(key) || []
      group.push(point)
      pointsByLocation.set(key, group)
    })
    const markers = []

    located.forEach((point) => {
      const sameLocation = pointsByLocation.get(`${point.latitude.toFixed(6)}:${point.longitude.toFixed(6)}`) || []
      const positionInLocation = sameLocation.findIndex((candidate) => candidate.id === point.id)
      const element = document.createElement('button')
      const imageUrl = point.image?.cardUrl || point.image?.url
      element.type = 'button'
      element.className = `crc-map-marker ${imageUrl ? 'has-image' : ''}`
      element.setAttribute('aria-label', `Afficher les informations de ${point.name}`)
      element.setAttribute('aria-pressed', 'false')
      element.title = `${point.name} · ${point.location}`
      if (imageUrl) {
        const image = document.createElement('img')
        image.className = 'crc-map-marker__image'
        image.src = imageUrl
        image.alt = ''
        image.decoding = 'async'
        image.addEventListener('error', () => {
          image.remove()
          element.classList.remove('has-image')
        })
        element.append(image)
      }
      const name = document.createElement('span')
      name.className = 'crc-map-marker__label'
      name.textContent = point.name
      element.append(name)
      element.addEventListener('click', (event) => {
        event.stopPropagation()
        onSelect?.(point.id)
      })
      const marker = new libraryRef.current.Marker({ element, anchor: 'center', offset: overlappingMarkerOffset(positionInLocation, sameLocation.length) })
        .setLngLat([point.longitude, point.latitude])
        .addTo(mapRef.current)
      markers.push({ id: point.id, element, marker })
    })
    courseMarkersRef.current = markers

    return () => {
      markers.forEach(({ marker }) => marker.remove())
      if (courseMarkersRef.current === markers) courseMarkersRef.current = []
    }
  }, [onSelect, points, ready])

  useEffect(() => {
    courseMarkersRef.current.forEach(({ id, element }) => {
      const active = id === activePointId
      element.classList.toggle('is-active', active)
      element.setAttribute('aria-pressed', active ? 'true' : 'false')
    })
  }, [activePointId, points, ready])

  useEffect(() => {
    if (!ready || !mapRef.current || activePointId === null || selectedPoint) return
    const activePoint = points.find((point) => point.id === activePointId)
    if (!activePoint || !Number.isFinite(activePoint.latitude) || !Number.isFinite(activePoint.longitude)) return
    mapRef.current.easeTo({
      center: [activePoint.longitude, activePoint.latitude],
      zoom: Math.max(mapRef.current.getZoom(), 10),
      duration: motionDuration(),
    })
  }, [activePointId, points, ready, selectedPoint])

  useEffect(() => {
    if (!ready || !mapRef.current || !libraryRef.current) return
    selectedMarkerRef.current?.remove()
    selectedMarkerRef.current = null
    if (!selectedPoint || !Number.isFinite(selectedPoint.latitude) || !Number.isFinite(selectedPoint.longitude)) return

    const element = document.createElement(onPick ? 'button' : 'div')
    if (onPick) element.type = 'button'
    element.className = 'crc-map-marker crc-map-marker--selected'
    if (onPick) {
      element.setAttribute('aria-label', 'Déplacer le point de départ')
      element.title = 'Déplacez ce point pour préciser le départ'
    } else {
      element.setAttribute('aria-hidden', 'true')
      element.title = 'Point de départ'
    }
    const marker = new libraryRef.current.Marker({ element, anchor: 'center', draggable: Boolean(onPick) })
      .setLngLat([selectedPoint.longitude, selectedPoint.latitude])
      .addTo(mapRef.current)
    if (onPick) {
      marker.on('dragend', () => {
        const position = marker.getLngLat()
        onPick({ latitude: position.lat, longitude: position.lng })
      })
    }
    selectedMarkerRef.current = marker
    mapRef.current.easeTo({ center: [selectedPoint.longitude, selectedPoint.latitude], zoom: Math.max(mapRef.current.getZoom(), 10), duration: motionDuration() })
  }, [onPick, ready, selectedPoint])

  useEffect(() => {
    if (!ready || !mapRef.current || !onPick) return
    const handler = (event) => onPick({ latitude: event.lngLat.lat, longitude: event.lngLat.lng })
    mapRef.current.on('click', handler)
    return () => mapRef.current?.off('click', handler)
  }, [onPick, ready])

  useEffect(() => {
    if (!ready || !mapRef.current || onPick || !onSelect) return
    const handler = () => onSelect(null)
    mapRef.current.on('click', handler)
    return () => mapRef.current?.off('click', handler)
  }, [onPick, onSelect, ready])

  return (
    <div className={`crc-map-canvas ${onPick ? 'is-pickable' : ''} ${readOnlyPreview ? 'is-preview' : ''}`} ref={containerRef} role="region" aria-label={label || 'Carte des courses'}>
      {!readOnlyPreview && <div className="crc-map-controls" onMouseDown={(event) => event.stopPropagation()}>
        <button type="button" aria-label="Zoomer" onClick={(event) => { event.stopPropagation(); mapRef.current?.zoomIn() }}>+</button>
        <button type="button" aria-label="Dézoomer" onClick={(event) => { event.stopPropagation(); mapRef.current?.zoomOut() }}>−</button>
      </div>}
      <a className="crc-map-attribution" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">© OpenStreetMap contributors</a>
      {!ready && !error && <div className="crc-map-status" role="status" aria-live="polite">Chargement de la carte…</div>}
      {error && <div className="crc-map-status is-error" role="alert">{error}</div>}
    </div>
  )
}
