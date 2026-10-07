import App from './App.jsx'
import { createRoot } from 'react-dom/client'
import './styles.css'

export function mount() {
  document.querySelectorAll('[data-community-race-calendar]').forEach((element) => {
    if (element.dataset.crcMounted) return
    element.dataset.crcMounted = 'true'
    window.CommunityRaceCalendarConfig = {
      restRoot: element.dataset.restRoot,
      nonce: element.dataset.nonce,
    }
    createRoot(element).render(<App />)
  })
}
