// One banner scenario text, registered by the three native Layout test entries.
import type * as Vitest from 'vitest'
import {
  ADMITTED,
  layoutChromeConnection,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type LayoutBannerRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

const PROJECT_BANNER = {
  dataId: 'test-banner',
  text: 'Acting for someone else',
}

/** Register only the banner union; other Layout behavior keeps its own owner. */
export function registerLayoutBannerCases(
  sdk: string,
  runner: LayoutBannerRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout banner region on ${sdk}`, () => {
    it('B01 keeps an empty region for a project to fill', async () => {
      const view = await mount({ connection: layoutChromeConnection() })
      try {
        const region = part(view.root, 'app-banner')
        expect(region).not.toBeNull()
        expect(region?.childElementCount).toBe(0)
        expect(region?.textContent?.trim()).toBe('')
      } finally {
        await view.unmount()
      }
    })

    it('B02 puts native project content inside the region', async () => {
      const view = await mount({
        connection: layoutChromeConnection(),
        banner: PROJECT_BANNER,
      })
      try {
        const region = part(view.root, 'app-banner')
        expect(region).not.toBeNull()
        expect(region && part(region, 'test-banner')).not.toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('B03 stands between the native navigation and main content', async () => {
      const view = await mount({ connection: layoutChromeConnection() })
      try {
        const root = part(view.root, 'app-root')
        expect(root).not.toBeNull()
        const children = Array.from(root?.children ?? [])
        const nav = children.findIndex((child) => child.tagName === 'NAV')
        const region = children.findIndex(
          (child) => child.getAttribute('data-id') === 'app-banner',
        )
        const main = children.findIndex(
          (child) =>
            child.tagName === 'MAIN' && child.id === 'hilos-main-content',
        )
        expect(nav).toBeGreaterThanOrEqual(0)
        expect(region).toBeGreaterThanOrEqual(0)
        expect(main).toBeGreaterThanOrEqual(0)
        expect(nav).toBeLessThan(region)
        expect(region).toBeLessThan(main)
      } finally {
        await view.unmount()
      }
    })

    it('B04 puts the admitted verification strip inside the region', async () => {
      const view = await mount({
        connection: layoutChromeConnection(ADMITTED),
      })
      try {
        const region = part(view.root, 'app-banner')
        expect(region).not.toBeNull()
        const strip = region && part(region, 'protected-mode-banner')
        expect(strip).not.toBeNull()
        expect(strip?.textContent).toContain('The restore is being verified.')
      } finally {
        await view.unmount()
      }
    })

    it('B05 adds no announcer when the framework strip goes up', async () => {
      const live = '[role="status"][aria-live="polite"]'
      let baseline: number
      const down = await mount({ connection: layoutChromeConnection() })
      try {
        const region = part(down.root, 'app-banner')
        expect(region?.getAttribute('role')).toBe('status')
        expect(region?.getAttribute('aria-live')).toBe('polite')
        baseline = down.root.querySelectorAll(live).length
      } finally {
        await down.unmount()
      }

      // Keep a numeric baseline after disposal, never a second Angular root.
      const up = await mount({ connection: layoutChromeConnection(ADMITTED) })
      try {
        const region = part(up.root, 'app-banner')
        expect(region?.getAttribute('role')).toBe('status')
        expect(region?.getAttribute('aria-live')).toBe('polite')
        expect(up.root.querySelectorAll(live).length).toBe(baseline)
      } finally {
        await up.unmount()
      }
    })

    it('B06 keeps the framework strip before project content in the region', async () => {
      const view = await mount({
        connection: layoutChromeConnection(ADMITTED),
        banner: PROJECT_BANNER,
      })
      try {
        const region = part(view.root, 'app-banner')
        expect(region).not.toBeNull()
        const children = Array.from(region?.children ?? [])
        const strip = children.findIndex(
          (child) => child.getAttribute('data-id') === 'protected-mode-banner',
        )
        const project = children.findIndex(
          (child) => child.getAttribute('data-id') === 'test-banner',
        )
        expect(strip).toBeGreaterThanOrEqual(0)
        expect(project).toBeGreaterThanOrEqual(0)
        expect(strip).toBeLessThan(project)
      } finally {
        await view.unmount()
      }
    })
  })
}
