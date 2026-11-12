// The Dashboard cards, head and native top seam on all three real SDKs.
import { HilosPages } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  FRAMEWORK_SECTIONS,
  IDENTITY,
  PROJECT_SECTION,
  RICH_IDENTITY,
  SECTIONS,
  dashboardRouter,
  frozenDashboardIdentity,
  type MountDashboard,
} from './dashboardFixtures.js'

type DashboardRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Register the existing semantic union without using another SDK as oracle. */
export function registerDashboardCases(
  sdk: string,
  runner: DashboardRunner,
  mount: MountDashboard,
): void {
  const { describe, it, expect } = runner
  describe(`HilosDashboardPage on ${sdk}`, () => {
    for (const [state, identity] of [
      ['pending head', undefined],
      ['answered head', IDENTITY],
    ] as const) {
      it(`DB-C1 renders answered cards with ${state}`, async () => {
        const { router } = dashboardRouter(identity, SECTIONS)
        const view = await mount({ router })
        try {
          expect(part(view.root, 'dashboard-view')).not.toBeNull()
          for (const page of [HilosPages.USERS, 'admin_users']) {
            const card = part(view.root, `dashboard-card-${page}`)
            expect(card?.tagName).toBe('A')
            expect(card?.getAttribute('href')).toMatch(/^\/hilos/)
            expect(card?.textContent).toContain('Users')
          }
        } finally {
          await view.unmount()
        }
      })
    }

    it('DB-C2 keeps incoming group order and both Users cards with distinct keys', async () => {
      const { router } = dashboardRouter(IDENTITY, SECTIONS)
      const view = await mount({ router })
      try {
        expect(
          [...view.root.querySelectorAll('h2')].map((h) =>
            h.textContent?.trim(),
          ),
        ).toEqual(['Access & identity', 'Chat administration'])
        const framework = part(view.root, `dashboard-card-${HilosPages.USERS}`)
        const project = part(view.root, 'dashboard-card-admin_users')
        expect(framework?.tagName).toBe('A')
        expect(project?.tagName).toBe('A')
        expect(framework).not.toBe(project)
        expect(framework?.textContent).toContain('Users')
        expect(project?.textContent).toContain('Users')
      } finally {
        await view.unmount()
      }
    })

    it('DB-C3 leaves out an unresolved card from a mixed group', async () => {
      const { router } = dashboardRouter(IDENTITY, [
        {
          title: 'Access & identity',
          description: '',
          items: [
            { page: 'unrouted', label: 'Nowhere', lead: '', icon: null },
            {
              page: HilosPages.USERS,
              label: 'Users',
              lead: '',
              icon: 'bi-people',
            },
          ],
        },
      ])
      const view = await mount({ router })
      try {
        expect(view.root.querySelectorAll('a')).toHaveLength(1)
        expect(
          part(view.root, `dashboard-card-${HilosPages.USERS}`)?.tagName,
        ).toBe('A')
        expect(part(view.root, 'dashboard-card-unrouted')).toBeNull()
        expect(view.root.textContent).not.toContain('Nowhere')
      } finally {
        await view.unmount()
      }
    })

    it('DB-C4 leaves out a group with no resolved cards and keeps surviving order', async () => {
      const { router } = dashboardRouter(IDENTITY, [
        ...SECTIONS,
        {
          title: 'Hidden',
          description: 'No destination',
          items: [{ page: 'unrouted', label: 'Nowhere', lead: '', icon: null }],
        },
      ])
      const view = await mount({ router })
      try {
        expect(
          [...view.root.querySelectorAll('h2')].map((h) =>
            h.textContent?.trim(),
          ),
        ).toEqual(['Access & identity', 'Chat administration'])
        expect(view.root.textContent).not.toContain('Hidden')
        expect(view.root.textContent).not.toContain('No destination')
      } finally {
        await view.unmount()
      }
    })

    it('DB-S1 keeps the card skeleton when the head has answered but sections are pending', async () => {
      const { router } = dashboardRouter(IDENTITY, undefined)
      const view = await mount({ router })
      try {
        expect(part(view.root, 'dashboard-skeleton')).not.toBeNull()
        expect(view.root.querySelectorAll('a')).toHaveLength(0)
        expect(
          part(view.root, 'hilos-page-head-title')?.textContent?.trim(),
        ).toBe('Hilos')
        expect(part(view.root, 'hilos-page-head-skeleton')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('DB-S2 draws the shared head and card skeletons before either answer', async () => {
      const { router } = dashboardRouter(undefined, undefined)
      const view = await mount({ router })
      try {
        expect(part(view.root, 'hilos-page-head-skeleton')).not.toBeNull()
        expect(
          part(view.root, 'hilos-page-head-skeleton')?.getAttribute(
            'aria-hidden',
          ),
        ).toBe('true')
        expect(part(view.root, 'dashboard-skeleton')).not.toBeNull()
        expect(view.root.querySelectorAll('a')).toHaveLength(0)
        expect(view.root.querySelector('h1')).toBeNull()
        expect(part(view.root, 'hilos-page-head-title')).toBeNull()
        expect(part(view.root, 'hilos-page-head-block')).toBeNull()
        expect(view.root.querySelector('i')).toBeNull()
        expect(part(view.root, 'dashboard-title-skeleton')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('DB-H1 transitions to the answered head on the same mount without navigation or legacy title', async () => {
      const context = dashboardRouter(undefined, undefined)
      const view = await mount({ router: context.router })
      try {
        const root = view.root
        const dashboard = part(root, 'dashboard-view')
        expect(dashboard).not.toBeNull()
        expect(part(root, 'hilos-page-head-skeleton')).not.toBeNull()
        expect(root.querySelector('h1')).toBeNull()

        await view.flush(() => {
          context.pageIdentity.set(IDENTITY)
          context.dashboardSections.set(SECTIONS)
        })

        expect(part(root, 'dashboard-view')).toBe(dashboard)
        expect(part(root, 'hilos-page-head-skeleton')).toBeNull()
        expect(root.querySelectorAll('h1')).toHaveLength(1)
        expect(part(root, 'hilos-page-head-title')?.textContent?.trim()).toBe(
          'Hilos',
        )
        const block = part(root, 'hilos-page-head-block')
        expect(block?.textContent).toContain(
          'Administrative sections for the project.',
        )
        const icon = block?.querySelector('i')
        expect(icon).not.toBeNull()
        expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
        for (const id of [
          'hilos-breadcrumb',
          'hilos-page-tabs',
          'hilos-page-head-action',
          'dashboard-title',
          'dashboard-title-skeleton',
        ])
          expect(part(root, id), id).toBeNull()
        expect(root.querySelector('nav')).toBeNull()
        expect(root.querySelector('[role="tab"]')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('DB-H2 ignores rich input navigation in its local head presentation', async () => {
      const { router } = dashboardRouter(RICH_IDENTITY, SECTIONS)
      const view = await mount({ router })
      try {
        expect(part(view.root, 'hilos-breadcrumb')).toBeNull()
        expect(part(view.root, 'hilos-page-tabs')).toBeNull()
        expect(view.root.querySelector('nav')).toBeNull()
        expect(view.root.querySelectorAll('[role="tab"]')).toHaveLength(0)
        expect(
          part(view.root, 'hilos-page-head-title')?.textContent?.trim(),
        ).toBe('Hilos')
      } finally {
        await view.unmount()
      }
    })

    it('DB-H3 updates name and lead, removes an empty lead without fallback, and resets only identity', async () => {
      const context = dashboardRouter(IDENTITY, SECTIONS)
      const view = await mount({ router: context.router })
      try {
        const root = view.root
        const dashboard = part(root, 'dashboard-view')
        expect(part(root, 'hilos-page-head-title')?.textContent?.trim()).toBe(
          'Hilos',
        )
        expect(part(root, 'hilos-page-head-block')?.textContent).toContain(
          'Administrative sections for the project.',
        )

        await view.flush(() =>
          context.pageIdentity.set({
            label: 'Custom Admin',
            lead: 'Custom lead line.',
            breadcrumb: [],
            children: [],
          }),
        )
        expect(part(root, 'hilos-page-head-title')?.textContent?.trim()).toBe(
          'Custom Admin',
        )
        expect(part(root, 'hilos-page-head-block')?.textContent).toContain(
          'Custom lead line.',
        )

        await view.flush(() =>
          context.pageIdentity.set({
            label: 'Custom Admin',
            lead: '',
            breadcrumb: [],
            children: [],
          }),
        )
        expect(part(root, 'hilos-page-head-title')?.textContent?.trim()).toBe(
          'Custom Admin',
        )
        const block = part(root, 'hilos-page-head-block')
        expect(block).not.toBeNull()
        expect(block?.querySelector('p')).toBeNull()
        expect(root.textContent).not.toContain('Administrative sections')

        await view.flush(() => context.pageIdentity.set(undefined))
        expect(part(root, 'dashboard-view')).toBe(dashboard)
        expect(part(root, 'hilos-page-head-skeleton')).not.toBeNull()
        expect(root.querySelector('h1')).toBeNull()
        expect(context.dashboardSections.get()).toBe(SECTIONS)
        expect(part(root, 'dashboard-skeleton')).toBeNull()
        expect(part(root, `dashboard-card-${HilosPages.USERS}`)?.tagName).toBe(
          'A',
        )
      } finally {
        await view.unmount()
      }
    })

    it('DB-H4 does not mutate the frozen identity or its navigation arrays', async () => {
      const identity = frozenDashboardIdentity()
      const { router } = dashboardRouter(identity, SECTIONS)
      const view = await mount({ router })
      try {
        const inRouter = router.pageIdentity.get()
        expect(inRouter).toBe(identity)
        expect(inRouter?.pageHeadOwner).toBeUndefined()
        expect(inRouter?.breadcrumb).toHaveLength(1)
        expect(inRouter?.children).toHaveLength(1)
        expect(inRouter?.pageTabs).toHaveLength(1)
        for (const value of [
          identity,
          identity.breadcrumb,
          identity.children,
          identity.pageTabs,
        ])
          expect(Object.isFrozen(value)).toBe(true)
      } finally {
        await view.unmount()
      }
    })

    for (const [name, sections, headings] of [
      [
        'two groups',
        [...FRAMEWORK_SECTIONS, PROJECT_SECTION],
        ['Access & identity', 'Project Area'],
      ],
      [
        'three groups',
        [...SECTIONS, PROJECT_SECTION],
        ['Access & identity', 'Chat administration', 'Project Area'],
      ],
    ] as const) {
      it(`DB-T1 keeps native top content after the head and before cards with ${name}`, async () => {
        const { router } = dashboardRouter(IDENTITY, [...sections])
        const view = await mount({
          router,
          top: { dataId: 'project-top-slot', text: 'Custom Top Content' },
        })
        try {
          const root = view.root
          const head = part(root, 'hilos-page-head')
          const top = part(root, 'project-top-slot')
          const framework = part(root, `dashboard-card-${HilosPages.USERS}`)
          const project = part(root, 'dashboard-card-project-page')
          for (const element of [head, top, framework, project])
            expect(element).not.toBeNull()
          if (
            head === null ||
            top === null ||
            framework === null ||
            project === null
          )
            throw new Error(
              'The real dashboard renders its head, top content and both cards.',
            )
          expect(head.compareDocumentPosition(top)).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
          )
          expect(top.compareDocumentPosition(framework)).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
          )
          expect(framework.compareDocumentPosition(project)).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
          )
          expect(
            [...root.querySelectorAll('h2')].map((h) => h.textContent?.trim()),
          ).toEqual(headings)
          expect(top.textContent?.trim()).toBe('Custom Top Content')
        } finally {
          await view.unmount()
        }
      })
    }
  })
}
