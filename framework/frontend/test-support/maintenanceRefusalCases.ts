import { PROTECTED_MODE_PASS_COPY } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  MINTED,
  REJECTED,
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

/** Read the row's sentence without coupling it to its truncation classes. */
function sentence(row: HTMLElement): string {
  const node = row.querySelector('span')
  if (!node) throw new Error('Missing Maintenance refusal sentence')
  return node.textContent!.trim()
}

/** One refusal room and details timeline on the three real native components. */
export function registerMaintenanceRefusalCases(
  sdk: string,
  runner: MaintenanceRunner,
  mount: MountMaintenance,
): void {
  const { describe, it, expect, vi } = runner
  describe(`HilosMaintenance refusal on ${sdk}`, () => {
    it('M11 holds the room of the refusal from the moment the field appears', async () => {
      await withMaintenance(vi, mount, MINTED, true, async ({ root }) => {
        expect(part(root, 'maintenance-pass-error-slot')).not.toBeNull()
        const idle = part(root, 'maintenance-pass-error-idle')
        expect(idle).not.toBeNull()
        expect(idle?.getAttribute('aria-hidden')).toBe('true')
        expect(idle?.querySelector('button')).toBeNull()
        expect(part(root, 'maintenance-pass-error')).toBeNull()
        expect(part(root, 'maintenance-pass-error-details')).toBeNull()
        expect(
          required(root, 'maintenance-pass').hasAttribute('aria-describedby'),
        ).toBe(false)
      })
    })

    it('M12 draws, opens and clears the refusal in the same slot of one mount', async () => {
      await withMaintenance(vi, mount, MINTED, true, async (view, feed) => {
        const { root } = view
        const document = root.ownerDocument
        const rejected = PROTECTED_MODE_PASS_COPY.rejected
        const field = (): HTMLInputElement =>
          required(root, 'maintenance-pass') as HTMLInputElement
        const slot = (): HTMLElement =>
          required(root, 'maintenance-pass-error-slot')
        const details = (): HTMLElement | null =>
          part(root, 'maintenance-pass-error-details')
        // Vue teleports and React portals; the marker belongs to this refusal.
        const full = (): HTMLElement | null =>
          part(document, 'maintenance-pass-error-full')

        await view.input(field(), 'typo')
        const fieldNode = field()
        const surfaceNode = required(root, 'maintenance')
        const slotNode = slot()

        // The same connection and component receive the refusal, without submit.
        await view.update({ status: REJECTED })
        expect(field()).toBe(fieldNode)
        expect(required(root, 'maintenance')).toBe(surfaceNode)
        expect(slot()).toBe(slotNode)
        expect(
          root.querySelectorAll('[data-id="maintenance-pass-error"]'),
        ).toHaveLength(1)
        const row = required(root, 'maintenance-pass-error')
        expect(sentence(row)).toBe(rejected)
        expect(details()?.getAttribute('aria-label')).toBe('Show error details')
        expect(fieldNode.value).toBe('typo')
        expect(fieldNode.getAttribute('aria-invalid')).toBe('true')
        expect(fieldNode.getAttribute('aria-describedby')).toBe(
          'maintenance-pass-error',
        )
        const target = document.getElementById('maintenance-pass-error')
        expect(target).not.toBeNull()
        expect(target?.contains(row)).toBe(true)
        expect(target?.textContent).toContain(rejected)
        // Only the permanent Maintenance surface announces the sentence.
        for (const node of [slotNode, row, target]) {
          expect(node?.getAttribute('role')).toBeNull()
          expect(node?.getAttribute('aria-live')).toBeNull()
        }
        expect(surfaceNode.getAttribute('role')).toBe('status')
        expect(surfaceNode.getAttribute('aria-live')).toBe('polite')

        await view.flush(() =>
          required(root, 'maintenance-pass-error-details').click(),
        )
        const dialog = required(
          document,
          'maintenance-pass-error-full',
        ).closest('[role="dialog"]')
        if (!dialog) throw new Error('Missing Maintenance refusal dialog')
        expect(
          dialog.querySelector('h1, h2, h3, h4, h5, h6, [role="heading"]')
            ?.textContent,
        ).toContain('Error details')
        expect(full()?.textContent?.trim()).toBe(rejected)
        expect(feed.presented).toEqual([])
        await view.flush(() =>
          required(dialog, 'maintenance-pass-error-close').click(),
        )
        expect(full()).toBeNull()
        await view.flush(() =>
          required(root, 'maintenance-pass-error-details').click(),
        )
        expect(full()?.textContent?.trim()).toBe(rejected)

        // Clear the refusal while details are open, retaining the raw draft.
        await view.update({ status: MINTED })
        expect(full()).toBeNull()
        expect(part(root, 'maintenance-pass-error')).toBeNull()
        expect(details()).toBeNull()
        expect(part(root, 'maintenance-pass-error-idle')).not.toBeNull()
        expect(field()).toBe(fieldNode)
        expect(slot()).toBe(slotNode)
        expect(fieldNode.value).toBe('typo')
        expect(fieldNode.hasAttribute('aria-describedby')).toBe(false)

        // A later refusal reuses the slot without reviving the old panel.
        await view.update({ status: REJECTED })
        expect(slot()).toBe(slotNode)
        expect(sentence(required(root, 'maintenance-pass-error'))).toBe(
          rejected,
        )
        expect(details()).not.toBeNull()
        expect(full()).toBeNull()
        expect(fieldNode.getAttribute('aria-describedby')).toBe(
          'maintenance-pass-error',
        )
      })
    })
  })
}
