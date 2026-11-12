// One provider/name-chain scenario text, run by the three native SDK shells.
import {
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  TableViewportController,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  LEAF_IDENTITY,
  OWNER_IDENTITY,
  SECTION_IDENTITY,
  adminPageInput,
  type AdminPageTableRow,
  type AdminPageView,
  type MountAdminPage,
} from './adminPageFixtures.js'
import { adminPageSession } from './adminPageSessionFixtures.js'

type AdminPageRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Register the shell's actual heading and view-mode provider consumers. */
export function registerAdminPageContextCases(
  sdk: string,
  runner: AdminPageRunner,
  mount: MountAdminPage,
): void {
  const { describe, it, expect } = runner
  describe(`HilosAdminPage context on ${sdk}`, () => {
    for (const [branch, identity, titleId] of [
      ['legacy', SECTION_IDENTITY, 'hilos-admin-title'],
      ['shared', OWNER_IDENTITY, 'hilos-page-head-title'],
    ] as const) {
      it(`AP-C1 provides the ${branch} head's one h1 id to its native child`, async () => {
        const view = await mount(
          adminPageInput(identity, { contextProbe: 'heading' }),
        )
        try {
          const headings = view.root.querySelectorAll('h1')
          expect(headings).toHaveLength(1)
          expect(headings[0].getAttribute('data-id')).toBe(titleId)
          expect(headings[0].id).toBeTruthy()
          expect(part(view.root, 'heading-probe')?.textContent).toBe(
            headings[0].id,
          )
        } finally {
          await view.unmount()
        }
      })
    }

    for (const [branch, identity, page, titleId] of [
      ['legacy', LEAF_IDENTITY, HilosPages.I18N_LANGUAGE, 'hilos-admin-title'],
      ['shared', OWNER_IDENTITY, HilosPages.I18N, 'hilos-page-head-title'],
    ] as const) {
      it(`AP-C2 names a real untitled table by the ${branch} page h1`, async () => {
        const controller = new TableViewportController<AdminPageTableRow>({
          resolve: (raw) => ({ name: String(raw.slots.name) }),
          sendViewport: () => {},
          frame: { search: {}, columns: [{ key: 'name', label: 'Name' }] },
        })
        let view: AdminPageView | undefined
        try {
          view = await mount(
            adminPageInput(identity, { page, table: controller }),
          )
          const root = view.root
          const headings = root.querySelectorAll('h1')
          expect(headings).toHaveLength(1)
          expect(headings[0].getAttribute('data-id')).toBe(titleId)
          expect(headings[0].id).toBeTruthy()
          expect(root.querySelectorAll('table')).toHaveLength(1)
          expect(
            root.querySelector('table')?.getAttribute('aria-labelledby'),
          ).toBe(headings[0].id)
          expect(part(root, 'hilos-table-title')).toBeNull()
          expect(root.querySelector('[id^="hilos-table-title-"]')).toBeNull()
        } finally {
          try {
            await view?.unmount()
          } finally {
            controller.dispose()
          }
        }
      })
    }

    for (const [name, user, mode, expected] of [
      ['guest in view mode', null, true, 'true'],
      ['non-admin without view mode', { id: 7, admin: false }, false, 'false'],
      ['admin in view mode', { id: 1, admin: true }, true, 'false'],
    ] as const) {
      it(`AP-C3 provides the viewer verdict for a ${name}`, async () => {
        const session = adminPageSession()
        let view: AdminPageView | undefined
        try {
          session.handshake(user, mode)
          view = await mount(
            adminPageInput(LEAF_IDENTITY, {
              page: HilosPages.I18N_LANGUAGE,
              contextProbe: 'view-mode',
            }),
          )
          expect(part(view.root, 'view-mode-probe')?.textContent).toBe(expected)
          expect(part(view.root, 'look-only-probe')?.textContent).toBe(expected)
        } finally {
          try {
            await view?.unmount()
          } finally {
            session.dispose()
          }
        }
      })
    }

    it('AP-C3 provides the viewer verdict under the shared head too', async () => {
      const session = adminPageSession()
      let view: AdminPageView | undefined
      try {
        session.handshake(null, true)
        view = await mount(
          adminPageInput(OWNER_IDENTITY, { contextProbe: 'view-mode' }),
        )
        expect(part(view.root, 'hilos-page-head')).not.toBeNull()
        expect(part(view.root, 'view-mode-probe')?.textContent).toBe('true')
        expect(part(view.root, 'look-only-probe')?.textContent).toBe('true')
      } finally {
        try {
          await view?.unmount()
        } finally {
          session.dispose()
        }
      }
    })

    it('AP-C4 follows rights granted and revoked on the same mount', async () => {
      const session = adminPageSession()
      let view: AdminPageView | undefined
      try {
        session.handshake({ id: 7, admin: false }, true)
        view = await mount(
          adminPageInput(LEAF_IDENTITY, {
            page: HilosPages.I18N_LANGUAGE,
            contextProbe: 'view-mode',
          }),
        )
        const shell = part(view.root, 'hilos-admin-page')
        const probe = part(view.root, 'view-mode-probe')
        expect(shell).not.toBeNull()
        expect(probe?.textContent).toBe('true')
        expect(part(view.root, 'look-only-probe')?.textContent).toBe('true')

        await view.flush(() => session.handshake({ id: 7, admin: true }, true))
        expect(part(view.root, 'hilos-admin-page')).toBe(shell)
        expect(part(view.root, 'view-mode-probe')).toBe(probe)
        expect(probe?.textContent).toBe('false')
        expect(part(view.root, 'look-only-probe')?.textContent).toBe('false')

        await view.flush(() => session.handshake({ id: 7, admin: false }, true))
        expect(part(view.root, 'hilos-admin-page')).toBe(shell)
        expect(part(view.root, 'view-mode-probe')).toBe(probe)
        expect(probe?.textContent).toBe('true')
        expect(part(view.root, 'look-only-probe')?.textContent).toBe('true')
      } finally {
        try {
          await view?.unmount()
        } finally {
          session.dispose()
        }
      }
    })

    it('AP-C5 describes the native look-only consumer by the view-mode strip', async () => {
      const session = adminPageSession()
      let view: AdminPageView | undefined
      try {
        session.handshake(null, true)
        view = await mount(
          adminPageInput(LEAF_IDENTITY, {
            page: HilosPages.I18N_LANGUAGE,
            contextProbe: 'view-mode',
          }),
        )
        expect(part(view.root, 'look-only-described')?.textContent).toBe(
          HILOS_VIEW_MODE_STRIP_TEXT_ID,
        )
      } finally {
        try {
          await view?.unmount()
        } finally {
          session.dispose()
        }
      }
    })
  })
}
