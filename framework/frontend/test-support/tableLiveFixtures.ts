/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { TableViewportController } from '@hilos/core/base'
import type { HilosTableColumn } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** Only the real Live inputs cross the native boundary. */
export interface TableLiveInput {
  controller: TableViewportController<unknown>
  columns: readonly HilosTableColumn[]
}

/** Native rendering and lifetime; scenario steps and queries stay common. */
export interface TableLiveView {
  readonly root: HTMLElement
  change(action: () => void): Promise<void>
  unmount(): Promise<void>
}

export type TableLiveRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
export type TableLiveMount = (input: TableLiveInput) => Promise<TableLiveView>

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name' },
  { key: 'presence', label: 'Presence', source: 'connections' },
]

// Real Bootstrap supplies the hidden state; no utility spelling is an oracle.
const bootstrapCss = readFileSync(
  createRequire(import.meta.url).resolve('bootstrap/dist/css/bootstrap.css'),
  'utf8',
)

interface TableLiveResources {
  create(): TableViewportController<unknown>
  mount(controller: TableViewportController<unknown>): Promise<TableLiveView>
  useBootstrap(): void
}

/** Own resources before fallible setup and attempt every independent release. */
export async function withTableLiveResources(
  runner: TableLiveRunner,
  nativeMount: TableLiveMount,
  steps: (resources: TableLiveResources) => Promise<void>,
): Promise<void> {
  const controllers: TableViewportController<unknown>[] = []
  const views: TableLiveView[] = []
  const styles: HTMLStyleElement[] = []
  const initialDialogs = new Set(document.querySelectorAll('[role="dialog"]'))
  const initialFocus = document.activeElement
  const initialScrollLock = document.body.classList.contains('modal-open')
  const initialScroll = { x: window.scrollX, y: window.scrollY }
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
      create() {
        const controller = new TableViewportController<unknown>({
          resolve: (raw) => ({ name: String(raw.slots['name']) }),
          sendViewport: () => undefined,
          frame: { columns: COLUMNS },
        })
        controllers.push(controller)
        controller.ingestWindow(
          [{ rowKey: 'a', slots: { name: 'Alice' } }],
          1,
          true,
          null,
          null,
          10,
        )
        return controller
      },
      async mount(controller) {
        const view = await nativeMount({ controller, columns: COLUMNS })
        views.push(view)
        return view
      },
      useBootstrap() {
        const style = document.createElement('style')
        styles.push(style)
        style.textContent = bootstrapCss
        document.head.appendChild(style)
      },
    })
  } catch (error) {
    errors.push(error)
  } finally {
    for (const view of views.reverse()) {
      const ownDialogs = Array.from(
        document.querySelectorAll('[role="dialog"]'),
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
    for (const style of styles.reverse()) {
      await attempt(() => style.remove())
      await attempt(() => runner.expect(style.isConnected).toBe(false))
    }
    await attempt(() => {
      if (initialFocus instanceof HTMLElement && initialFocus.isConnected)
        initialFocus.focus()
    })
    await attempt(() =>
      runner.expect(document.activeElement).toBe(initialFocus),
    )
    await attempt(() => {
      if (
        window.scrollX !== initialScroll.x ||
        window.scrollY !== initialScroll.y
      )
        window.scrollTo(initialScroll.x, initialScroll.y)
    })
    await attempt(() => runner.expect(window.scrollX).toBe(initialScroll.x))
    await attempt(() => runner.expect(window.scrollY).toBe(initialScroll.y))
    await attempt(() =>
      runner
        .expect(document.body.classList.contains('modal-open'))
        .toBe(initialScrollLock),
    )
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Table live case and cleanup failed', {
      cause: errors[0],
    })
}
