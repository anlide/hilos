// One legacy-shell and child-context scenario text for all three real SDKs.
import { createAppPageRouter, HilosPages } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { readTextWithoutAriaHidden } from '../test/breadcrumb.js'
import {
  adminPageInput,
  adminPageRouter,
  LEAF_IDENTITY,
  SECTION_IDENTITY,
  type MountAdminPage,
} from './adminPageFixtures.js'

type AdminPageRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Register the legacy shell and the independent child selection context. */
export function registerAdminPageLegacyCases(
  sdk: string,
  runner: AdminPageRunner,
  mount: MountAdminPage,
): void {
  const { describe, it, expect } = runner
  describe(`HilosAdminPage legacy on ${sdk}`, () => {
    it('AP-L1 renders the answered section heading, breadcrumb and real child anchor', async () => {
      const view = await mount(adminPageInput(SECTION_IDENTITY))
      try {
        expect(
          view.root
            .querySelector('[data-id="hilos-admin-title"]')
            ?.textContent?.trim(),
        ).toBe('Internationalization')
        const breadcrumb = view.root.querySelector(
          '[data-id="hilos-breadcrumb"]',
        )
        expect(breadcrumb).not.toBeNull()
        expect(
          [...(breadcrumb?.querySelectorAll('li') ?? [])].map((item) =>
            readTextWithoutAriaHidden(item),
          ),
        ).toEqual(['Hilos', 'Internationalization'])
        expect(breadcrumb?.querySelector('a')?.getAttribute('href')).toBe(
          '/hilos',
        )
        expect(
          view.root.querySelector('[data-id="hilos-admin-children"]'),
        ).not.toBeNull()
        const child = view.root.querySelector(
          `[data-id="hilos-admin-child-${HilosPages.I18N_LANGUAGES}"]`,
        )
        expect(child?.tagName).toBe('A')
        expect(child?.getAttribute('href')).toBe('/hilos/i18n/languages')
      } finally {
        await view.unmount()
      }
    })

    it('AP-L2 renders the empty stub for a bare leaf without child cards', async () => {
      const view = await mount(
        adminPageInput(LEAF_IDENTITY, { page: HilosPages.I18N_LANGUAGE }),
      )
      try {
        expect(
          view.root.querySelector('[data-id="hilos-admin-empty"]'),
        ).not.toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-admin-children"]'),
        ).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-L3 keeps only the legacy skeleton before identity, with no head kind', async () => {
      const view = await mount(adminPageInput(undefined))
      try {
        expect(
          view.root.querySelector('[data-id="hilos-admin-title-skeleton"]'),
        ).not.toBeNull()
        for (const absent of [
          'hilos-admin-title',
          'hilos-breadcrumb',
          'hilos-admin-empty',
          'hilos-page-head',
          'hilos-page-head-skeleton',
        ]) {
          expect(
            view.root.querySelector(`[data-id="${absent}"]`),
            absent,
          ).toBeNull()
        }
        expect(view.root.querySelector('h1')).toBeNull()
        expect(view.root.textContent).not.toContain(HilosPages.I18N)
      } finally {
        await view.unmount()
      }
    })

    it('AP-L4 keeps child cards before the section body in DOM order', async () => {
      const view = await mount(
        adminPageInput(SECTION_IDENTITY, {
          body: { dataId: 'section-body', text: 'Figures of this section' },
        }),
      )
      try {
        const cards = view.root.querySelector(
          '[data-id="hilos-admin-children"]',
        )
        const body = view.root.querySelector('[data-id="section-body"]')
        expect(cards).not.toBeNull()
        expect(body).not.toBeNull()
        if (cards === null || body === null)
          throw new Error('The section draws both cards and caller content.')
        expect(
          cards.compareDocumentPosition(body) &
            Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy()
      } finally {
        await view.unmount()
      }
    })

    it('AP-L5 draws a leaf body without a stub or child cards', async () => {
      const view = await mount(
        adminPageInput(LEAF_IDENTITY, {
          page: HilosPages.I18N_LANGUAGE,
          body: { dataId: 'leaf-body', text: 'One language' },
        }),
      )
      try {
        expect(view.root.querySelector('[data-id="leaf-body"]')).not.toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-admin-empty"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-admin-children"]'),
        ).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    // The original React and Angular examples exercise distinct breadcrumb and
    // child keys. Both run on every SDK; the first resolver also needs section.
    it.each([
      {
        name: 'section merge',
        routeNode: 'n1',
        section: 'daemon',
        crumbPage: HilosPages.DASHBOARD,
        crumbLabel: 'Hilos',
        childPage: HilosPages.DAEMON_WORKERS,
        childLabel: 'Workers',
        selections: ['n2', 'n3'],
        hrefs: [
          `/hilos/daemon/n2/${HilosPages.DAEMON_WORKERS}`,
          `/hilos/daemon/n3/${HilosPages.DAEMON_WORKERS}`,
        ],
        crumbHref: `/hilos/daemon/n1/${HilosPages.DASHBOARD}`,
      },
      {
        name: 'workers ancestry and agents child',
        routeNode: 'route-node',
        section: undefined,
        crumbPage: HilosPages.DAEMON_WORKERS,
        crumbLabel: 'Workers',
        childPage: HilosPages.DAEMON_AGENTS,
        childLabel: 'Agents',
        selections: ['first', 'second'],
        hrefs: [
          `/hilos/first/${HilosPages.DAEMON_AGENTS}`,
          `/hilos/second/${HilosPages.DAEMON_AGENTS}`,
        ],
        crumbHref: `/hilos/route-node/${HilosPages.DAEMON_WORKERS}`,
      },
    ])(
      'AP-P1/P2 merges and updates child params without navigating: $name',
      async (example) => {
        const params: Record<string, string> =
          example.section === undefined
            ? { nodeId: example.routeNode }
            : { nodeId: example.routeNode, section: example.section }
        const navigation = adminPageRouter(
          {
            label: 'Daemon',
            lead: '',
            breadcrumb: [
              { page: example.crumbPage, label: example.crumbLabel },
              { page: HilosPages.DAEMON, label: 'Daemon' },
            ],
            children: [
              {
                page: example.childPage,
                label: example.childLabel,
                lead: '',
                icon: null,
              },
            ],
          },
          {
            route: {
              page: HilosPages.DAEMON,
              params: { ...params },
              admin: true,
            },
            path: '/hilos/daemon',
            resolvePath:
              example.section === undefined
                ? (page, routeParams = {}) =>
                    `/hilos/${routeParams.nodeId}/${page}`
                : (page, routeParams = {}) =>
                    `/hilos/${routeParams.section}/${routeParams.nodeId}/${page}`,
          },
        )
        const input = { page: HilosPages.DAEMON, router: navigation.router }
        const view = await mount({
          ...input,
          childParams: { nodeId: example.selections[0] },
        })
        try {
          const breadcrumb = () =>
            view.root.querySelector('[data-id="hilos-breadcrumb"]')
          const initialBreadcrumb = breadcrumb()?.innerHTML
          expect(breadcrumb()?.querySelector('a')?.getAttribute('href')).toBe(
            example.crumbHref,
          )
          for (const [index, nodeId] of example.selections.entries()) {
            if (index > 0)
              await view.update({ ...input, childParams: { nodeId } })
            expect(
              view.root
                .querySelector(
                  `[data-id="hilos-admin-child-${example.childPage}"]`,
                )
                ?.getAttribute('href'),
            ).toBe(example.hrefs[index])
            expect(breadcrumb()?.innerHTML).toBe(initialBreadcrumb)
            expect(navigation.currentRoute.get().params).toEqual(params)
            expect(navigation.currentPath.get()).toBe('/hilos/daemon')
          }
        } finally {
          await view.unmount()
        }
      },
    )

    it('AP-P3 resolves, encodes, matches and resets child context with the real router', async () => {
      const matcher = createAppPageRouter({})
      const navigation = adminPageRouter(
        {
          label: 'Daemon',
          lead: '',
          breadcrumb: [
            { page: HilosPages.DAEMON_WORKERS, label: 'Route node' },
            { page: HilosPages.DAEMON, label: 'Daemon' },
          ],
          children: [
            {
              page: HilosPages.DAEMON_WORKERS,
              label: 'Workers',
              lead: '',
              icon: null,
            },
          ],
        },
        {
          route: {
            page: HilosPages.DAEMON,
            params: { nodeId: 'route-node' },
            admin: true,
          },
          path: '/hilos/daemon',
          resolvePath: matcher.path,
        },
      )
      const input = { page: HilosPages.DAEMON, router: navigation.router }
      const view = await mount(input)
      try {
        const child = () =>
          view.root.querySelector(
            `[data-id="hilos-admin-child-${HilosPages.DAEMON_WORKERS}"]`,
          )
        expect(child()?.getAttribute('href')).toBe(
          '/hilos/daemon/node/route-node/workers',
        )
        const initialBreadcrumb = view.root.querySelector(
          '[data-id="hilos-breadcrumb"]',
        )?.innerHTML
        const nodeId = 'node /?#%2F🌍'
        for (const [childParams, href] of [
          [
            { nodeId: 'selected-node' },
            '/hilos/daemon/node/selected-node/workers',
          ],
          [{ nodeId: 'next' }, '/hilos/daemon/node/next/workers'],
          [
            { nodeId },
            `/hilos/daemon/node/${encodeURIComponent(nodeId)}/workers`,
          ],
          [undefined, '/hilos/daemon/node/route-node/workers'],
        ] as const) {
          await view.update({ ...input, childParams })
          const actualHref = child()?.getAttribute('href')
          expect(actualHref).toBe(href)
          if (childParams?.nodeId === nodeId)
            expect(matcher.match(actualHref!).params).toEqual({ nodeId })
          const breadcrumb = view.root.querySelector(
            '[data-id="hilos-breadcrumb"]',
          )
          expect(breadcrumb?.querySelector('a')?.getAttribute('href')).toBe(
            '/hilos/daemon/node/route-node/workers',
          )
          expect(breadcrumb?.innerHTML).toBe(initialBreadcrumb)
          expect(navigation.currentRoute.get().params).toEqual({
            nodeId: 'route-node',
          })
          expect(navigation.currentPath.get()).toBe('/hilos/daemon')
        }
      } finally {
        await view.unmount()
      }
    })
  })
}
