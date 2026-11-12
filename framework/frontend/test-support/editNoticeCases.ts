// One text of the edit notice cases; styling and pixel geometry are not asserted.
import type { RowEditNoticeKind } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The registering SDK's Vitest. */
type EditNoticeTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** The real component's inputs. */
export interface EditNoticeInput {
  kind: RowEditNoticeKind | null
  text: string
  dataId: string
}

/** Lifecycle operations supplied by one SDK, without expectations. */
export interface EditNoticeView {
  readonly root: ParentNode
  update(patch: Partial<EditNoticeInput>): Promise<void>
  click(target: HTMLElement): Promise<void>
  flush(): Promise<void>
  unmount(): Promise<void>
}

/** Mount the real component, including its teleported or portaled details. */
type MountEditNotice = (input: EditNoticeInput) => Promise<EditNoticeView>

/** Find a scenario-owned data-id under the mounted document. */
function byId(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** Run a case, always destroying its modal, effects and host. */
async function withView(
  runner: EditNoticeTestRunner,
  mount: MountEditNotice,
  kind: RowEditNoticeKind | null,
  text: string,
  steps: (view: EditNoticeView) => Promise<void>,
): Promise<void> {
  const view = await mount({ kind, text, dataId: 'edit-notice' })
  try {
    await view.flush()
    await steps(view)
  } finally {
    await view.unmount()
  }
  runner.expect(view.root.childElementCount).toBe(0)
  runner.expect(document.body.classList.contains('modal-open')).toBe(false)
}

/** Register notice data, structure, details and permanent announcement cases. */
export function registerEditNoticeCases(
  sdk: string,
  runner: EditNoticeTestRunner,
  mount: MountEditNotice,
): void {
  const { describe, it, expect } = runner

  describe(`HilosEditNotice on ${sdk}`, () => {
    it('keeps an aria-hidden inert twin while there is no message', async () => {
      await withView(runner, mount, null, '', async ({ root }) => {
        expect(byId(root, 'edit-notice')).toBeNull()
        const idle = byId(root, 'edit-notice-idle')
        expect(idle).not.toBeNull()
        expect(idle?.getAttribute('aria-hidden')).toBe('true')
        expect(idle?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(idle?.querySelector('span')).not.toBeNull()
        expect(idle?.querySelector('span i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(idle?.querySelector('button, a, input, [tabindex]')).toBeNull()
      })
    })

    it('draws conflict, deletion and update data without a second announcement', async () => {
      const kinds: RowEditNoticeKind[] = ['conflict', 'deleted', 'updated']
      for (const kind of kinds) {
        await withView(runner, mount, kind, 'Something', async ({ root }) => {
          const row = byId(root, 'edit-notice')
          expect(row).not.toBeNull()
          expect(row?.querySelector('span')?.textContent).toBe('Something')
          expect(row?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
            'true',
          )
          expect(byId(root, 'edit-notice-idle')).toBeNull()
          expect(row?.getAttribute('role')).toBeNull()
          expect(row?.getAttribute('aria-live')).toBeNull()
        })
      }
    })

    it('keeps the whole sentence and opens it from the named details button', async () => {
      const text =
        'Changed elsewhere to "a value long enough to run past the row"'
      await withView(runner, mount, 'conflict', text, async (view) => {
        expect(
          byId(view.root, 'edit-notice')?.querySelector('span')?.textContent,
        ).toBe(text)
        const details = byId(
          view.root,
          'edit-notice-details',
        ) as HTMLButtonElement
        expect(details.tagName).toBe('BUTTON')
        expect(details.getAttribute('aria-label')).toBe('Show details')
        await view.click(details)
        await view.flush()
        expect(byId(view.root, 'edit-notice-full')?.textContent?.trim()).toBe(
          text,
        )
      })
    })

    it('closes details when the message disappears', async () => {
      await withView(
        runner,
        mount,
        'updated',
        'Updated just now',
        async (view) => {
          await view.click(
            byId(view.root, 'edit-notice-details') as HTMLElement,
          )
          await view.flush()
          expect(byId(view.root, 'edit-notice-full')).not.toBeNull()
          await view.update({ kind: null, text: '' })
          await view.flush()
          expect(byId(view.root, 'edit-notice-full')).toBeNull()
        },
      )
    })

    it('keeps the same polite status slot before and after a message', async () => {
      await withView(runner, mount, null, '', async (view) => {
        const slot = byId(view.root, 'edit-notice-slot')
        expect(slot).not.toBeNull()
        expect(slot?.getAttribute('role')).toBe('status')
        expect(slot?.getAttribute('aria-live')).toBe('polite')
        await view.update({ kind: 'updated', text: 'Updated just now' })
        await view.flush()
        expect(byId(view.root, 'edit-notice-slot')).toBe(slot)
        expect(slot?.getAttribute('role')).toBe('status')
        expect(slot?.getAttribute('aria-live')).toBe('polite')
        expect(slot?.textContent).toContain('Updated just now')
      })
    })
  })
}
