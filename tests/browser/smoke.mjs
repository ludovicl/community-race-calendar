// Browser smoke test: logs in as a member, loads the calendar page served by WordPress (which provides
// React through wp-element) and checks that the application renders and reacts. Every request that is not
// addressed to the local WordPress is blocked and recorded, so no external service is ever contacted.
//
// Usage: CRCAL_PUPPETEER_PACKAGE=/path/to/node_modules/puppeteer-core/package.json \
//        CRCAL_BROWSER="/path/to/chromium-based-browser" node tests/browser/smoke.mjs [mapExpectation]
// mapExpectation: "enabled" or "disabled" (the state of the Map setting on the site under test).
import { createRequire } from 'node:module'

const baseUrl = process.env.CRCAL_BASE_URL || 'http://localhost:8087'
const packagePath = process.env.CRCAL_PUPPETEER_PACKAGE
const browserPath = process.env.CRCAL_BROWSER
const mapExpectation = process.argv[2] || 'enabled'
const designExpectation = process.argv[3] === 'design'
if (!packagePath || !browserPath) {
  console.error('Set CRCAL_PUPPETEER_PACKAGE and CRCAL_BROWSER.')
  process.exit(2)
}
const puppeteer = createRequire(packagePath)('puppeteer-core')

const failures = []
const check = (condition, message) => {
  if (!condition) failures.push(message)
  console.log(`${condition ? 'ok  ' : 'FAIL'} ${message}`)
}

const browser = await puppeteer.launch({
  executablePath: browserPath,
  headless: true,
  args: ['--no-sandbox', '--disable-gpu', '--use-gl=swiftshader', '--enable-unsafe-swiftshader'],
})
try {
  const page = await browser.newPage()
  const blocked = []
  const consoleErrors = []
  await page.setRequestInterception(true)
  page.on('request', (request) => {
    if (request.url().startsWith(baseUrl) || request.url().startsWith('data:') || request.url().startsWith('blob:')) {
      request.continue()
    } else {
      blocked.push(request.url())
      request.abort()
    }
  })
  page.on('pageerror', (error) => consoleErrors.push(`pageerror: ${error.message}`))
  page.on('console', (message) => {
    // Failures caused by the blocked external requests are expected and not application errors.
    if (message.type() === 'error' && !/ERR_FAILED|ERR_BLOCKED|Failed to load resource|AJAXError|Failed to fetch|WebGL|webgl/i.test(message.text())) {
      consoleErrors.push(message.text())
    }
  })

  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'networkidle0' })
  await page.type('#user_login', 'member_one')
  await page.type('#user_pass', 'local-member-one-pass')
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#wp-submit')])

  await page.goto(`${baseUrl}/calendrier/`, { waitUntil: 'networkidle0' })
  await page.waitForSelector('.crc-shell', { timeout: 15000 })
  check(true, 'the application shell is rendered')
  check(await page.$('.crc-app__loading') === null, 'the loading placeholder is gone')
  const reactVersion = await page.evaluate(() => window.React?.version)
  check(typeof reactVersion === 'string', `React comes from WordPress (version ${reactVersion})`)
  const appScripts = await page.$$eval('script[src]', (nodes) => nodes.map((node) => node.src))
  check(appScripts.some((src) => src.includes('/wp-includes/js/dist/vendor/react.')) && !appScripts.some((src) => /react-dom.*community-race-calendar/.test(src)), 'WordPress react scripts are loaded and none is bundled')
  check(await page.$('.crc-brand, .crc-header, header') !== null, 'the header is displayed')

  const courseButtons = await page.$$('.crc-main button')
  check(courseButtons.length > 0, 'the calendar view lists interactive controls')
  const addButton = await page.evaluateHandle(() => [...document.querySelectorAll('button')].find((button) => /Ajouter une course$/.test(button.textContent.trim())))
  check(addButton.asElement() !== null, 'the member sees the add-a-course button')
  await addButton.asElement().click()
  await page.waitForSelector('[role="dialog"] input', { timeout: 10000 })
  check(true, 'the course form opens in a dialog (portal) with its fields')
  await page.type('[role="dialog"] input[required]', 'Smoke test typing')
  const typed = await page.$eval('[role="dialog"] input[required]', (input) => input.value)
  check(typed === 'Smoke test typing', 'typing in a controlled input updates its value')
  await page.keyboard.press('Escape')
  await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 5000 })
  check(true, 'the dialog closes with Escape')
  const courseTitle = await page.$('.crc-card')
  if (courseTitle) {
    await courseTitle.click()
    await page.waitForSelector('[role="dialog"]', { timeout: 10000 })
    check(true, 'opening a course shows its detail dialog')
    await page.keyboard.press('Escape')
    await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 5000 })
  } else {
    console.log('note no course card displayed; detail dialog not exercised')
  }

  if (designExpectation) {
    // Settings under test: font "Courier New, monospace", width 900px, text color #112233.
    const rendered = await page.evaluate(() => ({
      width: getComputedStyle(document.querySelector('.crc-main')).maxWidth,
      font: getComputedStyle(document.querySelector('.crc-shell')).fontFamily,
      color: getComputedStyle(document.querySelector('.crc-shell')).color,
      title: getComputedStyle(document.querySelector('.crc-brandmark strong')).fontFamily,
    }))
    check(rendered.width === '900px', `the maximum content width is applied (${rendered.width})`)
    check(rendered.font.includes('Courier New') && rendered.title.includes('Courier New'), `the font stack is applied (${rendered.font})`)
    check(rendered.color === 'rgb(17, 34, 51)', `the text color is applied (${rendered.color})`)
    const opener = await page.evaluateHandle(() => [...document.querySelectorAll('button')].find((button) => /Ajouter une course$/.test(button.textContent.trim())))
    await opener.asElement().click()
    await page.waitForSelector('.crc-modal')
    const modalFont = await page.$eval('.crc-modal', (node) => getComputedStyle(node).fontFamily)
    check(modalFont.includes('Courier New'), 'the font also applies to dialogs rendered in a portal')
    await page.keyboard.press('Escape')
    await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 5000 })
  }

  const mapTab = await page.evaluateHandle(() => [...document.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Carte'))
  await mapTab.asElement().click()
  await new Promise((resolve) => setTimeout(resolve, 1500))
  if (mapExpectation === 'disabled') {
    const text = await page.$eval('.crc-main', (node) => node.textContent)
    check(text.includes('La carte n’est pas activée sur ce site.'), 'a neutral message replaces the map')
    check(await page.$('.crc-map-canvas') === null, 'no map canvas is mounted')
    check(!blocked.some((url) => url.includes('openfreemap')), 'no request is made to the tile service')
  } else {
    check(await page.$('.crc-map-canvas') !== null, 'the map canvas is mounted')
    check(blocked.some((url) => url.includes('tiles.openfreemap.org')), 'the tile service is requested (and was blocked by this test)')
  }

  const calendarTab = await page.evaluateHandle(() => [...document.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Calendrier'))
  await calendarTab.asElement().click()
  await new Promise((resolve) => setTimeout(resolve, 500))
  check(await page.$('.crc-main') !== null, 'switching back to the calendar keeps the interface usable')
  check(consoleErrors.length === 0, `no application console error (${consoleErrors.join(' | ')})`)
  console.log(`blocked external hosts: ${[...new Set(blocked.map((url) => new URL(url).host))].join(', ') || 'none'}`)
} finally {
  await browser.close()
}
if (failures.length) {
  console.error(`${failures.length} smoke check(s) failed`)
  process.exit(1)
}
console.log('Browser smoke test passed.')
