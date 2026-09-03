// prerenderPublicRoutes end to end against a real tmp distDir and a fake
// renderRoute: a successful render writes one <route>.html per footer page and
// returns the routes; a render failure fails the build fast, writing nothing.
import {
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  resolvePageTitle,
} from '@hilos/core'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

import { prerenderPublicRoutes } from '../src/orchestrate.js'
import type { RenderResult } from '../src/types.js'

const TEMPLATE =
  '<!doctype html><html><head><title>App</title></head>' +
  '<body><div id="app"></div></body></html>'

/** A component per public footer page — opaque to the orchestration. */
function allComponents(): Record<string, string> {
  return Object.fromEntries(
    HILOS_FOOTER_LINKS.map(({ page }) => [page, `component:${page}`]),
  )
}

/** The output file name the orchestration writes for a route: `/about.html`. */
function fileName(route: string): string {
  return `${route.replace(/^\//, '')}.html`
}

let dir: string

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'prerender-orchestrate-'))
  writeFileSync(join(dir, 'index.html'), TEMPLATE)
})

afterEach(() => {
  rmSync(dir, { recursive: true, force: true })
})

it('writes one <route>.html per footer page and returns the routes', async () => {
  const renderRoute = vi.fn(
    async ({ route }: { route: string }): Promise<RenderResult> => ({
      ok: true,
      html: `<html data-route="${route}"></html>`,
    }),
  )

  const written = await prerenderPublicRoutes({
    components: allComponents(),
    renderRoute,
    distDir: dir,
    pageTitles: {},
    appName: 'Demo',
  })

  const expectedRoutes = HILOS_FOOTER_LINKS.map(
    ({ page }) => HILOS_PAGE_ROUTES[page],
  )
  expect(written).toEqual(expectedRoutes)
  for (const route of expectedRoutes) {
    expect(readFileSync(join(dir, fileName(route)), 'utf8')).toBe(
      `<html data-route="${route}"></html>`,
    )
  }
  expect(readdirSync(dir).some((name) => name.endsWith('.tmp'))).toBe(false)
})

it('passes the template and resolved title to renderRoute', async () => {
  const renderRoute = vi.fn(
    async (): Promise<RenderResult> => ({ ok: true, html: '<html></html>' }),
  )

  await prerenderPublicRoutes({
    components: allComponents(),
    renderRoute,
    distDir: dir,
    pageTitles: {},
    appName: 'Demo',
  })

  const first = HILOS_FOOTER_LINKS[0]
  expect(renderRoute).toHaveBeenCalledWith(
    expect.objectContaining({
      route: HILOS_PAGE_ROUTES[first.page],
      component: `component:${first.page}`,
      template: TEMPLATE,
      title: resolvePageTitle(first.page, {}, 'Demo', undefined, true),
    }),
  )
})

it('fails the build fast and writes nothing when a render fails', async () => {
  const renderRoute = vi.fn(
    async (): Promise<RenderResult> => ({ ok: false, error: 'boom' }),
  )

  await expect(
    prerenderPublicRoutes({
      components: allComponents(),
      renderRoute,
      distDir: dir,
      pageTitles: {},
      appName: 'Demo',
    }),
  ).rejects.toThrow(/boom/)

  expect(renderRoute).toHaveBeenCalledTimes(1)
  expect(readdirSync(dir)).toEqual(['index.html'])
})
