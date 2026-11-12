// One deletion-boundary scenario text over the three real AdminPage shells.
import { HilosPages, type HilosRouter } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  ADMIN_PAGE_DELETED,
  adminPageDeletionInput,
  adminPageFocusRequest,
  adminPageRetention,
  adminPageTrapFrom,
} from './adminPageDeletionFixtures.js'
import {
  LEAF_IDENTITY,
  OWNER_IDENTITY,
  type AdminPageView,
  type MountAdminPage,
} from './adminPageFixtures.js'

type AdminPageRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Register live deletion, retained modals and the shell's focus ownership. */
export function registerAdminPageDeletionCases(
  sdk: string,
  runner: AdminPageRunner,
  mount: MountAdminPage,
): void {
  const { describe, it, expect } = runner

  function expectDeleted(root: HTMLElement, gone: string[]): void {
    const section = part(root, 'hilos-admin-page')!
    expect(section.querySelectorAll('[data-id="page-error"]')).toHaveLength(1)
    expect(
      part(section, 'page-error-transition-message')?.textContent?.trim(),
    ).toBe('It was deleted just now.')
    for (const id of gone) expect(part(root, id), id).toBeNull()
    const headings = section.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].textContent?.trim()).toBe('404')
  }

  function expectModals(
    root: HTMLElement,
    probe: HTMLElement,
    provided: string | null,
    modals: { mounts: number },
  ): void {
    expect(part(root, 'modals-probe')).toBe(probe)
    expect(part(root, 'hilos-admin-page')?.contains(probe)).toBe(true)
    expect(modals.mounts).toBe(1)
    expect(probe.textContent).toBe(provided)
    expect(provided).toMatch(/^\S+ false$/)
  }

  describe(`HilosAdminPage deletion on ${sdk}`, () => {
    it('AP-D0 draws a held leaf body without the empty stub', async () => {
      const { input } = adminPageDeletionInput(LEAF_IDENTITY)
      const view = await mount(input)
      try {
        expect(part(view.root, 'opener')?.textContent?.trim()).toBe('Rename')
        expect(part(view.root, 'hilos-admin-empty')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-D1 replaces the legacy head, cards and body with one real error and retains modals', async () => {
      const { input, pageError, modals } = adminPageDeletionInput()
      const view = await mount(input)
      try {
        const probe = part(view.root, 'modals-probe')!
        const provided = probe.textContent
        const heading = part(view.root, 'hilos-admin-title')!
        expect(heading.textContent?.trim()).toBe('Internationalization')
        expect(heading.id).toBeTruthy()
        expect(provided).toBe(`${heading.id} false`)
        expect(part(view.root, 'hilos-breadcrumb')).not.toBeNull()
        expect(part(view.root, 'hilos-admin-children')).not.toBeNull()
        expect(part(view.root, 'opener')).not.toBeNull()
        expectModals(view.root, probe, provided, modals)

        await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))

        expectDeleted(view.root, [
          'hilos-breadcrumb',
          'hilos-admin-title',
          'hilos-admin-children',
          'opener',
        ])
        expectModals(view.root, probe, provided, modals)
      } finally {
        await view.unmount()
      }
    })

    it('AP-D2 replaces the adopted head and recovers it on the same modal mount', async () => {
      const { input, pageError, modals } =
        adminPageDeletionInput(OWNER_IDENTITY)
      const view = await mount(input)
      try {
        const probe = part(view.root, 'modals-probe')!
        const provided = probe.textContent
        expect(part(view.root, 'hilos-page-head')).not.toBeNull()
        expect(part(view.root, 'hilos-breadcrumb')).not.toBeNull()
        expect(part(view.root, 'hilos-page-tabs')).not.toBeNull()
        expect(part(view.root, 'opener')).not.toBeNull()
        expectModals(view.root, probe, provided, modals)

        await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))

        expectDeleted(view.root, [
          'hilos-page-head',
          'hilos-breadcrumb',
          'hilos-page-tabs',
          'hilos-admin-title',
          'opener',
        ])
        expectModals(view.root, probe, provided, modals)

        await view.flush(() => pageError.set(null))

        expect(part(view.root, 'page-error')).toBeNull()
        expect(
          part(view.root, 'hilos-page-head-title')?.textContent?.trim(),
        ).toBe('Internationalization')
        expect(part(view.root, 'opener')).not.toBeNull()
        expectModals(view.root, probe, provided, modals)
      } finally {
        await view.unmount()
      }
    })

    it('AP-D3 recovers the legacy title and body without remounting modals', async () => {
      const { input, pageError, modals } = adminPageDeletionInput()
      const view = await mount(input)
      try {
        const probe = part(view.root, 'modals-probe')!
        const provided = probe.textContent
        await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))
        expect(part(view.root, 'page-error')).not.toBeNull()
        await view.flush(() => pageError.set(null))

        expect(part(view.root, 'page-error')).toBeNull()
        expect(part(view.root, 'hilos-admin-title')?.textContent?.trim()).toBe(
          'Internationalization',
        )
        expect(part(view.root, 'opener')).not.toBeNull()
        expectModals(view.root, probe, provided, modals)
      } finally {
        await view.unmount()
      }
    })

    it('AP-D4 keeps the legacy shell under an error without the opt-in', async () => {
      const { input, pageError, modals } = adminPageDeletionInput(
        undefined,
        false,
      )
      const view = await mount(input)
      try {
        const probe = part(view.root, 'modals-probe')!
        const provided = probe.textContent
        await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))

        expect(part(view.root, 'page-error')).toBeNull()
        expect(part(view.root, 'hilos-admin-title')?.textContent?.trim()).toBe(
          'Internationalization',
        )
        expect(part(view.root, 'opener')).not.toBeNull()
        expectModals(view.root, probe, provided, modals)
      } finally {
        await view.unmount()
      }
    })

    it('AP-D4 reads no pageError when the navigator omits it without the opt-in', async () => {
      const { input } = adminPageDeletionInput(undefined, false)
      // Deliberately absent optional seam in a test navigator, not a router API.
      const router = {
        ...input.router,
        pageError: undefined,
      } as unknown as HilosRouter
      const view = await mount({ ...input, router })
      try {
        expect(part(view.root, 'hilos-admin-title')?.textContent?.trim()).toBe(
          'Internationalization',
        )
        expect(part(view.root, 'opener')).not.toBeNull()
        expect(part(view.root, 'modals-probe')).not.toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('AP-D5 returns to Back to home when deletion removes the trapped opener', async () => {
      const { input, pageError } = adminPageDeletionInput()
      const view = await mount(input)
      let dialog: ReturnType<typeof adminPageTrapFrom> | undefined
      try {
        const opener = part(view.root, 'opener')!
        dialog = adminPageTrapFrom(opener)
        await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))
        dialog.close()

        expect(opener.isConnected).toBe(false)
        const home = part(view.root, 'page-error-home')
        expect(home).not.toBeNull()
        expect(home?.textContent?.trim()).toBe('Back to home')
        expect(document.activeElement).toBe(home)
      } finally {
        dialog?.dispose()
        await view.unmount()
      }
    })

    it('AP-D5 leaves a lost opener to the outer surface without the opt-in', async () => {
      const { input } = adminPageDeletionInput(undefined, false)
      const view = await mount(input)
      let dialog: ReturnType<typeof adminPageTrapFrom> | undefined
      try {
        const opener = part(view.root, 'opener')!
        dialog = adminPageTrapFrom(opener)
        opener.remove()
        dialog.close()
        expect(document.activeElement).toBe(dialog.outer)
      } finally {
        dialog?.dispose()
        await view.unmount()
      }
    })

    it('AP-D6 withdraws the root listener itself before any outer offer is installed', async () => {
      const { input } = adminPageDeletionInput()
      let view: AdminPageView | undefined = await mount(input)
      let section: HTMLElement | undefined
      let dialog: ReturnType<typeof adminPageTrapFrom> | undefined
      try {
        section = part(view.root, 'hilos-admin-page')!
        const opener = part(section, 'opener')!
        expect(adminPageFocusRequest(opener).fallback).toBeTypeOf('function')
        const mounted = view
        view = undefined
        await mounted.unmount()
        // Reuse the captured native nodes even if destruction detached content.
        document.body.append(section)
        section.append(opener)
        expect(adminPageFocusRequest(opener).fallback).toBeUndefined()

        dialog = adminPageTrapFrom(opener)
        opener.remove()
        dialog.close()
        expect(document.activeElement).toBe(dialog.outer)
      } finally {
        dialog?.dispose()
        await view?.unmount()
        section?.remove()
      }
    })

    it('AP-D6 leaves an available old home target to the outer surface after disposal', async () => {
      const { input, pageError } = adminPageDeletionInput()
      let view: AdminPageView | undefined = await mount(input)
      let section: HTMLElement | undefined
      let dialog: ReturnType<typeof adminPageTrapFrom> | undefined
      try {
        const opener = part(view.root, 'opener')!
        await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))
        section = part(view.root, 'hilos-admin-page')!
        const home = part(section, 'page-error-home')!
        expect(home).not.toBeNull()
        const mounted = view
        view = undefined
        await mounted.unmount()
        document.body.append(section)
        section.append(home, opener)
        expect(part(section, 'page-error-home')).toBe(home)
        expect(home.isConnected).toBe(true)

        dialog = adminPageTrapFrom(opener)
        opener.remove()
        dialog.close()
        expect(document.activeElement).toBe(dialog.outer)
      } finally {
        dialog?.dispose()
        await view?.unmount()
        section?.remove()
      }
    })

    it('AP-D7 retains by page only after mounting with the opt-in and releases on disposal', async () => {
      const optedIn = adminPageRetention()
      const { input } = adminPageDeletionInput(undefined, true, optedIn.retain)
      let view: AdminPageView | undefined = await mount(input)
      try {
        expect(optedIn.pages).toEqual([HilosPages.I18N])
        expect(optedIn.shown).toEqual([true])
        expect(optedIn.live.size).toBe(1)
        const mounted = view
        view = undefined
        await mounted.unmount()
        expect(optedIn.live.size).toBe(0)

        const legacy = adminPageRetention()
        const legacyInput = adminPageDeletionInput(
          undefined,
          false,
          legacy.retain,
        )
        view = await mount(legacyInput.input)
        expect(legacy.pages).toEqual([])
        expect(legacy.shown).toEqual([])
        expect(legacy.live.size).toBe(0)
      } finally {
        await view?.unmount()
      }
    })
  })
}
