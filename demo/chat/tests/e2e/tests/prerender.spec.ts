import { test, expect } from '@playwright/test'

// SSG (build-and-docker.md): the public footer pages are prerendered to static
// HTML through Vue's native server renderer, so a crawler — or any no-JS fetch —
// sees the page content without running the SPA. nginx serves <route>.html for
// the public routes and falls back to the SPA shell only for the app's own deep
// links. These checks fetch the raw HTML (request, not a browser), so they
// assert the prerendered markup itself, not what the client renders afterwards.

test('a public page is prerendered with its content and title', async ({
  request,
}) => {
  const res = await request.get('/about')
  expect(res.status()).toBe(200)
  const html = await res.text()
  // The About prose is in the served HTML, before any JavaScript runs.
  expect(html).toContain('Hilos Chat is a demonstration')
  expect(html).toMatch(/<title>About[^<]*<\/title>/)
})

test('the license page is prerendered with the build inventory in it', async ({
  request,
}) => {
  const res = await request.get('/license')
  expect(res.status()).toBe(200)
  const html = await res.text()
  // The inventory is a build-time snapshot handed to the page as a prop, so the
  // rows are in the served HTML before any JavaScript runs — and one of them is
  // the framework itself, read from this project's own composer.lock.
  expect(html).toContain('data-id="license-row"')
  expect(html).toContain('anlide/hilos')
})

test('the privacy page is prerendered with the erase block whole', async ({
  request,
}) => {
  const res = await request.get('/privacy')
  expect(res.status()).toBe(200)
  const html = await res.text()
  // The block is a declaration and a click handler, never a read of the browser,
  // so it renders whole on a machine that has none — button included, inert until
  // the SPA mounts.
  expect(html).toContain('data-id="privacy-erase"')
  expect(html).toContain('Erase and sign out')
})

test('robots.txt and sitemap.xml advertise the public surface', async ({
  request,
}) => {
  const robots = await request.get('/robots.txt')
  expect(robots.status()).toBe(200)
  expect(await robots.text()).toContain('Sitemap:')

  const sitemap = await request.get('/sitemap.xml')
  expect(sitemap.status()).toBe(200)
  expect(await sitemap.text()).toContain('/about')
})

test('an app deep link cold-loads the SPA shell, not a prerender', async ({
  request,
}) => {
  const res = await request.get('/profile')
  expect(res.status()).toBe(200)
  // The SPA shell: an empty mount point the client fills, with no prerendered
  // content — the authed area is never forced through the prerender path.
  expect(await res.text()).toContain('<div id="app"></div>')
})
