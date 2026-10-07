import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import MapCanvas from './MapCanvas.jsx'

const { easeTo, initialViews, markerOptions } = vi.hoisted(() => ({ easeTo: vi.fn(), initialViews: [], markerOptions: [] }))

vi.mock('maplibre-gl', () => {
  class Map {
    constructor({ container, center, zoom }) {
      this.container = container
      initialViews.push({ center, zoom })
    }

    on(event, callback) {
      if (event === 'load') callback()
    }

    off() {}
    remove() {}
    fitBounds() {}
    easeTo(options) { easeTo(options) }
    getZoom() { return 8 }
  }

  class Marker {
    constructor({ element, ...options }) {
      this.element = element
      markerOptions.push(options)
    }

    setLngLat() { return this }

    addTo(map) {
      map.container.append(this.element)
      return this
    }

    remove() {
      this.element.remove()
    }
  }

  class LngLatBounds {
    extend() { return this }
  }

  return { Map, Marker, LngLatBounds, setWorkerUrl: vi.fn() }
})

beforeEach(() => {
  easeTo.mockClear()
  initialViews.length = 0
  markerOptions.length = 0
  window.matchMedia = vi.fn(() => ({ matches: false }))
})

it('ouvre la carte centrée sur la France', async () => {
  render(<MapCanvas points={[{ id: 7, name: 'UTMB Mont-Blanc', location: 'Chamonix', latitude: 45.9237, longitude: 6.8694 }]} onSelect={() => {}} />)

  await screen.findByRole('button', { name: 'Afficher les informations de UTMB Mont-Blanc' })
  expect(initialViews).toEqual([{ center: [2.4, 46.6], zoom: 5 }])
})

it('affiche le nom de la course sur son pin', async () => {
  const onSelect = vi.fn()
  render(<MapCanvas
    points={[{ id: 7, name: 'UTMB Mont-Blanc', location: 'Chamonix', latitude: 45.9237, longitude: 6.8694, image: { cardUrl: '/utmb-logo.webp' } }]}
    onSelect={onSelect}
    label="Carte des courses"
  />)

  expect(screen.getByRole('status')).toHaveTextContent('Chargement de la carte')

  const marker = await screen.findByRole('button', { name: 'Afficher les informations de UTMB Mont-Blanc' })
  expect(marker).toHaveTextContent('UTMB Mont-Blanc')
  expect(marker.querySelector('.crc-map-marker__label')).not.toBeNull()
  expect(marker.querySelector('.crc-map-marker__image')).toHaveAttribute('src', '/utmb-logo.webp')

  fireEvent.click(marker)
  expect(onSelect).toHaveBeenCalledWith(7)
})

it('conserve le marqueur et son focus quand il devient actif', async () => {
  const points = [{ id: 7, name: 'UTMB Mont-Blanc', location: 'Chamonix', latitude: 45.9237, longitude: 6.8694 }]
  const onSelect = vi.fn()
  const { rerender } = render(<MapCanvas points={points} activePointId={null} onSelect={onSelect} label="Carte des courses" />)
  const marker = await screen.findByRole('button', { name: 'Afficher les informations de UTMB Mont-Blanc' })
  marker.focus()

  rerender(<MapCanvas points={points} activePointId={7} onSelect={onSelect} label="Carte des courses" />)

  await waitFor(() => expect(marker).toHaveAttribute('aria-pressed', 'true'))
  expect(screen.getByRole('button', { name: 'Afficher les informations de UTMB Mont-Blanc' })).toBe(marker)
  expect(marker).toHaveClass('is-active')
  expect(document.activeElement).toBe(marker)
})

it('décale les marqueurs de courses au même endroit pour les rendre sélectionnables', async () => {
  const points = [
    { id: 7, name: 'Trail A', location: 'Chamonix', latitude: 45.9237, longitude: 6.8694 },
    { id: 8, name: 'Trail B', location: 'Chamonix', latitude: 45.9237, longitude: 6.8694 },
  ]
  render(<MapCanvas points={points} onSelect={() => {}} />)

  await screen.findByRole('button', { name: 'Afficher les informations de Trail B' })
  expect(markerOptions.map(({ offset }) => offset)).toEqual(expect.arrayContaining([[27, 0], [-27, 0]]))
})

it('affiche un point sélectionné en lecture seule', async () => {
  window.matchMedia = vi.fn(() => ({ matches: true }))
  render(<MapCanvas
    selectedPoint={{ latitude: 45.9237, longitude: 6.8694 }}
    label="Aperçu du point de départ"
  />)

  expect(await screen.findByRole('region', { name: 'Aperçu du point de départ' })).toHaveClass('is-preview')
  expect(screen.queryByRole('button', { name: 'Déplacer le point de départ' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Zoomer' })).not.toBeInTheDocument()
  await waitFor(() => expect(document.querySelector('.crc-map-marker--selected')).toHaveAttribute('aria-hidden', 'true'))
  expect(easeTo).toHaveBeenCalledWith(expect.objectContaining({ duration: 0 }))
})
