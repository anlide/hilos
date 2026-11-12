import type {
  TableSelectionMount,
  TableSelectionReply,
  TableSelectionResources,
  TableSelectionRunner,
  TableSelectionView,
} from './tableSelectionFixtures.js'
import { withTableSelectionResources } from './tableSelectionFixtures.js'

function required<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const node = root.querySelector<T>(selector)
  if (node === null)
    throw new Error(`Missing table confirmation node: ${selector}`)
  return node
}

function byId<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  return required<T>(root, `[data-id="${id}"]`)
}

/** The document is only a portal query scope; the native host stays panel-local. */
function dialogs(
  view: TableSelectionView,
  initial: Set<Element>,
): HTMLElement[] {
  return Array.from(
    view.root.ownerDocument.querySelectorAll<HTMLElement>('[role="dialog"]'),
  ).filter((dialog) => !initial.has(dialog))
}

/** Dispatch through the live handler even when a native click would be blocked. */
function dispatchClick(node: HTMLElement): void {
  node.dispatchEvent(new MouseEvent('click', { bubbles: true }))
}

/** Confirmation and refusal proof through each SDK's real Modal and ActionError. */
export function registerTableSelectionConfirmCases(
  sdk: string,
  runner: TableSelectionRunner,
  mount: TableSelectionMount,
): void {
  const { describe, it, expect } = runner
  function scenario(
    name: string,
    steps: (resources: TableSelectionResources) => Promise<void>,
  ): void {
    it(name, () => withTableSelectionResources(runner, mount, steps))
  }
  describe(`HilosTableSelection confirmation on ${sdk}`, () => {
    scenario(
      'S04 reads the target at confirmation, after a new mark',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.selectRow('a', true)
        const view = await resources.mount(fixture)
        const initial = new Set<Element>(
          view.root.ownerDocument.querySelectorAll('[role="dialog"]'),
        )
        await view.change(() =>
          byId(view.root, 'hilos-table-bulk-delete').click(),
        )
        expect(dialogs(view, initial)).toHaveLength(1)
        const modal = dialogs(view, initial)[0]!
        expect(modal.textContent).toContain(
          '1 row on this page will be affected',
        )
        expect(fixture.asked).toHaveLength(0)
        await view.change(() => fixture.controller.selectRow('b', true))
        await view.change(() => byId(modal, 'hilos-table-bulk-confirm').click())
        await view.settle()
        expect(fixture.asked).toEqual([{ kind: 'rows', rowKeys: ['a', 'b'] }])
        expect(dialogs(view, initial)).toEqual([])
        expect(modal.isConnected).toBe(false)
      },
    )

    scenario(
      'S05 keeps the confirmation and the exact server refusal',
      async (resources) => {
        const fixture = resources.create(() => resources.refused())
        fixture.controller.selectRow('a', true)
        const view = await resources.mount(fixture)
        const initial = new Set<Element>(
          view.root.ownerDocument.querySelectorAll('[role="dialog"]'),
        )
        await view.change(() =>
          byId(view.root, 'hilos-table-bulk-delete').click(),
        )
        const modal = dialogs(view, initial)[0]!
        await view.change(() => byId(modal, 'hilos-table-bulk-confirm').click())
        await view.settle()
        expect(dialogs(view, initial)).toEqual([modal])
        expect(byId(modal, 'hilos-action-error').textContent).toContain(
          'The node is frozen',
        )
      },
    )

    for (const ordering of ['before ack', 'after ack'] as const) {
      scenario(
        `S06 locks, refuses and retries with progress ${ordering}`,
        async (resources) => {
          const runs: TableSelectionReply[] = []
          let sends = 0
          const fixture = resources.create(() => {
            sends += 1
            if (ordering === 'after ack' && sends === 2)
              return resources.accepted('run-2')
            const reply = resources.deferred()
            runs.push(reply)
            return reply.handle
          })
          const { controller, asked } = fixture
          controller.selectRow('a', true)
          const view = await resources.mount(fixture)
          const document = view.root.ownerDocument
          const initial = new Set<Element>(
            document.querySelectorAll('[role="dialog"]'),
          )
          await view.change(() =>
            byId(view.root, 'hilos-table-bulk-delete').click(),
          )
          expect(dialogs(view, initial)).toHaveLength(1)
          const modal = dialogs(view, initial)[0]!
          const body = required<HTMLFieldSetElement>(
            modal,
            '.modal-body > fieldset',
          )
          // Query a real room before comparing identity: null === null proves nothing.
          const room = byId(modal, 'hilos-action-error-slot')
          const message = required<HTMLParagraphElement>(body, 'p')
          const originalMessage = message.textContent
          const cancel = Array.from(modal.querySelectorAll('button')).find(
            (button) => button.textContent?.trim() === 'Cancel',
          )
          if (cancel === undefined)
            throw new Error('Missing confirmation Cancel button')
          const close = byId<HTMLButtonElement>(modal, 'modal-close')
          const confirm = byId<HTMLButtonElement>(
            modal,
            'hilos-table-bulk-confirm',
          )
          expect(body.textContent).toContain(
            '1 row on this page will be affected',
          )
          expect(body.disabled).toBe(false)
          expect(body.matches(':disabled')).toBe(false)
          expect(cancel.disabled).toBe(false)
          expect(cancel.matches(':disabled')).toBe(false)
          expect(controller.progress.bulk.get()).toBeNull()

          await view.change(() => dispatchClick(confirm))
          expect(runs).toHaveLength(1)
          expect(runs[0]!.handle.loading.get()).toBe(false)
          expect(controller.progress.bulk.get()).toBeNull()
          expect(body.disabled).toBe(true)
          expect(body.matches(':disabled')).toBe(true)
          expect(cancel.disabled).toBe(true)
          expect(cancel.matches(':disabled')).toBe(true)
          expect(close.disabled).toBe(true)
          expect(close.matches(':disabled')).toBe(true)
          expect(confirm.getAttribute('aria-busy')).toBe('true')
          expect(confirm.closest('fieldset:disabled')).toBeNull()
          // Preserve native disabled probes and React's synthetic handler probes.
          for (const gesture of [
            () => {
              cancel.click()
              dispatchClick(cancel)
            },
            () => {
              close.click()
              dispatchClick(close)
            },
            () =>
              modal.dispatchEvent(
                new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
              ),
            () => {
              modal.click()
              dispatchClick(modal)
            },
          ]) {
            await view.change(gesture)
            expect(dialogs(view, initial)).toEqual([modal])
          }
          await view.change(() => dispatchClick(confirm))
          await view.change(() =>
            confirm.dispatchEvent(
              new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
            ),
          )
          await view.change(() => dispatchClick(confirm))
          expect(asked).toEqual([{ kind: 'rows', rowKeys: ['a'] }])
          expect(
            dialogs(view, initial).filter(
              (dialog) => dialog.getAttribute('data-id') === 'modal-confirm',
            ),
          ).toEqual([])

          await view.updateShown(false)
          expect(dialogs(view, initial)).toEqual([modal])
          await view.change(() => runs[0]!.refuse())
          await view.settle()
          expect(dialogs(view, initial)).toEqual([modal])
          expect(required(modal, '.modal-body > fieldset')).toBe(body)
          expect(byId(modal, 'hilos-action-error-slot')).toBe(room)
          expect(required(body, 'p')).toBe(message)
          expect(message.textContent).toBe(originalMessage)
          expect(byId(modal, 'hilos-action-error').textContent).toContain(
            'The node is frozen',
          )
          expect(body.disabled).toBe(false)
          expect(body.matches(':disabled')).toBe(false)
          expect(cancel.disabled).toBe(false)
          expect(cancel.matches(':disabled')).toBe(false)
          expect(close.disabled).toBe(false)
          expect(close.matches(':disabled')).toBe(false)
          expect(confirm.getAttribute('aria-busy')).toBeNull()
          expect(
            dialogs(view, initial).filter(
              (dialog) => dialog.getAttribute('data-id') === 'modal-confirm',
            ),
          ).toEqual([])
          expect(runs).toHaveLength(1)

          if (ordering === 'before ack') {
            await view.change(() => dispatchClick(confirm))
            expect(asked).toHaveLength(2)
            expect(runs).toHaveLength(2)
            expect(body.disabled).toBe(true)
            expect(confirm.getAttribute('aria-busy')).toBe('true')
            await view.change(() =>
              controller.ingestProgress({
                scope: 'bulk',
                progressKey: 'run-1',
                current: 1,
                total: 4,
              }),
            )
            await view.change(() => runs[1]!.accept('run-1', 4))
            await view.settle()
            expect(dialogs(view, initial)).toEqual([])
            expect(controller.progress.bulk.get()).toEqual({
              progressKey: 'run-1',
              current: 1,
              total: 4,
              fraction: 0.25,
              detail: {},
            })
            expect(
              byId(view.root, 'hilos-table-bulk-delete').matches(':disabled'),
            ).toBe(true)
            expect(document.body.classList.contains('modal-open')).toBe(false)
          } else {
            // Acceptance alone closes the window; no progress input helped it.
            // This also preserves Vue's retry-accept-without-progress phase.
            await view.change(() => dispatchClick(confirm))
            await view.settle()
            expect(asked).toHaveLength(2)
            expect(dialogs(view, initial)).toEqual([])
            expect(controller.progress.bulk.get()).toBeNull()
            await view.change(() =>
              controller.ingestProgress({
                scope: 'bulk',
                progressKey: 'run-2',
                current: 1,
                total: 4,
              }),
            )
            expect(dialogs(view, initial)).toEqual([])
            expect(
              byId<HTMLButtonElement>(view.root, 'hilos-table-bulk-delete')
                .disabled,
            ).toBe(true)
            await view.change(() =>
              controller.ingestProgress({
                scope: 'bulk',
                progressKey: 'run-2',
                current: 4,
                total: 4,
                ended: true,
              }),
            )
            expect(
              byId<HTMLButtonElement>(view.root, 'hilos-table-bulk-delete')
                .disabled,
            ).toBe(false)
          }
        },
      )
    }

    scenario(
      'S07 names the nested refusal details for the declared operation',
      async (resources) => {
        const fixture = resources.create(() => resources.refused())
        fixture.controller.selectRow('a', true)
        const view = await resources.mount(fixture)
        const document = view.root.ownerDocument
        const initial = new Set<Element>(
          document.querySelectorAll('[role="dialog"]'),
        )
        await view.change(() =>
          byId(view.root, 'hilos-table-bulk-delete').click(),
        )
        const modal = dialogs(view, initial)[0]!
        await view.change(() => byId(modal, 'hilos-table-bulk-confirm').click())
        await view.settle()
        const details = byId<HTMLButtonElement>(
          modal,
          'hilos-action-error-details',
        )
        await view.change(() => {
          details.focus()
          details.click()
        })
        await view.settle()
        const nested = dialogs(view, initial).filter(
          (dialog) => dialog !== modal,
        )
        expect(nested).toHaveLength(1)
        const detailDialog = nested[0]!
        expect(detailDialog.getAttribute('aria-label')).toBe(
          "Couldn't delete the marked rows",
        )
        expect(detailDialog.contains(document.activeElement)).toBe(true)
        expect(modal.isConnected).toBe(true)
        expect(document.body.classList.contains('modal-open')).toBe(true)
        await view.change(() =>
          byId(detailDialog, 'hilos-action-error-close').click(),
        )
        await view.settle()
        expect(detailDialog.isConnected).toBe(false)
        expect(dialogs(view, initial)).toEqual([modal])
        expect(document.activeElement).toBe(details)
        expect(document.body.classList.contains('modal-open')).toBe(true)
      },
    )
  })
}
