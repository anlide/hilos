import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'

// SSG (build-and-docker.md): the public footer pages are prerendered to static
// HTML through Angular's native server renderer (renderApplication), so a
// crawler — or any no-JS fetch — sees the page content without running the SPA.
// nginx serves the prerendered HTML for the public routes and falls back to the
// SPA shell for the app's own deep links. These checks fetch the raw HTML
// (request, not a browser), so they assert the prerendered markup itself.

test('a public page is prerendered with its content and title', async ({
  request,
}) => {
  const res = await request.get('/about')
  expect(res.status()).toBe(200)
  const html = await res.text()
  // The About prose is in the served HTML, before any JavaScript runs.
  expect(html).toContain('Hilos Polls is a demonstration')
  // The support block is behavior, and it is in the served HTML too: the plate
  // and its button render on a machine with no browser, inert until the SPA mounts.
  expect(html).toContain('Support the project')
  expect(html).toMatch(/<title>About[^<]*<\/title>/)
})

test('the project license is prerendered as exact text', async ({
  request,
}) => {
  const res = await request.get('/license')
  expect(res.status()).toBe(200)
  const html = await res.text()
  const projectLicenseText = await readFile(
    new URL('../../../LICENSE', import.meta.url),
    'utf8',
  )
  // SSR may serialize quotes as text or as &quot;; compare the response with the
  // complete source text after normalizing that HTML representation.
  expect(html.replaceAll('&quot;', '"')).toContain(projectLicenseText)
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
  const res = await request.get('/hilos')
  expect(res.status()).toBe(200)
  // The SPA shell: an empty mount point the client fills, with no prerendered
  // content — the authed area is never forced through the prerender path.
  expect(await res.text()).toContain('<app-root></app-root>')
})
