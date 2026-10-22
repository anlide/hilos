import type { Expect, Locator, Page } from '@playwright/test'
import type { TableViewportController } from '../core/src/table/TableViewportController.js'
import { createSocketFrameReader, type SocketFrame } from './socket.js'
import { deliveryObject } from './deliveryFrames.js'

export const TABLE_SEARCH_HOOK = '__hilosE2eTableSearch'

export interface TableSearchApplication {
  readonly controller: number
  readonly root: number
  readonly appliedRequestId: string | null
  readonly search: string
  readonly filter: Readonly<Record<string, unknown>>
  readonly rows: readonly string[]
  readonly occurrences: readonly {
    readonly rowKey: string
    readonly occurrence: number
    readonly expanded: boolean
    readonly selected: boolean
    readonly placeholder: boolean
  }[]
  readonly descriptor: unknown
  readonly domSearch: string | null
  readonly domRows: readonly string[]
}
interface TableSearchPhysical {
  readonly direction: 'sent' | 'received'
  readonly socket: number
  readonly at: number
  readonly active: string | null
  readonly raw: string | undefined
  /** Taken after synchronous product dispatch and the queued Vue commit. */
  readonly application: readonly TableSearchApplication[]
}
interface TableSearchObservation extends SocketFrame, TableSearchPhysical {
  readonly index: number
  readonly inner: number
}
interface TableSearchFrame extends Omit<TableSearchObservation, 'wire'> {
  readonly wire: Record<string, unknown>
}
interface TableSearchState {
  readonly documentStarted: number
  readonly socket: number
  readonly readyState: number | null
  readonly physical: readonly TableSearchPhysical[]
  readonly application: readonly TableSearchApplication[]
}
interface TableSearchMark {
  readonly documentStarted: number
  readonly socket: number
  readonly cursor: number
}

/**
 * Observe real sends and post-dispatch physical messages before loading the app.
 * Incoming parsing belongs to the runner's shared decoder, including batches.
 * Vue's existing mounted vnode props give read-only access to the SDK controller;
 * no product registry, DOM request id or application test hook is installed.
 */
export async function watchTableSearch(
  page: Page,
  address: { page: string; tableKey: string },
) {
  await page.addInitScript((hook) => {
    const physical: TableSearchPhysical[] = []
    let sockets = 0
    let readReadyState: () => number | null = () => null
    const identities = new WeakMap<object, number>()
    let controllers = 0

    // Vue retains the root vnode in production too; unlike devtools fields,
    // _vnode is the renderer's own mount/unmount state. Follow only live trees.
    interface VNode {
      readonly props?: { controller?: TableViewportController<unknown> }
      readonly el?: Node
      readonly component?: { subTree: VNode; isUnmounted: boolean }
      readonly suspense?: { activeBranch?: VNode }
      readonly children?: unknown
    }
    const identity = (value: object) => {
      let id = identities.get(value)
      if (id === undefined) {
        id = ++controllers
        identities.set(value, id)
      }
      return id
    }
    const application = (target?: Element): TableSearchApplication[] => {
      const found = new Map<object, TableSearchApplication>()
      const visit = (node: VNode | null | undefined): void => {
        if (!node || typeof node !== 'object') return
        const controller = node.props?.controller
        const root = node.el
        if (
          controller?.lastAppliedWindowRequestId &&
          root instanceof Element &&
          root.isConnected &&
          (!target || target === root) &&
          root.getAttribute('data-id') &&
          !found.has(controller)
        ) {
          found.set(controller, {
            controller: identity(controller),
            root: identity(root),
            appliedRequestId: controller.lastAppliedWindowRequestId.get(),
            search: controller.search.get(),
            filter: controller.filter.get(),
            rows: controller.rows.get().map((row) => row.rowKey),
            occurrences: controller.rows.get().map((row) => ({
              rowKey: row.rowKey,
              occurrence: row.occurrence,
              expanded: row.expanded,
              selected: row.selected,
              placeholder: row.placeholder,
            })),
            descriptor: controller.descriptor(),
            domSearch:
              root.querySelector<HTMLInputElement>(
                '[data-id="hilos-table-search"]',
              )?.value ?? null,
            domRows: [
              ...root.querySelectorAll(
                'tbody > tr[data-id^="hilos-table-row-"]:not([data-id^="hilos-table-row-detail-"])',
              ),
            ].map((row) =>
              row.getAttribute('data-id')!.slice('hilos-table-row-'.length),
            ),
          })
        }
        if (node.component && !node.component.isUnmounted)
          visit(node.component.subTree)
        else if (Array.isArray(node.children)) {
          for (const child of node.children) visit(child)
        }
        visit(node.suspense?.activeBranch)
      }
      for (const root of document.querySelectorAll('[data-v-app]')) {
        visit((root as Element & { _vnode?: VNode })._vnode)
      }
      return [...found.values()]
    }

    const NativeWebSocket = window.WebSocket
    class ObservedWebSocket extends NativeWebSocket {
      private readonly number = ++sockets

      constructor(url: string | URL, protocols?: string | string[]) {
        super(url, protocols)
        readReadyState = () => this.readyState
        // openSocket installs the product listener synchronously after new.
        // Its signal dispatch queues Vue's flush before our checkpoint record.
        queueMicrotask(() =>
          this.addEventListener('message', (event) => {
            queueMicrotask(() => this.record('received', event.data))
          }),
        )
      }

      send(data: Parameters<WebSocket['send']>[0]): void {
        super.send(data)
        this.record('sent', data)
      }

      private record(direction: 'sent' | 'received', payload: unknown): void {
        physical.push({
          direction,
          socket: this.number,
          at: performance.now(),
          active: document.activeElement?.getAttribute('data-id') ?? null,
          raw: typeof payload === 'string' ? payload : undefined,
          application: direction === 'received' ? application() : [],
        })
      }
    }
    window.WebSocket = ObservedWebSocket
    Object.defineProperty(window, hook, {
      value: (target?: Element): TableSearchState => ({
        documentStarted: performance.timeOrigin,
        socket: sockets,
        readyState: readReadyState(),
        physical,
        application: application(target),
      }),
    })
  }, TABLE_SEARCH_HOOK)

  // This journal lives in one test runner/document, never in shared runtime.
  // Reset readers at a document boundary so physical message ids cannot alias.
  let documentStarted: number | undefined
  let cursor = 0
  const readers = new Map<number, ReturnType<typeof createSocketFrameReader>>()
  const frames: TableSearchFrame[] = []
  const observations: TableSearchObservation[] = []
  const read = async () => {
    const state = await page.evaluate(
      (hook) =>
        (window as unknown as Record<string, () => TableSearchState>)[hook](),
      TABLE_SEARCH_HOOK,
    )
    if (documentStarted !== state.documentStarted) {
      documentStarted = state.documentStarted
      cursor = 0
      readers.clear()
      frames.length = 0
      observations.length = 0
    }
    for (; cursor < state.physical.length; cursor++) {
      const physical = state.physical[cursor]!
      let decoded: SocketFrame[]
      if (physical.direction === 'received') {
        let reader = readers.get(physical.socket)
        if (!reader) {
          reader = createSocketFrameReader(physical.socket)
          readers.set(physical.socket, reader)
        }
        decoded = reader(physical.raw)
      } else {
        let wire: unknown
        try {
          wire = JSON.parse(physical.raw ?? '')
        } catch {
          wire = undefined
        }
        decoded = [
          {
            socket: physical.socket,
            message: 0,
            raw: physical.raw,
            wire,
            failure: undefined,
          },
        ]
      }
      decoded.forEach((frame, inner) => {
        const wire = deliveryObject(frame.wire)
        const observation = {
          ...physical,
          ...frame,
          index: cursor,
          inner,
        }
        observations.push(observation)
        const data = deliveryObject(
          physical.direction === 'sent' ? wire : wire.data,
        )
        if (
          (physical.direction === 'sent' &&
            [
              'page_subscribe',
              'page_update_subscription',
              'page_unsubscribe',
            ].includes(String(wire.type))) ||
          (data.page === address.page &&
            (data.tableKey === address.tableKey ||
              [
                'page_response',
                'subscription_page_error',
                'page_resubscribe',
              ].includes(String(wire.type))))
        )
          frames.push({ ...observation, wire })
      })
    }
    return { ...state, frames: [...frames], observations: [...observations] }
  }

  let condition: unknown
  return {
    read: async () => ({ ...(await read()), condition }),
    async rowKeys(): Promise<readonly string[]> {
      const state = await read()
      if (state.application.length !== 1)
        throw new Error('An unambiguous mounted table is required')
      return state.application[0]!.rows
    },
    /** Mark after navigation, before typing or changing a filter, in each document. */
    async mark(): Promise<TableSearchMark> {
      const state = await read()
      const mark = {
        documentStarted: state.documentStarted,
        socket: state.socket,
        cursor: state.physical.length,
      }
      condition = { mark }
      return mark
    },
    /** Await the latest post-mark addressed id, accepted by its live controller. */
    async settled(
      expect: Expect,
      mark: TableSearchMark,
      search: string,
      rowKeys: readonly string[],
    ) {
      let refusal: TableSearchFrame | undefined
      await expect
        .poll(async () => {
          const state = await read()
          const current = state.frames.filter(
            (frame) => frame.socket === mark.socket,
          )
          const subscriptions = current.filter(
            (frame) =>
              frame.direction === 'sent' &&
              [
                'page_subscribe',
                'page_update_subscription',
                'page_unsubscribe',
              ].includes(String(frame.wire.type)),
          )
          const subscription = subscriptions.at(-1)
          const full = current.filter((frame) => {
            if (frame.failure || frame.wire.type !== 'page_response')
              return false
            const payload = deliveryObject(
              deliveryObject(frame.wire.data).payload,
            )
            const window = deliveryObject(
              deliveryObject(payload.windows)[address.tableKey],
            )
            return (
              Array.isArray(window.rows) ||
              deliveryObject(payload.refusedWindows)[address.tableKey] !==
                undefined
            )
          })
          const opening = full.at(-1)
          const payload = deliveryObject(
            deliveryObject(opening?.wire.data).payload,
          )
          const initial = deliveryObject(
            deliveryObject(payload.windows)[address.tableKey],
          )
          const problems: string[] = []
          if (
            !subscription ||
            subscription.wire.page !== address.page ||
            subscription.wire.type === 'page_unsubscribe'
          )
            problems.push('no current page subscription')
          if (
            !opening ||
            !subscription ||
            opening.index <= subscription.index ||
            !Array.isArray(initial.rows)
          )
            problems.push('no initial window')
          if (opening && opening.index >= mark.cursor)
            problems.push('page replaced after mark')
          if (
            current.some(
              (frame) =>
                frame.index >= mark.cursor &&
                [
                  'subscription_page_error',
                  'table_viewport_frozen',
                  'page_resubscribe',
                ].includes(String(frame.wire.type)),
            )
          )
            problems.push('scope refused, frozen or recovering')
          const lastSent = current
            .filter(
              (frame) =>
                frame.direction === 'sent' &&
                frame.wire.type === 'table_viewport',
            )
            .at(-1)
          const requestId = lastSent?.wire.requestId
          const lastReply =
            typeof requestId === 'string' && requestId.length > 0
              ? current
                  .filter(
                    (frame) =>
                      !frame.failure &&
                      frame.direction === 'received' &&
                      ['table_window', 'table_window_refused'].includes(
                        String(frame.wire.type),
                      ) &&
                      deliveryObject(frame.wire.data).requestId === requestId &&
                      frame.index > lastSent!.index,
                  )
                  .at(-1)
              : undefined
          const processed = lastReply?.application.find(
            (item) => item.appliedRequestId === requestId,
          )
          const applied = state.application.find(
            (item) =>
              item.controller === processed?.controller &&
              item.appliedRequestId === requestId,
          )
          const reply = deliveryObject(lastReply?.wire.data)
          const replyRows = Array.isArray(reply.rows)
            ? reply.rows.map((row) => String(deliveryObject(row).rowKey))
            : undefined
          const filter = deliveryObject(lastSent?.wire.filter)
          const queryMatches =
            (filter.search === undefined ? '' : filter.search) === search &&
            applied?.search === search &&
            applied.domSearch === search &&
            JSON.stringify(applied.filter) === JSON.stringify(filter)
          const rowsMatch = [replyRows, applied?.rows, applied?.domRows].every(
            (keys) => JSON.stringify(keys) === JSON.stringify(rowKeys),
          )
          refusal =
            lastReply?.wire.type === 'table_window_refused' && applied
              ? lastReply
              : undefined
          const actual = {
            sameDocument: state.documentStarted === mark.documentStarted,
            sameSocket: state.socket === mark.socket,
            open: state.readyState === 1,
            freshRequest:
              lastSent !== undefined && lastSent.index >= mark.cursor,
            completed:
              applied !== undefined &&
              (refusal !== undefined || (queryMatches && rowsMatch)),
            problems,
          }
          condition = {
            mark,
            expected: { search, rowKeys },
            opening,
            subscription,
            lastSent,
            lastReply,
            processed,
            applied,
            queryMatches,
            rowsMatch,
            actual,
          }
          return actual
        })
        .toEqual({
          sameDocument: true,
          sameSocket: true,
          open: true,
          freshRequest: true,
          completed: true,
          problems: [],
        })
      if (refusal)
        throw new Error(
          `Latest table window refused: ${JSON.stringify(refusal.wire.data)}`,
        )
      return condition
    },
  }
}

/** Keep the actual opener identity through a modal and a table DOM replacement. */
export async function watchTableModalFocus(trigger: Locator) {
  const handle = await trigger.evaluateHandle((opener) => {
    const root = opener.closest('[data-id="hilos-viewport-table"]')!
    const id = opener.getAttribute('data-id')!
    const read = () => {
      const current = [
        ...root.querySelectorAll<HTMLElement>('[data-id]'),
      ].filter((node) => node.dataset.id === id && node.checkVisibility())
      const active = opener.ownerDocument.activeElement
      return {
        openerConnected: opener.isConnected,
        sameTrigger: current[0] === opener,
        visibleTriggers: current.length,
        active: active?.getAttribute('data-id'),
        activeTag: active?.tagName,
        activeId: active?.id,
        expanded: current[0]?.getAttribute('aria-expanded'),
        inDialog: active?.closest('[aria-modal="true"]') !== null,
        rootConnected: root.isConnected,
      }
    }
    const journal: {
      at: number
      source: string
      state: ReturnType<typeof read>
    }[] = []
    let previous = ''
    const record = (source: string) => {
      const state = read()
      const encoded = JSON.stringify(state)
      if (encoded === previous) return
      previous = encoded
      journal.push({ at: performance.now(), source, state })
    }
    const onFocus = () => record('focusin')
    const observer = new MutationObserver(() => record('mutation'))
    observer.observe(opener.ownerDocument.body, {
      childList: true,
      subtree: true,
    })
    opener.ownerDocument.addEventListener('focusin', onFocus)
    record('start')
    return {
      read,
      history: () => {
        record('read')
        return journal
      },
      stop: () => {
        observer.disconnect()
        opener.ownerDocument.removeEventListener('focusin', onFocus)
      },
    }
  })
  return {
    read: () => handle.evaluate((watch) => watch.read()),
    history: () => handle.evaluate((watch) => watch.history()),
    dispose: async () => {
      await handle.evaluate((watch) => watch.stop())
      await handle.dispose()
    },
  }
}
