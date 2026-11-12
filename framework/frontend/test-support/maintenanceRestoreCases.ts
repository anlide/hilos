import type * as Vitest from 'vitest'
import {
  FROZEN,
  restoreFrame,
  withMaintenance,
  type MountMaintenance,
} from './maintenanceFixtures.js'

type MaintenanceRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

function required(root: ParentNode, id: string): HTMLElement {
  const node = part(root, id)
  if (!node) throw new Error(`Missing Maintenance node: ${id}`)
  return node
}

/** Remove template-edge whitespace without changing the restore words. */
function text(node: HTMLElement): string {
  return node.textContent!.trim()
}

/** One addressed-restore projection, run on each SDK's native component. */
export function registerMaintenanceRestoreCases(
  sdk: string,
  runner: MaintenanceRunner,
  mount: MountMaintenance,
): void {
  const { describe, it, expect, vi } = runner
  describe(`HilosMaintenance restore on ${sdk}`, () => {
    it(`M13 [${sdk}] has no panel before an addressed frame`, async () => {
      await withMaintenance(vi, mount, FROZEN, true, async ({ root }) => {
        expect(part(root, 'maintenance-restore')).toBeNull()
      })
    })

    it(`M14 [${sdk}] reports the addressed phase without an outcome`, async () => {
      await withMaintenance(vi, mount, FROZEN, true, async (view, feed) => {
        await view.flush(() => feed.pushRestore(restoreFrame()))
        expect(text(required(view.root, 'maintenance-restore-phase'))).toBe(
          'Restore 2026-08-24-full · importing',
        )
        expect(part(view.root, 'maintenance-restore-outcome')).toBeNull()
      })
    })

    it(`M15 [${sdk}] shows an indeterminate bar and phase caption`, async () => {
      await withMaintenance(vi, mount, FROZEN, true, async (view, feed) => {
        await view.flush(() => feed.pushRestore(restoreFrame()))
        expect(
          required(view.root, 'maintenance-restore-bar').getAttribute(
            'aria-valuenow',
          ),
        ).toBeNull()
        expect(text(required(view.root, 'maintenance-restore-progress'))).toBe(
          'importing',
        )
      })
    })

    it(`M16 [${sdk}] exposes an estimated bar and phase caption`, async () => {
      await withMaintenance(vi, mount, FROZEN, true, async (view, feed) => {
        await view.flush(() =>
          feed.pushRestore(
            restoreFrame({
              estimatedSeconds: 600,
              phaseStartedAt: new Date().toISOString(),
            }),
          ),
        )
        expect(
          required(view.root, 'maintenance-restore-bar').getAttribute(
            'aria-valuenow',
          ),
        ).not.toBeNull()
        const caption = text(
          required(view.root, 'maintenance-restore-progress'),
        )
        expect(caption).toContain('importing')
        expect(caption).toContain('%')
      })
    })

    it(`M17 [${sdk}] removes progress after terminal success`, async () => {
      await withMaintenance(vi, mount, FROZEN, true, async (view, feed) => {
        await view.flush(() =>
          feed.pushRestore(
            restoreFrame({
              running: false,
              phase: 'done',
              outcome: 'success',
            }),
          ),
        )
        expect(part(view.root, 'maintenance-restore-bar')).toBeNull()
        expect(part(view.root, 'maintenance-restore-progress')).toBeNull()
      })
    })

    it(`M18 [${sdk}] explains the failed restore and its consequence`, async () => {
      await withMaintenance(vi, mount, FROZEN, true, async (view, feed) => {
        await view.flush(() =>
          feed.pushRestore(
            restoreFrame({
              running: false,
              phase: 'failed',
              outcome: 'error',
              failureReason: 'the archive checksum did not match',
              databaseTouched: true,
              rehydrateComplete: false,
            }),
          ),
        )
        const outcome = text(required(view.root, 'maintenance-restore-outcome'))
        expect(outcome).toContain('checksum did not match')
        expect(outcome).toContain('already being replaced')
        expect(outcome).toContain('stays closed')
      })
    })
  })
}
