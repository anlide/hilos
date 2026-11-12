// One wrapper-forwarding scenario text for the three real SDK components.
import { HilosPages } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  ADMIN_PAGE_HEAD_CONTENT,
  OWNER_IDENTITY,
  SECTION_IDENTITY,
  adminPageInput,
  adminPageRouter,
  type MountAdminPage,
} from './adminPageFixtures.js'

type AdminPageRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Register AdminPage forwarding, leaving standalone Head algorithms to Head. */
export function registerAdminPageHeadCases(
  sdk: string,
  runner: AdminPageRunner,
  mount: MountAdminPage,
): void {
  const { describe, it, expect } = runner
  describe(`HilosAdminPage head on ${sdk}`, () => {
    it('AP-H1 adopts the answered owner instead of the legacy title and cards', async () => {
      const view = await mount(adminPageInput(OWNER_IDENTITY))
      try {
        const root = view.root
        expect(part(root, 'hilos-page-head')).not.toBeNull()
        expect(part(root, 'hilos-admin-title')).toBeNull()
        expect(part(root, 'hilos-admin-children')).toBeNull()
        const headings = root.querySelectorAll('h1')
        expect(headings).toHaveLength(1)
        expect(headings[0].getAttribute('data-id')).toBe(
          'hilos-page-head-title',
        )
        expect(headings[0].textContent?.trim()).toBe('Internationalization')
        const block = part(root, 'hilos-page-head-block')
        expect(block).not.toBeNull()
        expect(block?.querySelector('[aria-hidden="true"] i')).not.toBeNull()
        expect(
          root.textContent?.split(
            'Languages, countries, and translation screens.',
          ),
        ).toHaveLength(2)
        expect(
          part(root, `hilos-page-tab-${HilosPages.I18N}`)?.getAttribute(
            'aria-current',
          ),
        ).toBe('page')
        expect(
          part(root, `hilos-page-tab-${HilosPages.I18N}`)?.textContent?.trim(),
        ).toBe('Overview')
        expect(part(root, 'hilos-breadcrumb')?.tagName).toBe('NAV')
        expect(part(root, 'hilos-breadcrumb')?.getAttribute('aria-label')).toBe(
          'Breadcrumb',
        )
        expect(part(root, 'hilos-page-tabs')?.tagName).toBe('NAV')
        expect(part(root, 'hilos-page-tabs')?.getAttribute('aria-label')).toBe(
          'Page sections',
        )
        expect(part(root, 'hilos-admin-empty')).not.toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-H2 forwards caller content, kind, navigation labels and the action', async () => {
      const navigation = adminPageRouter(OWNER_IDENTITY, {
        resolvePath: (page) => `/hilos/${page}`,
      })
      const view = await mount(
        adminPageInput(OWNER_IDENTITY, {
          page: HilosPages.I18N_LANGUAGES,
          router: navigation.router,
          headKind: 'entity',
          breadcrumbLabel: 'Path',
          tabsLabel: 'Section pages',
          headAction: { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
          headContent: ADMIN_PAGE_HEAD_CONTENT,
          body: { dataId: 'page-body', text: 'The body' },
        }),
      )
      try {
        const root = view.root
        const block = part(root, 'hilos-page-head-block')
        expect(block).not.toBeNull()
        if (block === null)
          throw new Error('The real head owns the caller content.')
        for (const [id, text] of [
          ['probe-tile', 'IN'],
          ['probe-code', 'i18n'],
          ['probe-badge', 'Own'],
          ['probe-description', 'One line'],
        ]) {
          expect(part(block, id)?.textContent?.trim(), id).toBe(text)
        }
        expect(block.querySelector('[aria-hidden="true"] i')).toBeNull()
        expect(block.textContent).not.toContain(
          'Languages, countries, and translation screens.',
        )
        expect(part(root, 'hilos-breadcrumb')?.getAttribute('aria-label')).toBe(
          'Path',
        )
        expect(part(root, 'hilos-page-tabs')?.getAttribute('aria-label')).toBe(
          'Section pages',
        )
        expect(
          part(
            root,
            `hilos-page-tab-${HilosPages.I18N_LANGUAGES}`,
          )?.getAttribute('aria-current'),
        ).toBe('page')
        const action = part(root, 'hilos-page-head-action')
        expect(action?.tagName).toBe('A')
        expect(action?.textContent?.trim()).toBe('Countries')
        expect(action?.getAttribute('href')).toBe(
          `/hilos/${HilosPages.I18N_COUNTRIES}`,
        )
        const head = part(root, 'hilos-page-head')
        const body = part(root, 'page-body')
        expect(body?.textContent?.trim()).toBe('The body')
        if (head === null || body === null)
          throw new Error('The shell draws both head and body.')
        expect(
          head.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy()
        expect(part(root, 'hilos-admin-empty')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-H3 routes the title class to the shared h1 only', async () => {
      const navigation = adminPageRouter(OWNER_IDENTITY)
      const view = await mount(
        adminPageInput(OWNER_IDENTITY, {
          router: navigation.router,
          headKind: 'section',
          headTitleClass: 'head-title-extra',
        }),
      )
      try {
        const shared = part(view.root, 'hilos-page-head-title')
        expect(shared?.tagName).toBe('H1')
        expect(shared?.classList.contains('head-title-extra')).toBe(true)
        await view.flush(() => navigation.pageIdentity.set(SECTION_IDENTITY))
        expect(part(view.root, 'hilos-page-head-title')).toBeNull()
        const legacy = part(view.root, 'hilos-admin-title')
        expect(legacy?.tagName).toBe('H1')
        expect(legacy?.classList.contains('head-title-extra')).toBe(false)
      } finally {
        await view.unmount()
      }
    })

    it('AP-H5 keeps the legacy answer despite head kind, action and caller parts', async () => {
      const view = await mount(
        adminPageInput(SECTION_IDENTITY, {
          headKind: 'entity',
          breadcrumbLabel: 'Path',
          tabsLabel: 'Section pages',
          headAction: { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
          headContent: ADMIN_PAGE_HEAD_CONTENT,
          body: { dataId: 'page-body', text: 'The body' },
        }),
      )
      try {
        const root = view.root
        for (const id of [
          'hilos-page-head',
          'hilos-page-head-action',
          'hilos-page-tabs',
          'probe-tile',
          'probe-code',
          'probe-badge',
          'probe-description',
        ])
          expect(part(root, id), id).toBeNull()
        expect(part(root, 'hilos-admin-title')?.textContent?.trim()).toBe(
          'Internationalization',
        )
        expect(part(root, 'hilos-admin-children')).not.toBeNull()
        expect(
          part(root, `hilos-admin-child-${HilosPages.I18N_LANGUAGES}`)?.tagName,
        ).toBe('A')
        expect(part(root, 'page-body')?.textContent?.trim()).toBe('The body')
        expect(part(root, 'hilos-breadcrumb')).not.toBeNull()
        expect(
          part(root, 'hilos-breadcrumb')?.getAttribute('aria-label'),
        ).not.toBe('Path')
      } finally {
        await view.unmount()
      }
    })

    it('AP-H6/H7 moves the same shell from silent custom skeleton to owner to legacy', async () => {
      const navigation = adminPageRouter(undefined)
      const view = await mount(
        adminPageInput(undefined, {
          router: navigation.router,
          headKind: 'entity',
          headAction: { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
          headContent: {
            ...ADMIN_PAGE_HEAD_CONTENT,
            skeletonVisual: { dataId: 'probe-skeleton-visual', text: '' },
          },
        }),
      )
      try {
        const root = view.root
        const shell = part(root, 'hilos-admin-page')
        expect(shell).not.toBeNull()
        const skeleton = part(root, 'hilos-page-head-skeleton')
        expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
        expect(skeleton).not.toBeNull()
        if (skeleton === null)
          throw new Error('The unanswered head draws its skeleton.')
        expect(part(skeleton, 'probe-skeleton-visual')).not.toBeNull()
        for (const id of [
          'hilos-admin-title-skeleton',
          'hilos-admin-empty',
          'hilos-breadcrumb',
          'hilos-page-tabs',
          'hilos-page-head-block',
          'hilos-page-head-action',
          'probe-tile',
          'probe-code',
          'probe-badge',
          'probe-description',
        ])
          expect(part(root, id), id).toBeNull()
        expect(root.querySelector('h1')).toBeNull()
        expect(root.textContent?.trim()).toBe('')

        await view.flush(() => navigation.pageIdentity.set(OWNER_IDENTITY))
        expect(part(root, 'hilos-admin-page')).toBe(shell)
        expect(part(root, 'hilos-page-head-title')?.textContent?.trim()).toBe(
          'Internationalization',
        )
        expect(part(root, 'hilos-page-head-skeleton')).toBeNull()
        expect(part(root, 'probe-skeleton-visual')).toBeNull()
        const block = part(root, 'hilos-page-head-block')
        expect(block).not.toBeNull()
        if (block === null)
          throw new Error('The answered head draws its caller content.')
        for (const id of [
          'probe-tile',
          'probe-code',
          'probe-badge',
          'probe-description',
        ])
          expect(part(block, id), id).not.toBeNull()

        await view.flush(() => navigation.pageIdentity.set(SECTION_IDENTITY))
        expect(part(root, 'hilos-admin-page')).toBe(shell)
        expect(part(root, 'hilos-page-head')).toBeNull()
        expect(part(root, 'hilos-admin-title')?.textContent?.trim()).toBe(
          'Internationalization',
        )
        expect(part(root, 'hilos-admin-children')).not.toBeNull()
        expect(
          part(root, `hilos-admin-child-${HilosPages.I18N_LANGUAGES}`),
        ).not.toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-H7 keeps a decorative loading picture when none is supplied', async () => {
      const view = await mount(
        adminPageInput(undefined, { headKind: 'entity' }),
      )
      try {
        const skeleton = part(view.root, 'hilos-page-head-skeleton')
        expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
        expect(skeleton?.querySelector(':scope > span')).not.toBeNull()
        expect(skeleton?.textContent?.trim()).toBe('')
        expect(part(view.root, 'hilos-page-head-block')).toBeNull()
        expect(view.root.querySelector('h1')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-H9 withdraws native badge and visual content and restores section defaults', async () => {
      const input = adminPageInput(OWNER_IDENTITY, {
        headContent: {
          visual: ADMIN_PAGE_HEAD_CONTENT.visual,
          badges: ADMIN_PAGE_HEAD_CONTENT.badges,
        },
        body: { dataId: 'page-body', text: 'The body' },
      })
      const view = await mount(input)
      try {
        const root = view.root
        const shell = part(root, 'hilos-admin-page')
        const heading = part(root, 'hilos-page-head-title')
        expect(shell).not.toBeNull()
        expect(heading).not.toBeNull()
        for (const stage of ['initial', 'without badge', 'without visual']) {
          if (stage === 'without badge') {
            await view.update({
              ...input,
              headContent: { visual: ADMIN_PAGE_HEAD_CONTENT.visual },
            })
          } else if (stage === 'without visual') {
            await view.update({ ...input, headContent: {} })
          }
          expect(part(root, 'hilos-admin-page')).toBe(shell)
          expect(part(root, 'hilos-page-head-title')).toBe(heading)
          const block = part(root, 'hilos-page-head-block')
          expect(block).not.toBeNull()
          if (block === null)
            throw new Error('The owner block survives native content updates.')
          expect(block.textContent).toContain(
            'Languages, countries, and translation screens.',
          )
          if (stage === 'initial')
            expect(part(block, 'probe-badge')?.textContent?.trim()).toBe('Own')
          else expect(part(root, 'probe-badge')).toBeNull()
          if (stage === 'without visual') {
            expect(part(root, 'probe-tile')).toBeNull()
            expect(block.querySelector('[aria-hidden="true"] i')).not.toBeNull()
          } else {
            expect(part(block, 'probe-tile')?.textContent?.trim()).toBe('IN')
            expect(block.querySelector('[aria-hidden="true"] i')).toBeNull()
          }
        }
      } finally {
        await view.unmount()
      }
    })
  })
}
