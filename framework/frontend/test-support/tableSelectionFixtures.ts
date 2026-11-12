import {
  ActionError,
  TableViewportController,
  createSignal,
  hilosToasts,
} from '@hilos/core/base'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableSelectionTarget,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The panel's actual inputs, independent of its native view framework. */
export interface TableSelectionInput {
  controller: TableViewportController<unknown>
  shown: boolean
}

/** Omission means no provider, which is different from providing false. */
export interface TableSelectionMountOptions {
  adminViewMode?: boolean
}

/** Native input delivery and lifecycle; steps and observations remain common. */
export interface TableSelectionView {
  readonly root: HTMLElement
  updateShown(shown: boolean): Promise<void>
  change(action: () => void): Promise<void>
  /** Flush the native view after a controlled action reply, without a clock. */
  settle(): Promise<void>
  unmount(): Promise<void> | void
}

export type TableSelectionRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
>
export type TableSelectionMount = (
  input: TableSelectionInput,
  options: TableSelectionMountOptions,
) => Promise<TableSelectionView>

interface SelectionFixture {
  controller: TableViewportController<unknown>
  asked: HilosTableSelectionTarget[]
}

/** An owned reply, created only when the declared operation actually runs. */
export interface TableSelectionReply {
  handle: ActionHandle<HilosTableBulkAccepted>
  accept(progressKey: string, total: number): void
  refuse(): void
}

export interface TableSelectionResources {
  create(send?: () => ActionHandle<HilosTableBulkAccepted>): SelectionFixture
  accepted(
    progressKey: string,
    total?: number,
  ): ActionHandle<HilosTableBulkAccepted>
  refused(): ActionHandle<HilosTableBulkAccepted>
  deferred(): TableSelectionReply
  mount(
    fixture: SelectionFixture,
    options?: TableSelectionMountOptions,
  ): Promise<TableSelectionView>
}

/** Own controllers before ingest, replies before dispatch, and native mounts. */
export async function withTableSelectionResources(
  runner: TableSelectionRunner,
  nativeMount: TableSelectionMount,
  steps: (resources: TableSelectionResources) => Promise<void>,
): Promise<void> {
  const controllers: TableViewportController<unknown>[] = []
  const mounts: TableSelectionView[] = []
  const pending: TableSelectionReply[] = []
  const handles: ActionHandle<HilosTableBulkAccepted>[] = []
  const initialDialogs = new Set(document.querySelectorAll('[role="dialog"]'))
  const initialScrollLock = document.body.classList.contains('modal-open')
  const initialFocus = document.activeElement
  const errors: unknown[] = []
  async function attempt(release: () => unknown): Promise<void> {
    try {
      await release()
    } catch (error) {
      errors.push(error)
    }
  }
  function retain(handle: ActionHandle<HilosTableBulkAccepted>) {
    handles.push(handle)
    // Setup can fail before a component consumes this reply. Keep the original
    // rejecting promise for the real consumers and handle that failure too.
    void handle.done.catch(() => undefined)
    return handle
  }
  function refusal(): ActionError {
    return new ActionError('backups_delete', 'fail', 'The node is frozen')
  }
  const resources: TableSelectionResources = {
    accepted(progressKey, total = 2) {
      return retain({
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.resolve({ reply: { progressKey, total } }),
      })
    },
    refused() {
      return retain({
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.reject(refusal()),
      })
    },
    deferred() {
      let resolve!: (result: { reply: HilosTableBulkAccepted }) => void
      let reject!: (error: ActionError) => void
      const handle = retain({
        requestId: 'bulk-pending',
        loading: createSignal(false),
        done: new Promise<{ reply: HilosTableBulkAccepted }>((yes, no) => {
          resolve = yes
          reject = no
        }),
      })
      const reply: TableSelectionReply = {
        handle,
        accept: (progressKey, total) =>
          resolve({ reply: { progressKey, total } }),
        refuse: () => reject(refusal()),
      }
      pending.push(reply)
      return reply
    },
    create(send = () => resources.accepted('run-1')) {
      const asked: HilosTableSelectionTarget[] = []
      const controller = new TableViewportController<unknown>({
        resolve: (raw) => ({ name: String(raw.slots['name']) }),
        sendViewport: () => {},
        frame: {
          title: 'Backups',
          columns: [{ key: 'name', label: 'Name' }],
          bulkActions: [
            {
              key: 'delete',
              label: 'Delete',
              refusalTitle: "Couldn't delete the marked rows",
              danger: true,
              run(target) {
                asked.push(target)
                return send()
              },
            },
          ],
        },
      })
      controllers.push(controller)
      controller.ingestWindow(
        [
          { rowKey: 'a', slots: { name: 'Alice' } },
          { rowKey: 'b', slots: { name: 'Bob' } },
        ],
        2,
        true,
        null,
        null,
        10,
      )
      return { controller, asked }
    },
    async mount(fixture, options = {}) {
      const view = await nativeMount(
        { controller: fixture.controller, shown: true },
        options,
      )
      mounts.push(view)
      return view
    },
  }
  try {
    await steps(resources)
  } catch (error) {
    errors.push(error)
  } finally {
    // A failed step may leave a run unanswered. Settle it as a refusal while its
    // real consumers still exist, then let their loading subscriptions release.
    for (const reply of pending)
      await attempt(() => {
        const view = mounts.at(-1)
        return view === undefined
          ? reply.refuse()
          : view.change(() => reply.refuse())
      })
    await attempt(() =>
      Promise.allSettled(handles.map((handle) => handle.done)),
    )
    for (const view of mounts) await attempt(() => view.settle())
    for (const view of mounts.reverse()) {
      const ownDialogs = Array.from(
        view.root.ownerDocument.querySelectorAll('[role="dialog"]'),
      ).filter((dialog) => !initialDialogs.has(dialog))
      await attempt(() => view.unmount())
      await attempt(() => runner.expect(view.root.childElementCount).toBe(0))
      await attempt(() => runner.expect(view.root.isConnected).toBe(false))
      for (const dialog of ownDialogs)
        await attempt(() => runner.expect(dialog.isConnected).toBe(false))
    }
    await attempt(() =>
      runner
        .expect(
          Array.from(document.querySelectorAll('[role="dialog"]')).filter(
            (dialog) => !initialDialogs.has(dialog),
          ),
        )
        .toEqual([]),
    )
    for (const controller of controllers.reverse())
      await attempt(() => controller.dispose())
    await attempt(() =>
      runner
        .expect(document.body.classList.contains('modal-open'))
        .toBe(initialScrollLock),
    )
    await attempt(() => {
      const active = document.activeElement
      runner
        .expect(active === initialFocus || active === document.body)
        .toBe(true)
    })
    await attempt(() => hilosToasts.clear())
    await attempt(() => runner.expect(hilosToasts.toasts.get()).toEqual([]))
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Table selection case and cleanup failed',
      {
        cause: errors[0],
      },
    )
}
