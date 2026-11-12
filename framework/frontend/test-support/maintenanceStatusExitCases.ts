import type * as Vitest from 'vitest'
import {
  FROZEN,
  FROZEN_WITHOUT_COPY,
  MINTED,
  REJECTED,
  VERIFYING,
  restoreFrame,
  withMaintenance,
  type MountMaintenance,
} from './maintenanceFixtures.js'

type MaintenanceRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

/** One native status exit through every freeze, surface and restore snapshot. */
export function registerMaintenanceStatusExitCases(
  sdk: string,
  runner: MaintenanceRunner,
  mount: MountMaintenance,
): void {
  const { describe, it, expect, vi } = runner

  function assertLink(root: HTMLElement, presented: string[]): void {
    const links = root.querySelectorAll('a')
    expect(links).toHaveLength(1)
    const link = links[0]!
    expect(link.textContent!.trim()).toBe('Service status')
    expect(link.getAttribute('href')).toBe('/status')
    expect(link.getAttribute('role')).toBeNull()
    expect(link.getAttribute('aria-disabled')).toBeNull()
    expect(link.closest('fieldset, [inert], form')).toBeNull()

    let prevented: boolean | undefined
    const preventNavigation = (event: Event): void => {
      prevented = event.defaultPrevented
      event.preventDefault()
    }
    try {
      link.addEventListener('click', preventNavigation, { once: true })
      link.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
      )
      expect(prevented).toBe(false)
    } finally {
      link.removeEventListener('click', preventNavigation)
    }
    expect(presented).toEqual([])
  }

  describe(`HilosMaintenance Service status on ${sdk}`, () => {
    it.each([
      { name: 'FROZEN', status: FROZEN, publicForm: false, adminForm: false },
      {
        name: 'FROZEN_WITHOUT_COPY',
        status: FROZEN_WITHOUT_COPY,
        publicForm: false,
        adminForm: false,
      },
      {
        name: 'VERIFYING',
        status: VERIFYING,
        publicForm: false,
        adminForm: false,
      },
      { name: 'MINTED', status: MINTED, publicForm: false, adminForm: true },
      {
        name: 'REJECTED',
        status: REJECTED,
        publicForm: false,
        adminForm: true,
      },
    ])('M01 keeps the native status exit for $name', async (row) => {
      for (const adminSurface of [false, true]) {
        await withMaintenance(
          vi,
          mount,
          row.status,
          adminSurface,
          async (view, feed) => {
            assertLink(view.root, feed.presented)
            expect(
              view.root.querySelector('a i')?.getAttribute('aria-hidden'),
            ).toBe('true')
            for (const frame of [
              restoreFrame(),
              restoreFrame({ running: false, outcome: 'success' }),
              restoreFrame({
                running: false,
                outcome: 'error',
                failureReason: 'Restore refused',
              }),
            ]) {
              await view.flush(() => feed.pushRestore(frame))
              expect(
                view.root.querySelector('[data-id="maintenance-restore"]') !==
                  null,
              ).toBe(true)
              assertLink(view.root, feed.presented)
            }
            expect(
              view.root.querySelector('[data-id="maintenance-pass-form"]') !==
                null,
            ).toBe(adminSurface ? row.adminForm : row.publicForm)
          },
          { initialState: 'disconnected' },
        )
      }
    })
  })
}
