import { TableViewportController } from '@hilos/core/base'
import type {
  HilosTableFrame,
  HilosTableSortOrder,
  TableViewportDescriptor,
  TableViewportControllerOptions,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** Only the real Bar's inputs cross the native adapter boundary. */
export interface TableBarInput {
  controller: TableViewportController<unknown>
  titleId: string
}

/** Native delivery and lifetime; all scenario steps and queries stay common. */
export interface TableBarView {
  readonly root: HTMLElement
  change(action: () => void): Promise<void>
  type(field: HTMLInputElement, value: string): Promise<void>
  unmount(): Promise<void> | void
}

export type TableBarRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
export type TableBarMount = (input: TableBarInput) => Promise<TableBarView>

interface BarFixture {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
}

export interface TableBarResources {
  create(
    frame: HilosTableFrame,
    initialFilter?: Record<string, unknown>,
    declaredOrders?: readonly HilosTableSortOrder[],
    known?: boolean,
    sendRendered?: TableViewportControllerOptions<unknown>['sendRendered'],
  ): BarFixture
  mount(fixture: BarFixture, titleId?: string): Promise<TableBarView>
}

/** Own the controller before ingest and attempt every independent release. */
export async function withTableBarResources(
  runner: TableBarRunner,
  nativeMount: TableBarMount,
  steps: (resources: TableBarResources) => Promise<void>,
): Promise<void> {
  const controllers: TableViewportController<unknown>[] = []
  const mounts: TableBarView[] = []
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
  try {
    await steps({
      create(frame, initialFilter, declaredOrders, known = true, sendRendered) {
        const sent: TableViewportDescriptor[] = []
        const controller = new TableViewportController<unknown>({
          resolve: (raw) => ({ name: String(raw.slots['name']) }),
          sendViewport: (descriptor) => sent.push(descriptor),
          sendRendered,
          initialFilter,
          frame,
          declaredOrders,
        })
        controllers.push(controller)
        if (known)
          controller.ingestSubscriptionWindow(
            [],
            0,
            true,
            null,
            null,
            10,
            undefined,
            initialFilter ?? {},
            [],
          )
        return { controller, sent }
      },
      async mount(fixture, titleId = 'table-title') {
        const view = await nativeMount({
          controller: fixture.controller,
          titleId,
        })
        mounts.push(view)
        return view
      },
    })
  } catch (error) {
    errors.push(error)
  } finally {
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
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Table bar case and cleanup failed', {
      cause: errors[0],
    })
}
