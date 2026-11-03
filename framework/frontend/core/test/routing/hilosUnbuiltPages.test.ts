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
  it('serves Revision Text in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.LEGAL_REVISION_TEXT,
    )
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.LEGAL_REVISION_TEXT)).toBe(
        false,
      )
      expect(hilosUnbuiltPages(layer).has(HilosPages.LEGAL_REVISION)).toBe(
        false,
      )
      expect(
        hilosUnbuiltPages(layer, [HilosPages.LEGAL_REVISION_TEXT]).has(
          HilosPages.LEGAL_REVISION_TEXT,
        ),
      ).toBe(false)
    }
  })

  it('serves all three document tabs in every view layer', () => {
    for (const page of [
      HilosPages.LEGAL_DOCUMENT_SET,
      HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
    ]) {
      expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(page)
      for (const layer of ['vue', 'react', 'angular'] as const) {
        expect(hilosUnbuiltPages(layer).has(page)).toBe(false)
        expect(hilosUnbuiltPages(layer).has(HilosPages.LEGAL_DOCUMENT)).toBe(
          false,
        )
        expect(hilosUnbuiltPages(layer).has(HilosPages.LEGAL_REVISION)).toBe(
          false,
        )
        expect(hilosUnbuiltPages(layer, [page]).has(page)).toBe(false)
      }
    }
  })

  it('serves Legal Documents in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.LEGAL_DOCUMENTS)
    for (const layer of ['vue', 'react', 'angular'] as const)
      expect(hilosUnbuiltPages(layer).has(HilosPages.LEGAL_DOCUMENTS)).toBe(
        false,
      )
  })

  it('serves the Status Overview in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.STATUS)
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.STATUS)).toBe(false)
      expect(
        hilosUnbuiltPages(layer, [HilosPages.STATUS]).has(HilosPages.STATUS),
      ).toBe(false)
    }
  })

  it('serves Status Components in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.STATUS_COMPONENTS)
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.STATUS_COMPONENTS)).toBe(
        false,
      )
      expect(
        hilosUnbuiltPages(layer, [HilosPages.STATUS_COMPONENTS]).has(
          HilosPages.STATUS_COMPONENTS,
        ),
      ).toBe(false)
    }
  })

  it('serves Status History in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.STATUS_HISTORY)
    for (const layer of ['vue', 'react', 'angular'] as const)
      expect(hilosUnbuiltPages(layer).has(HilosPages.STATUS_HISTORY)).toBe(
        false,
      )
    expect(
      hilosUnbuiltPages('angular', [HilosPages.STATUS_HISTORY]).has(
        HilosPages.STATUS_HISTORY,
      ),
    ).toBe(false)
  })

  it('serves Status Subscribers in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.STATUS_SUBSCRIBERS,
    )
    expect(hilosUnbuiltPages('vue').has(HilosPages.STATUS_SUBSCRIBERS)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.STATUS_SUBSCRIBERS)).toBe(
      false,
    )
    expect(
      hilosUnbuiltPages('angular').has(HilosPages.STATUS_SUBSCRIBERS),
    ).toBe(false)
  })
  it('serves Works in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.STATUS_WORKS)
    for (const kit of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(kit).has(HilosPages.STATUS_WORKS)).toBe(false)
      expect(
        hilosUnbuiltPages(kit, [HilosPages.STATUS_WORKS]).has(
          HilosPages.STATUS_WORKS,
        ),
      ).toBe(false)
    }
  })

  it('serves the session list and detail in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.ANALYTICS_BROWSER_SESSIONS,
    )
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.ANALYTICS_BROWSER_SESSION,
    )
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(
        hilosUnbuiltPages(layer).has(HilosPages.ANALYTICS_BROWSER_SESSIONS),
      ).toBe(false)
      expect(
        hilosUnbuiltPages(layer).has(HilosPages.ANALYTICS_BROWSER_SESSION),
      ).toBe(false)
    }
  })

  it('serves People in all three kits', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.ANALYTICS_PEOPLE)
    expect(hilosUnbuiltPages('vue').has(HilosPages.ANALYTICS_PEOPLE)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.ANALYTICS_PEOPLE)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('angular').has(HilosPages.ANALYTICS_PEOPLE)).toBe(
      false,
    )
  })

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

  it('serves Maintenance Circle in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.MAINTENANCE_CIRCLE,
    )
    expect(hilosUnbuiltPages('vue').has(HilosPages.MAINTENANCE_CIRCLE)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.MAINTENANCE_CIRCLE)).toBe(
      false,
    )
    expect(
      hilosUnbuiltPages('angular').has(HilosPages.MAINTENANCE_CIRCLE),
    ).toBe(false)
  })

  it('serves Maintenance Words in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.MAINTENANCE_WORDS)
    expect(hilosUnbuiltPages('vue').has(HilosPages.MAINTENANCE_WORDS)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.MAINTENANCE_WORDS)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('angular').has(HilosPages.MAINTENANCE_WORDS)).toBe(
      false,
    )
  })

  it('serves Maintenance Nodes in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.MAINTENANCE_NODES)
    expect(hilosUnbuiltPages('vue').has(HilosPages.MAINTENANCE_NODES)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('react').has(HilosPages.MAINTENANCE_NODES)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('angular').has(HilosPages.MAINTENANCE_NODES)).toBe(
      false,
    )
  })

  it('serves the Moderation root in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.MODERATION)
    expect(hilosUnbuiltPages('vue').has(HilosPages.MODERATION)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.MODERATION)).toBe(false)
    expect(hilosUnbuiltPages('angular').has(HilosPages.MODERATION)).toBe(false)
  })

  it('serves kind Overview and Rules in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.MODERATION_KIND)
    expect(hilosUnbuiltPages('vue').has(HilosPages.MODERATION_KIND)).toBe(false)
    expect(hilosUnbuiltPages('react').has(HilosPages.MODERATION_KIND)).toBe(
      false,
    )
    expect(hilosUnbuiltPages('angular').has(HilosPages.MODERATION_KIND)).toBe(
      false,
    )
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(
      HilosPages.MODERATION_KIND_RULES,
    )
    expect(hilosUnbuiltPages('vue').has(HilosPages.MODERATION_KIND_RULES)).toBe(
      false,
    )
    expect(
      hilosUnbuiltPages('react').has(HilosPages.MODERATION_KIND_RULES),
    ).toBe(false)
    expect(
      hilosUnbuiltPages('angular').has(HilosPages.MODERATION_KIND_RULES),
    ).toBe(false)
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

  it('opens WebSockets in every view layer', () => {
    for (const layer of ['vue', 'react', 'angular'] as const) {
      const unbuilt = hilosUnbuiltPages(layer)
      expect(unbuilt.has(HilosPages.DAEMON_WEBSOCKETS)).toBe(false)
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

  it('serves the public file reader in all three view layers', () => {
    expect(HILOS_UNBUILT_PAGES).not.toHaveProperty(HilosPages.PUBLIC_STATUS)
    for (const layer of ['vue', 'react', 'angular'] as const) {
      expect(hilosUnbuiltPages(layer).has(HilosPages.PUBLIC_STATUS)).toBe(false)
      expect(
        hilosUnbuiltPages(layer, [HilosPages.PUBLIC_STATUS]).has(
          HilosPages.PUBLIC_STATUS,
        ),
      ).toBe(false)
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
