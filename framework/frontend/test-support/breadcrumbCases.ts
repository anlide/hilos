// Breadcrumb semantics shared by the three real SDK components.
import {
  createSignal,
  HilosPages,
  resolveHilosPath,
  type HilosCrumb,
  type HilosRouter,
} from '@hilos/core/base'
import { getByRole } from '@testing-library/dom'
import type * as Vitest from 'vitest'
import { readTextWithoutAriaHidden } from '../test/breadcrumb.js'

type BreadcrumbRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Caller input and the optional real router context for the trail. */
export interface BreadcrumbInput {
  crumbs: HilosCrumb[]
  label?: string
  router?: HilosRouter
}

/** One mounted breadcrumb and its native destruction boundary. */
export interface BreadcrumbView {
  readonly root: HTMLElement
  unmount(): Promise<void>
}

type MountBreadcrumb = (input: BreadcrumbInput) => Promise<BreadcrumbView>

const DASHBOARD: HilosCrumb = {
  page: HilosPages.DASHBOARD,
  label: 'Dashboard',
  to: resolveHilosPath(HilosPages.DASHBOARD),
}
const BILLING: HilosCrumb = {
  page: HilosPages.BILLING,
  label: 'Billing',
  to: resolveHilosPath(HilosPages.BILLING),
}
const TRAIL: HilosCrumb[] = [DASHBOARD, BILLING]
const ADDRESSLESS_TRAIL: HilosCrumb[] = [
  DASHBOARD,
  { page: 'unrouted', label: 'Section' },
  BILLING,
]

/** Register the complete trail, naming, current-entry and separator union. */
export function registerBreadcrumbCases(
  sdk: string,
  runner: BreadcrumbRunner,
  mount: MountBreadcrumb,
): void {
  const { describe, it, expect } = runner
  describe(`HilosBreadcrumb on ${sdk}`, () => {
    it('B01 renders no navigation for an empty trail', async () => {
      const view = await mount({ crumbs: [] })
      try {
        expect(view.root.querySelector('nav')).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-breadcrumb"]'),
        ).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('B02 defaults the navigation name to breadcrumb', async () => {
      const view = await mount({ crumbs: TRAIL })
      try {
        const nav = getByRole(view.root, 'navigation', { name: 'breadcrumb' })
        expect(nav.tagName).toBe('NAV')
        expect(nav.getAttribute('aria-label')).toBe('breadcrumb')
        expect(nav.getAttribute('data-id')).toBe('hilos-breadcrumb')
      } finally {
        await view.unmount()
      }
    })

    it('B03 names the navigation with the caller label', async () => {
      const view = await mount({
        crumbs: TRAIL,
        label: 'Path to this page',
      })
      try {
        const nav = getByRole(view.root, 'navigation', {
          name: 'Path to this page',
        })
        expect(nav.tagName).toBe('NAV')
        expect(nav.getAttribute('aria-label')).toBe('Path to this page')
        expect(nav.getAttribute('data-id')).toBe('hilos-breadcrumb')
      } finally {
        await view.unmount()
      }
    })

    it('B04 links ancestors and keeps the addressed last crumb current text', async () => {
      const view = await mount({ crumbs: TRAIL })
      try {
        const items = [...view.root.querySelectorAll('ol > li')]
        expect(items).toHaveLength(2)
        expect(items.map((item) => readTextWithoutAriaHidden(item))).toEqual([
          'Dashboard',
          'Billing',
        ])
        const links = view.root.querySelectorAll('a')
        expect(links).toHaveLength(1)
        expect(items[0].querySelector('a')).toBe(links[0])
        expect(getByRole(view.root, 'link', { name: 'Dashboard' })).toBe(
          links[0],
        )
        expect(links[0].getAttribute('data-id')).toBe('hilos-breadcrumb-hilos')
        expect(links[0].getAttribute('href')).toBe('/hilos')
        expect(readTextWithoutAriaHidden(links[0])).toBe('Dashboard')
        expect(items[1].querySelector('a')).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-breadcrumb-hilos_billing"]'),
        ).toBeNull()
        const current = view.root.querySelectorAll('[aria-current]')
        expect(current).toHaveLength(1)
        expect(current[0]).toBe(items[1])
        expect(current[0].getAttribute('aria-current')).toBe('page')
      } finally {
        await view.unmount()
      }
    })

    it('B05 preserves an addressless middle crumb as non-current text', async () => {
      const view = await mount({ crumbs: ADDRESSLESS_TRAIL })
      try {
        const items = [...view.root.querySelectorAll('ol > li')]
        expect(items).toHaveLength(3)
        expect(items.map((item) => readTextWithoutAriaHidden(item))).toEqual([
          'Dashboard',
          'Section',
          'Billing',
        ])
        const links = view.root.querySelectorAll('a')
        expect(links).toHaveLength(1)
        expect(items[0].querySelector('a')).toBe(links[0])
        expect(links[0].getAttribute('href')).toBe('/hilos')
        expect(items[1].querySelector('a')).toBeNull()
        expect(items[1].hasAttribute('aria-current')).toBe(false)
        expect(
          view.root.querySelector('[data-id="hilos-breadcrumb-unrouted"]'),
        ).toBeNull()
        const current = view.root.querySelectorAll('[aria-current]')
        expect(current).toHaveLength(1)
        expect(current[0]).toBe(items[2])
        expect(current[0].getAttribute('aria-current')).toBe('page')
      } finally {
        await view.unmount()
      }
    })

    it('B06 keeps only the last LI current when the router matches an ancestor', async () => {
      const router = {
        currentPath: createSignal('/hilos'),
        navigate: () => {},
      } as unknown as HilosRouter
      const view = await mount({ crumbs: TRAIL, router })
      try {
        const items = view.root.querySelectorAll('ol > li')
        expect(items).toHaveLength(2)
        const current = view.root.querySelectorAll('[aria-current]')
        expect(current).toHaveLength(1)
        expect(current[0]).toBe(items[1])
        expect(current[0].tagName).toBe('LI')
        expect(current[0].getAttribute('aria-current')).toBe('page')
        expect(readTextWithoutAriaHidden(current[0])).toBe('Billing')
        expect(items[1].querySelector('a')).toBeNull()
        const link = items[0].querySelector('a')
        expect(link).not.toBeNull()
        expect(getByRole(view.root, 'link', { name: 'Dashboard' })).toBe(link)
        expect(link?.getAttribute('href')).toBe('/hilos')
        expect(readTextWithoutAriaHidden(link!)).toBe('Dashboard')
        expect(link?.hasAttribute('aria-current')).toBe(false)
      } finally {
        await view.unmount()
      }
    })

    it.each([
      {
        name: 'linked middle crumb',
        crumbs: [
          DASHBOARD,
          {
            page: HilosPages.SETTINGS,
            label: 'Settings',
            to: resolveHilosPath(HilosPages.SETTINGS),
          },
          BILLING,
        ],
        labels: ['Dashboard', 'Settings', 'Billing'],
        linkLabels: ['Dashboard', 'Settings'],
      },
      {
        name: 'addressless middle crumb',
        crumbs: ADDRESSLESS_TRAIL,
        labels: ['Dashboard', 'Section', 'Billing'],
        linkLabels: ['Dashboard'],
      },
    ])('B07 keeps separators decorative with a $name', async (input) => {
      const view = await mount({ crumbs: input.crumbs })
      try {
        const items = [...view.root.querySelectorAll('ol > li')]
        expect(items).toHaveLength(3)
        expect(view.root.querySelector('.breadcrumb-item')).toBeNull()
        for (const item of items) {
          expect(item.classList.contains('breadcrumb-item')).toBe(false)
        }
        expect(items[0].querySelector('span')).toBeNull()
        for (const item of items.slice(1)) {
          const separators = item.querySelectorAll(':scope > span')
          expect(separators).toHaveLength(1)
          expect(item.firstElementChild).toBe(separators[0])
          expect(separators[0].textContent).toBe('/')
          expect(separators[0].getAttribute('aria-hidden')).toBe('true')
        }
        expect(items.map((item) => readTextWithoutAriaHidden(item))).toEqual(
          input.labels,
        )
        const links = [...view.root.querySelectorAll('a')]
        expect(links.map((link) => readTextWithoutAriaHidden(link))).toEqual(
          input.linkLabels,
        )
        for (const link of links) {
          expect(link.querySelector('span[aria-hidden="true"]')).toBeNull()
          expect(link.textContent).not.toContain('/')
        }
        expect(items[2].querySelector('a')).toBeNull()
        expect(items[2].getAttribute('aria-current')).toBe('page')
      } finally {
        await view.unmount()
      }
    })

    it('B08 renders a singleton trail as current text without separators', async () => {
      const view = await mount({ crumbs: [DASHBOARD] })
      try {
        const nav = view.root.querySelector('nav')
        expect(nav).not.toBeNull()
        expect(nav?.getAttribute('data-id')).toBe('hilos-breadcrumb')
        const items = view.root.querySelectorAll('ol > li')
        expect(items).toHaveLength(1)
        expect(readTextWithoutAriaHidden(items[0])).toBe('Dashboard')
        const current = view.root.querySelectorAll('[aria-current]')
        expect(current).toHaveLength(1)
        expect(current[0]).toBe(items[0])
        expect(current[0].getAttribute('aria-current')).toBe('page')
        expect(view.root.querySelector('a')).toBeNull()
        expect(view.root.querySelector('span')).toBeNull()
        expect(view.root.textContent).not.toContain('/')
      } finally {
        await view.unmount()
      }
    })
  })
}
