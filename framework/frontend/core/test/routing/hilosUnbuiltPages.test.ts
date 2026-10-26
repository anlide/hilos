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
  it('serves the contextual Frame page in all three kits', () => {
    for (const kit of ['vue', 'react', 'angular'] as const) {
      expect(
        hilosUnbuiltPages(kit).has(HilosPages.COMMUNICATIONS_CHANNEL_FRAME),
      ).toBe(false)
    }
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.COMMUNICATIONS_CHANNEL_FRAME,
    )
  })
  it('selects the view layer without hiding a section with built children', () => {
    const vue = hilosUnbuiltPages('vue')
    expect(vue.has(HilosPages.ROLES)).toBe(true)
    expect(vue.has(HilosPages.DAEMON)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.DAEMON)).toBe(false)
    expect(vue.has(HilosPages.DAEMON_WORKERS)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_WORKERS)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('angular').has(HilosPages.DAEMON_WORKERS)).toBe(
      false,
    )
    expect(vue.has(HilosPages.DAEMON_AGENTS)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_AGENTS)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.DAEMON_AGENTS)).toBe(
      false,
    )
    expect(vue.has(HilosPages.DAEMON_CRON)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_CRON)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.DAEMON_CRON)).toBe(false)
    expect(vue.has(HilosPages.DAEMON_HTTP_SERVER)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_HTTP_SERVER)).toBe(
      false,
    )
    expect(
      hilosUnbuiltPages('angular').has(HilosPages.DAEMON_HTTP_SERVER),
    ).toBe(false)
    expect(vue.has(HilosPages.SECURITY)).toBe(false)
    expect(vue.has(HilosPages.MAINTENANCE)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.MAINTENANCE)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.MAINTENANCE)).toBe(false)
  })

  it('keeps only React and Angular Circle views unbuilt', () => {
    expect(HILOS_UNBUILT_PAGES[HilosPages.MAINTENANCE_CIRCLE]).toEqual([
      'react',
      'angular',
    ])
    expect(hilosUnbuiltPages('vue').has(HilosPages.MAINTENANCE_CIRCLE)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.MAINTENANCE_CIRCLE)).toBe(
      true,
    )
    expect(
      hilosUnbuiltPages('angular').has(HilosPages.MAINTENANCE_CIRCLE),
    ).toBe(true)
  })

  it('keeps React and Angular Moderation views unbuilt while Vue is built by this sheet', () => {
    expect(HILOS_UNBUILT_PAGES[HilosPages.MODERATION]).toEqual([
      'react',
      'angular',
    ])
    expect(hilosUnbuiltPages('vue').has(HilosPages.MODERATION)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.MODERATION)).toBe(true)
    expect(hilosUnbuiltPages('angular').has(HilosPages.MODERATION)).toBe(true)
  })

  it('serves kind Overview only in Vue, with Rules still absent', () => {
    expect(HILOS_UNBUILT_PAGES[HilosPages.MODERATION_KIND]).toEqual([
      'react',
      'angular',
    ])
    expect(hilosUnbuiltPages('vue').has(HilosPages.MODERATION_KIND)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.MODERATION_KIND)).toBe(
      true,
    )
    expect(hilosUnbuiltPages('angular').has(HilosPages.MODERATION_KIND)).toBe(
      true,
    )
    expect(Object.values(HilosPages)).not.toContain(
      'hilos_moderation_kind_rules',
    )
  })

  it('opens the Daemon environment page in all three view layers', () => {
    expect(hilosUnbuiltPages('vue').has(HilosPages.DAEMON_ENV)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_ENV)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.DAEMON_ENV)).toBe(false)
  })

  it('opens the Daemon environment mismatch page in all three view layers', () => {
    expect(hilosUnbuiltPages('vue').has(HilosPages.DAEMON_ENV_MISMATCH)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_ENV_MISMATCH)).toBe(
      false,
    )
    expect(
      hilosUnbuiltPages('angular').has(HilosPages.DAEMON_ENV_MISMATCH),
    ).toBe(false)
  })

  it('opens Node in every view layer', () => {
    expect(hilosUnbuiltPages('vue').has(HilosPages.DAEMON_NODE)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.DAEMON_NODE)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.DAEMON_NODE)).toBe(false)
  })

  it('keeps WebSockets unbuilt in every view layer', () => {
    for (const layer of ['vue', 'react', 'angular'] as const) {
      const unbuilt = hilosUnbuiltPages(layer)
      expect(unbuilt.has(HilosPages.DAEMON_WEBSOCKETS)).toBe(true)
    }
  })

  it('opens the Analytics pages in every layer', () => {
    for (const page of [
      HilosPages.ANALYTICS,
      HilosPages.ANALYTICS_BROWSER_SESSION,
      HilosPages.ANALYTICS_PERSON,
    ]) {
      expect(hilosUnbuiltPages('vue').has(page)).toBe(false)
      expect(hilosUnbuiltPages('react').has(page)).toBe(false)
      expect(hilosUnbuiltPages('angular').has(page)).toBe(false)
    }
  })

  it('opens all eight completed Vue i18n pages', () => {
    const unbuilt = hilosUnbuiltPages('vue')
    expect(unbuilt.has(HilosPages.I18N)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRIES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE_NAMES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE_LOCALES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRY)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRY_NAMES)).toBe(false)
  })

  it('opens all eight completed React i18n pages', () => {
    const unbuilt = hilosUnbuiltPages('react')
    expect(unbuilt.has(HilosPages.I18N)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRIES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE_NAMES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE_LOCALES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRY)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRY_NAMES)).toBe(false)
  })

  it('opens all eight completed Angular i18n pages while the translation pages remain unbuilt', () => {
    const unbuilt = hilosUnbuiltPages('angular')
    expect(unbuilt.has(HilosPages.I18N)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE_NAMES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_LANGUAGE_LOCALES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRIES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRY)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_COUNTRY_NAMES)).toBe(false)
    expect(unbuilt.has(HilosPages.I18N_TRANSLATE_ENTITY)).toBe(true)
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

  it('keeps the public file reader unbuilt only in React and Angular', () => {
    expect(HILOS_UNBUILT_PAGES[HilosPages.PUBLIC_STATUS]).toEqual([
      'react',
      'angular',
    ])
    expect(hilosUnbuiltPages('vue').has(HilosPages.PUBLIC_STATUS)).toBe(false)
    for (const layer of ['react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.PUBLIC_STATUS)).toBe(true)
    }
  })

  it('lists admin pages and only the public Status exception, with distinct layers', () => {
    for (const [key, layers] of Object.entries(HILOS_UNBUILT_PAGES)) {
      expect(HILOS_ROUTE_DECLARATIONS[key as HilosPageKey].admin).toBe(
        key !== HilosPages.PUBLIC_STATUS,
      )
      expect(layers.length).toBeGreaterThan(0)
      expect(new Set(layers).size).toBe(layers.length)
    }
  })
})
