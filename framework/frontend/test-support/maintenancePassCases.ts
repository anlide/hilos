import { PROTECTED_MODE_PASS_COPY } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  FROZEN,
  FROZEN_WITHOUT_COPY,
  MINTED,
  REJECTED,
  VERIFYING,
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

/** Remove only outer template whitespace; preserve all words and punctuation. */
function text(node: HTMLElement): string {
  return node.textContent!.trim()
}

/** One pass-admission contract, run against the three real native components. */
export function registerMaintenancePassCases(
  sdk: string,
  runner: MaintenanceRunner,
  mount: MountMaintenance,
): void {
  const { describe, it, expect, vi } = runner
  describe(`HilosMaintenance pass on ${sdk}`, () => {
    it('M02 renders the copy the backend authored', async () => {
      await withMaintenance(vi, mount, FROZEN, true, async ({ root }) => {
        expect(
          required(root, 'maintenance').getAttribute('data-operation'),
        ).toBe('restore')
        expect(text(required(root, 'maintenance-title'))).toBe(
          'Restoring a backup',
        )
        expect(text(required(root, 'maintenance-message'))).toBe(
          'The application will be back in a few minutes.',
        )
      })
    })

    it('M03 falls back to its own copy when the frame carried none', async () => {
      await withMaintenance(
        vi,
        mount,
        FROZEN_WITHOUT_COPY,
        true,
        async ({ root }) => {
          expect(
            required(root, 'maintenance').hasAttribute('data-operation'),
          ).toBe(false)
          expect(text(required(root, 'maintenance-title'))).toBe(
            'Maintenance in progress',
          )
          expect(text(required(root, 'maintenance-message'))).not.toBe('')
        },
      )
    })

    it('M04 offers no code form on a freeze that admits nobody', async () => {
      await withMaintenance(vi, mount, FROZEN, true, async ({ root }) => {
        expect(part(root, 'maintenance-pass-form')).toBeNull()
      })
    })

    it('M05 offers the code form on an administrative surface only', async () => {
      await withMaintenance(vi, mount, MINTED, false, async ({ root }) => {
        expect(part(root, 'maintenance-pass-form')).toBeNull()
        expect(part(root, 'maintenance-pass-pending')).toBeNull()
        expect(part(root, 'maintenance-pass-error-slot')).toBeNull()
      })
      // A fresh administrative mount, after the public component was destroyed.
      await withMaintenance(vi, mount, MINTED, true, async ({ root }) => {
        expect(part(root, 'maintenance-pass-form')).not.toBeNull()
      })
    })

    it('M06 says a code is not out yet instead of offering an empty field', async () => {
      await withMaintenance(vi, mount, VERIFYING, true, async ({ root }) => {
        expect(part(root, 'maintenance-pass-form')).toBeNull()
        expect(part(root, 'maintenance-pass')).toBeNull()
        expect(part(root, 'maintenance-pass-error-slot')).toBeNull()
        expect(text(required(root, 'maintenance-pass-pending'))).not.toBe('')
      })
    })

    it('M07 swaps the sentence for the form when the first code lands', async () => {
      await withMaintenance(vi, mount, VERIFYING, true, async (view) => {
        await view.update({ status: MINTED })
        expect(part(view.root, 'maintenance-pass-form')).not.toBeNull()
        expect(part(view.root, 'maintenance-pass-pending')).toBeNull()
      })
    })

    it('M08 offers no waiting sentence on a freeze that admits nobody', async () => {
      await withMaintenance(vi, mount, FROZEN, true, async ({ root }) => {
        expect(part(root, 'maintenance-pass-pending')).toBeNull()
      })
    })

    it('M09 presents the raw typed code and keeps the draft after submit', async () => {
      await withMaintenance(vi, mount, MINTED, true, async (view, feed) => {
        const field = required(
          view.root,
          'maintenance-pass',
        ) as HTMLInputElement
        await view.input(field, 'typo')
        await view.submit(
          required(view.root, 'maintenance-pass-form') as HTMLFormElement,
        )
        expect(feed.presented).toEqual(['typo'])
        expect(
          (required(view.root, 'maintenance-pass') as HTMLInputElement).value,
        ).toBe('typo')
      })
    })

    it('M10 says so when the code was not accepted', async () => {
      await withMaintenance(vi, mount, REJECTED, true, async ({ root }) => {
        const row = part(root, 'maintenance-pass-error')
        expect(row).not.toBeNull()
        expect(text(required(root, 'maintenance-pass-error'))).toContain(
          PROTECTED_MODE_PASS_COPY.rejected,
        )
        expect(
          required(root, 'maintenance-pass').getAttribute('aria-invalid'),
        ).toBe('true')
      })
    })
  })
}
