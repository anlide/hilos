import { describe, expect, it } from 'vitest'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
  type HilosPageKey,
} from '../../src/routing/hilosPages.js'
import {
  HILOS_UNBUILT_PAGES,
  hilosUnbuiltPages,
} from '../../src/routing/hilosUnbuiltPages.js'

describe('hilosUnbuiltPages', () => {
  it('selects the view layer without hiding a section with built children', () => {
    const vue = hilosUnbuiltPages('vue')
    expect(vue.has(HilosPages.ROLES)).toBe(true)
    expect(vue.has(HilosPages.DAEMON)).toBe(true)
    expect(vue.has(HilosPages.SECURITY)).toBe(false)
    expect(vue.has(HilosPages.MAINTENANCE)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.MAINTENANCE)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.MAINTENANCE)).toBe(false)
  })

  it('keeps both new Environment pages and WebSockets unbuilt in every view layer', () => {
    for (const layer of ['vue', 'react', 'angular'] as const) {
      const unbuilt = hilosUnbuiltPages(layer)
      expect(unbuilt.has(HilosPages.DAEMON_ENV)).toBe(true)
      expect(unbuilt.has(HilosPages.DAEMON_ENV_MISMATCH)).toBe(true)
      expect(unbuilt.has(HilosPages.DAEMON_WEBSOCKETS)).toBe(true)
    }
  })

  it('removes project views only from this result and ignores unrelated keys', () => {
    expect(
      hilosUnbuiltPages('vue', [HilosPages.ROLES]).has(HilosPages.ROLES),
    ).toBe(false)
    expect(hilosUnbuiltPages('vue').has(HilosPages.ROLES)).toBe(true)
    expect(
      hilosUnbuiltPages('vue', ['project_page', HilosPages.SECURITY]),
    ).toEqual(hilosUnbuiltPages('vue'))
  })

  it('lists only admin pages with nonempty, distinct view layers', () => {
    for (const [key, layers] of Object.entries(HILOS_UNBUILT_PAGES)) {
      expect(HILOS_ROUTE_DECLARATIONS[key as HilosPageKey].admin).toBe(true)
      expect(layers.length).toBeGreaterThan(0)
      expect(new Set(layers).size).toBe(layers.length)
    }
  })
})
