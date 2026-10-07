// In the production build React comes from WordPress (wp-element). The globals are checked first
// because a script optimizer may defer WordPress scripts until after this module has been evaluated.
const wordpressReactReady = () => Boolean(window.React && window.ReactDOM && window.ReactJSXRuntime)
const start = () => import('./mount.jsx').then(({ mount }) => mount())

if (!import.meta.env.PROD || wordpressReactReady()) {
  start()
} else {
  window.addEventListener('load', () => { if (wordpressReactReady()) start() }, { once: true })
}
