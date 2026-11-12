// PageHead ancestry, rendered-page navigation and transition actions on all SDKs.
import type * as Vitest from 'vitest'
import { HilosPages } from '@hilos/core/base'
import { readTextWithoutAriaHidden } from '../test/breadcrumb.js'
import type { MountPageHead } from './pageHeadBlockCases.js'
import {
  ENTITY_CONTENT,
  ENTITY_PARAMS,
  SECTION,
  entityHeadInput,
  pageHeadRouterAt,
  resolvePath,
  sectionHeadInput,
} from './pageHeadFixtures.js'

type PageHeadRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

/** Register navigation steps and explicit expectations on the real head. */
export function registerPageHeadNavigationCases(
  sdk: string,
  runner: PageHeadRunner,
  mount: MountPageHead,
): void {
  const { describe, it, expect, vi } = runner
  describe(`HilosPageHead navigation on ${sdk}`, () => {
    for (const withContent of [true, false]) {
      it(`H04 names the entity's real navigation landmarks ${withContent ? 'with caller content' : 'without caller content'}`, async () => {
        const view = await mount(
          entityHeadInput({
            breadcrumbLabel: 'Path',
            tabsLabel: 'Entity pages',
            content: withContent ? ENTITY_CONTENT : undefined,
          }),
        )
        try {
          const breadcrumb = view.root.querySelector(
            '[data-id="hilos-breadcrumb"]',
          )
          const row = view.root.querySelector('[data-id="hilos-page-tabs"]')
          expect(breadcrumb?.tagName).toBe('NAV')
          expect(breadcrumb?.getAttribute('aria-label')).toBe('Path')
          expect(row?.tagName).toBe('NAV')
          expect(row?.getAttribute('aria-label')).toBe('Entity pages')
        } finally {
          await view.unmount()
        }
      })
    }

    it('H10 ends the named ordered ancestry at the unlinked entity owner', async () => {
      const view = await mount(entityHeadInput({ page: 'demo_entity_names' }))
      try {
        const crumbs = view.root.querySelector('[data-id="hilos-breadcrumb"]')
        expect(crumbs?.tagName).toBe('NAV')
        expect(crumbs?.getAttribute('aria-label')).toBe('Breadcrumb')
        expect(crumbs?.querySelector('ol')).not.toBeNull()
        const items = [...(crumbs?.querySelectorAll('ol > li') ?? [])]
        expect(items.map((item) => readTextWithoutAriaHidden(item))).toEqual([
          'Hilos',
          'Demo section',
          'List',
          'United States',
        ])
        expect(
          [...(crumbs?.querySelectorAll('a') ?? [])].map((link) =>
            link.getAttribute('href'),
          ),
        ).toEqual(['/hilos', '/hilos/demo', '/hilos/demo/list'])
        const current = crumbs?.querySelectorAll('[aria-current]') ?? []
        expect(current).toHaveLength(1)
        expect(current[0]).toBe(items[3])
        expect(current[0].tagName).toBe('LI')
        expect(current[0].getAttribute('aria-current')).toBe('page')
        expect(readTextWithoutAriaHidden(current[0])).toBe('United States')
        expect(current[0].textContent).not.toContain('Names')
        expect(current[0].querySelector('a')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('H11 retains the addressless intermediate People crumb as text', async () => {
      const view = await mount(
        sectionHeadInput({
          identity: {
            ...SECTION,
            breadcrumb: [
              { page: HilosPages.DASHBOARD, label: 'Hilos' },
              { page: 'demo_unrouted', label: 'People' },
              { page: 'demo_section', label: 'Demo section' },
            ],
          },
        }),
      )
      try {
        const items = [
          ...view.root.querySelectorAll('[data-id="hilos-breadcrumb"] li'),
        ]
        expect(items.map((item) => readTextWithoutAriaHidden(item))).toEqual([
          'Hilos',
          'People',
          'Demo section',
        ])
        expect(items[1].querySelector('a')).toBeNull()
        const links = view.root.querySelectorAll(
          '[data-id="hilos-breadcrumb"] a',
        )
        expect(links).toHaveLength(1)
        expect(links[0].getAttribute('href')).toBe('/hilos')
      } finally {
        await view.unmount()
      }
    })

    it('H12 draws a named list of four links in answered order without tab widgets or disabled entries', async () => {
      const view = await mount(sectionHeadInput({ page: 'demo_section_list' }))
      try {
        const row = view.root.querySelector('[data-id="hilos-page-tabs"]')
        expect(row?.tagName).toBe('NAV')
        expect(row?.getAttribute('aria-label')).toBe('Page sections')
        expect(row?.querySelectorAll('ul > li > a')).toHaveLength(4)
        const links = [...(row?.querySelectorAll('a') ?? [])]
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
          '/hilos/demo',
          '/hilos/demo/list',
          '/hilos/demo/gaps',
          '/hilos/demo/gaps/machine',
        ])
        expect(links.map((link) => link.textContent?.trim())).toEqual([
          'Overview',
          'List',
          'Gaps',
          'Machine',
        ])
        for (const selector of [
          '[role="tablist"]',
          '[role="tab"]',
          '.disabled',
          '[disabled]',
          '[aria-disabled="true"]',
          '.badge',
        ]) {
          expect(view.root.querySelector(selector), selector).toBeNull()
        }
      } finally {
        await view.unmount()
      }
    })

    it('H13 marks exactly the rendered Machine child, leaving its Gaps parent unmarked', async () => {
      const view = await mount(
        sectionHeadInput({ page: 'demo_section_gaps_machine' }),
      )
      try {
        const row = view.root.querySelector('[data-id="hilos-page-tabs"]')
        const current = row?.querySelectorAll('[aria-current]') ?? []
        expect(current).toHaveLength(1)
        expect(current[0].getAttribute('data-id')).toBe(
          'hilos-page-tab-demo_section_gaps_machine',
        )
        expect(current[0].getAttribute('aria-current')).toBe('page')
        const parent = row?.querySelector(
          '[data-id="hilos-page-tab-demo_section_gaps"]',
        )
        expect(parent).not.toBeNull()
        expect(parent?.hasAttribute('aria-current')).toBe(false)
      } finally {
        await view.unmount()
      }
    })

    it('H13 keeps the row with no current link for a rendered page outside it', async () => {
      const view = await mount(sectionHeadInput({ page: 'demo_elsewhere' }))
      try {
        const row = view.root.querySelector('[data-id="hilos-page-tabs"]')
        expect(row).not.toBeNull()
        expect(row?.querySelectorAll('[aria-current]')).toHaveLength(0)
      } finally {
        await view.unmount()
      }
    })

    it('H14 keeps Overview current when the real nested Link matches the next List path', async () => {
      const router = pageHeadRouterAt('/hilos/demo/list')
      const view = await mount(sectionHeadInput({ router }))
      try {
        expect(router.currentPath.get()).toBe('/hilos/demo/list')
        const row = view.root.querySelector('[data-id="hilos-page-tabs"]')
        const list = row?.querySelector(
          '[data-id="hilos-page-tab-demo_section_list"]',
        )
        expect(list?.tagName).toBe('A')
        expect(list?.getAttribute('href')).toBe('/hilos/demo/list')
        expect(list?.hasAttribute('aria-current')).toBe(false)
        const current = row?.querySelectorAll('[aria-current]') ?? []
        expect(current).toHaveLength(1)
        expect(current[0].getAttribute('data-id')).toBe(
          'hilos-page-tab-demo_section',
        )
        expect(current[0].getAttribute('aria-current')).toBe('page')
      } finally {
        await view.unmount()
      }
    })

    it('H14 updates the same head to Gaps while the real router still stands at Overview', async () => {
      const router = pageHeadRouterAt('/hilos/demo')
      const view = await mount(sectionHeadInput({ router }))
      try {
        const head = view.root.querySelector('[data-id="hilos-page-head"]')
        expect(head).not.toBeNull()
        const before = view.root.querySelectorAll(
          '[data-id="hilos-page-tabs"] [aria-current]',
        )
        expect(before).toHaveLength(1)
        expect(before[0].getAttribute('data-id')).toBe(
          'hilos-page-tab-demo_section',
        )
        expect(before[0].getAttribute('aria-current')).toBe('page')
        expect(router.currentPath.get()).toBe('/hilos/demo')

        await view.update({ page: 'demo_section_gaps' })

        expect(view.root.querySelector('[data-id="hilos-page-head"]')).toBe(
          head,
        )
        expect(router.currentPath.get()).toBe('/hilos/demo')
        const overview = view.root.querySelector(
          '[data-id="hilos-page-tab-demo_section"]',
        )
        expect(overview?.tagName).toBe('A')
        expect(overview?.getAttribute('href')).toBe('/hilos/demo')
        expect(overview?.hasAttribute('aria-current')).toBe(false)
        const current = view.root.querySelectorAll(
          '[data-id="hilos-page-tabs"] [aria-current]',
        )
        expect(current).toHaveLength(1)
        expect(current[0].getAttribute('data-id')).toBe(
          'hilos-page-tab-demo_section_gaps',
        )
        expect(current[0].getAttribute('aria-current')).toBe('page')
      } finally {
        await view.unmount()
      }
    })

    it('H15 hides singleton breadcrumb and row while keeping the one owner heading', async () => {
      const view = await mount(
        sectionHeadInput({
          identity: {
            ...SECTION,
            breadcrumb: [{ page: 'demo_section', label: 'Demo section' }],
            pageTabs: [{ page: 'demo_section', label: 'Overview' }],
          },
        }),
      )
      try {
        expect(
          view.root.querySelector('[data-id="hilos-breadcrumb"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-page-tabs"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-page-head-block"]'),
        ).not.toBeNull()
        const headings = view.root.querySelectorAll('h1')
        expect(headings).toHaveLength(1)
        expect(headings[0].textContent?.trim()).toBe('Demo section')
      } finally {
        await view.unmount()
      }
    })

    it('H16 hides the zero-result entity row when both routes lack their required code', async () => {
      // Both Overview and Names require code: this input leaves zero, not one.
      const input = entityHeadInput({ params: {} })
      expect(resolvePath('demo_entity', input.params)).toBeUndefined()
      expect(resolvePath('demo_entity_names', input.params)).toBeUndefined()
      const view = await mount(input)
      try {
        expect(
          view.root.querySelector('[data-id="hilos-page-tabs"]'),
        ).toBeNull()
        expect(view.root.querySelector('h1')?.textContent?.trim()).toBe(
          'United States',
        )
      } finally {
        await view.unmount()
      }
    })

    it('H16 counts after filtering an unbuilt page and missing code to a real singleton', async () => {
      const input = sectionHeadInput({
        identity: {
          ...SECTION,
          pageTabs: [
            { page: 'demo_section', label: 'Overview' },
            { page: 'demo_section_unbuilt', label: 'Unbuilt' },
            { page: 'demo_entity_names', label: 'Names' },
          ],
        },
      })
      expect(resolvePath('demo_section', input.params)).toBe('/hilos/demo')
      expect(resolvePath('demo_section_unbuilt', input.params)).toBeUndefined()
      expect(resolvePath('demo_entity_names', input.params)).toBeUndefined()
      const view = await mount(input)
      try {
        expect(
          view.root.querySelector('[data-id="hilos-page-tabs"]'),
        ).toBeNull()
        expect(view.root.querySelector('h1')?.textContent?.trim()).toBe(
          'Demo section',
        )
      } finally {
        await view.unmount()
      }
    })

    it('H17 keeps the answered Overview, Gaps, List order after removing an unbuilt tab', async () => {
      const view = await mount(
        sectionHeadInput({
          identity: {
            ...SECTION,
            pageTabs: [
              { page: 'demo_section', label: 'Overview' },
              { page: 'demo_section_unbuilt', label: 'Unbuilt' },
              { page: 'demo_section_gaps', label: 'Gaps' },
              { page: 'demo_section_list', label: 'List' },
            ],
          },
        }),
      )
      try {
        const links = [
          ...view.root.querySelectorAll('[data-id="hilos-page-tabs"] ul a'),
        ]
        expect(links.map((link) => link.getAttribute('data-id'))).toEqual([
          'hilos-page-tab-demo_section',
          'hilos-page-tab-demo_section_gaps',
          'hilos-page-tab-demo_section_list',
        ])
        expect(links.map((link) => link.textContent?.trim())).toEqual([
          'Overview',
          'Gaps',
          'List',
        ])
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
          '/hilos/demo',
          '/hilos/demo/gaps',
          '/hilos/demo/list',
        ])
      } finally {
        await view.unmount()
      }
    })

    it('H18 resolves the block action by page key with exactly its own params', async () => {
      const resolve = vi.fn(resolvePath)
      const view = await mount(
        entityHeadInput({
          resolvePath: resolve,
          action: {
            page: 'demo_activity',
            label: 'Activity',
            params: { person: '12' },
          },
        }),
      )
      try {
        const action = view.root.querySelector(
          '[data-id="hilos-page-head-action"]',
        )
        expect(action?.tagName).toBe('A')
        expect(action?.getAttribute('href')).toBe('/hilos/activity/12')
        expect(action?.textContent?.trim()).toBe('Activity')
        expect(
          view.root.querySelector(
            '[data-id="hilos-page-head-block"] [data-id="hilos-page-head-action"]',
          ),
        ).toBe(action)
        expect(resolve).toHaveBeenCalledWith('demo_activity', { person: '12' })
        for (const [page, params] of resolve.mock.calls) {
          expect(page).not.toBe('Activity')
          if (page === 'demo_activity') {
            expect(params).toEqual({ person: '12' })
          }
        }
      } finally {
        await view.unmount()
      }
    })

    it('H19 uses the whole current params when the action carries none', async () => {
      const resolve = vi.fn(resolvePath)
      const view = await mount(
        entityHeadInput({
          resolvePath: resolve,
          action: { page: 'demo_entity_names', label: 'Names' },
        }),
      )
      try {
        expect(
          view.root
            .querySelector('[data-id="hilos-page-head-action"]')
            ?.getAttribute('href'),
        ).toBe('/hilos/demo/list/US/names')
        expect(resolve).toHaveBeenCalledWith('demo_entity_names', {
          code: 'US',
        })
      } finally {
        await view.unmount()
      }
    })

    it('H20 omits an action and its Nowhere caption when no route exists', async () => {
      const view = await mount(
        sectionHeadInput({
          action: { page: 'demo_missing', label: 'Nowhere' },
        }),
      )
      try {
        expect(
          view.root.querySelector('[data-id="hilos-page-head-action"]'),
        ).toBeNull()
        expect(view.root.textContent).not.toContain('Nowhere')
      } finally {
        await view.unmount()
      }
    })

    it('H20 keeps explicit empty action params empty despite a current person with a valid address', async () => {
      const params = { ...ENTITY_PARAMS, person: '12' }
      expect(resolvePath('demo_activity', params)).toBe('/hilos/activity/12')
      const view = await mount(
        entityHeadInput({
          params,
          action: { page: 'demo_activity', label: 'Activity', params: {} },
        }),
      )
      try {
        expect(
          view.root.querySelector('[data-id="hilos-page-head-action"]'),
        ).toBeNull()
        expect(view.root.textContent).not.toContain('Activity')
      } finally {
        await view.unmount()
      }
    })
  })
}
