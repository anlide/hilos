import type { Expect, Page } from '@playwright/test'
import type { watchTableSearch } from './tableFocus.js'
import { deliveryObject } from './deliveryFrames.js'

type TableWatch = Awaited<ReturnType<typeof watchTableSearch>>
type TableMark = Awaited<ReturnType<TableWatch['mark']>>
type TableState = Awaited<ReturnType<TableWatch['read']>>
type Frame = TableState['frames'][number]

// The real chain coalesces a roster for 0.5s, publishes a node picture within
// 5s, then fans it out within 0.5s. This bounds a changed fact, never a quiet tick.
const SOURCE_TIMEOUT_MS = 10_000

function connections(frame: Frame): number | undefined {
  const data = deliveryObject(deliveryObject(frame.wire.data).payload).data
  const nodes = deliveryObject(deliveryObject(data).daemon).nodes
  if (!Array.isArray(nodes)) return undefined
  const node = nodes
    .map(deliveryObject)
    .find((node) => node.nodeId === 'standalone')
  const value = deliveryObject(node?.standing).connections
  return typeof value === 'number' ? value : undefined
}

/**
 * Read actual Daemon source changes through the existing physical-frame journal.
 * No messages are held, sent or synthesized. All raw packets and condition
 * failures stay in that journal and in this document-local evidence.
 */
export async function watchDaemonSource(windows: TableWatch, page: Page) {
  let initial = await windows.read()
  const latestConnections = (state: TableState) =>
    state.frames
      .filter((frame) => frame.direction === 'received')
      .map(connections)
      .filter((value) => value !== undefined)
      .at(-1)
  let initialConnections = latestConnections(initial)
  const evidence: unknown[] = []

  // Placement renders the complete overview as a prop, while its body is only
  // the matrix. Read that committed projection from Vue's live vnode tree,
  // just as watchTableSearch reads the mounted controller's props.
  const committedConnections = () =>
    page.evaluate(() => {
      interface VNode {
        readonly props?: {
          overview?: {
            nodes: readonly {
              nodeId: string
              standing: { connections: number } | null
            }[]
          } | null
        }
        readonly el?: Node
        readonly component?: { subTree: VNode; isUnmounted: boolean }
        readonly suspense?: { activeBranch?: VNode }
        readonly children?: unknown
      }
      const matrix = document.querySelector(
        '[data-id="hilos-daemon-placement-view"]',
      )
      let count: number | null = null
      const visit = (node: VNode | null | undefined): void => {
        if (!node || typeof node !== 'object') return
        if (matrix && node.el === matrix && node.el.isConnected) {
          const source = node.props?.overview?.nodes.find(
            (node) => node.nodeId === 'standalone',
          )
          if (typeof source?.standing?.connections === 'number')
            count = source.standing.connections
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
      return count
    })

  async function changed(
    expect: Expect,
    mark: TableMark,
    select: (frame: Frame) => boolean,
  ): Promise<Frame> {
    let selected: Frame | undefined
    let condition: unknown
    try {
      await expect
        .poll(
          async () => {
            const state = await windows.read()
            const frames = state.frames.filter(
              (frame) =>
                frame.socket === mark.socket && frame.index >= mark.cursor,
            )
            selected = frames.find(
              (frame) =>
                frame.direction === 'received' &&
                !frame.failure &&
                select(frame),
            )
            const replacements = frames.filter((frame) => {
              const wire = frame.wire
              const payload = deliveryObject(deliveryObject(wire.data).payload)
              return (
                (frame.direction === 'sent' &&
                  [
                    'page_subscribe',
                    'page_update_subscription',
                    'table_viewport',
                  ].includes(String(wire.type))) ||
                [
                  'table_window',
                  'table_window_refused',
                  'table_viewport_frozen',
                  'subscription_page_error',
                  'page_resubscribe',
                ].includes(String(wire.type)) ||
                (wire.type === 'page_response' &&
                  (payload.windows !== undefined ||
                    payload.refusedWindows !== undefined))
              )
            })
            const actual = {
              sameDocument: state.documentStarted === mark.documentStarted,
              sameSocket: state.socket === mark.socket,
              open: state.readyState === 1,
              changed: selected !== undefined,
              replacements: replacements.map((frame) => frame.wire),
            }
            condition = { mark, initialConnections, selected, actual }
            return actual
          },
          { timeout: SOURCE_TIMEOUT_MS },
        )
        .toEqual({
          sameDocument: true,
          sameSocket: true,
          open: true,
          changed: true,
          replacements: [],
        })
      const data = deliveryObject(selected!.wire.data)
      expect(data).not.toHaveProperty('requestId')
      expect(selected!.wire).not.toHaveProperty('requestId')
      expect(selected!.wire).not.toHaveProperty('deliveryId')
      expect(data).not.toHaveProperty('deliveryId')
      return selected!
    } finally {
      evidence.push(condition)
    }
  }

  return {
    read: () => evidence,
    /** Establish the one live test client, including withdrawal of an earlier test's client. */
    async singleConnection(expect: Expect) {
      await expect
        .poll(
          async () => {
            initial = await windows.read()
            initialConnections = latestConnections(initial)
            return initialConnections
          },
          { timeout: SOURCE_TIMEOUT_MS },
        )
        .toEqual(1)
      await expect
        .poll(committedConnections, { timeout: SOURCE_TIMEOUT_MS })
        .toEqual(1)
    },
    async connectionOpened(expect: Expect, mark: TableMark) {
      expect(typeof initialConnections).toBe('number')
      const frame = await changed(
        expect,
        mark,
        (frame) =>
          frame.wire.type === 'page_response' &&
          connections(frame) === initialConnections! + 1,
      )
      await expect
        .poll(committedConnections, { timeout: SOURCE_TIMEOUT_MS })
        .toEqual(initialConnections! + 1)
      return frame
    },
    async connectionClosed(expect: Expect, mark: TableMark) {
      expect(typeof initialConnections).toBe('number')
      const frame = await changed(
        expect,
        mark,
        (frame) =>
          frame.wire.type === 'page_response' &&
          connections(frame) === initialConnections,
      )
      await expect
        .poll(committedConnections, { timeout: SOURCE_TIMEOUT_MS })
        .toEqual(initialConnections)
      return frame
    },
    async workerAdded(expect: Expect, mark: TableMark, userId: number) {
      const agentId = `hilos_user:${userId}`
      const frame = await changed(expect, mark, (frame) => {
        const data = deliveryObject(frame.wire.data)
        const worker = deliveryObject(
          deliveryObject(deliveryObject(data.row).slots).worker,
        )
        return (
          frame.wire.type === 'table_viewport_delta' &&
          data.kind === 'row_updated' &&
          (data.own === undefined || data.own === false) &&
          Array.isArray(worker.agentIds) &&
          worker.agentIds.includes(agentId)
        )
      })
      const data = deliveryObject(frame.wire.data)
      const before = initial.frames.filter(
        (item) => item.direction === 'received',
      )
      // The new owner must be absent from the source before the actor creates it.
      expect(
        before.some((item) =>
          JSON.stringify(item.wire).includes(`"${agentId}"`),
        ),
      ).toBe(false)
      expect(
        initial.application.some((table) =>
          table.rows.includes(String(data.rowKey)),
        ),
      ).toBe(true)
      const worker = deliveryObject(
        deliveryObject(deliveryObject(data.row).slots).worker,
      )
      // Workers declares Worker / Kind / PID / Agents / Memory / Actions.
      // Read the changed Agents body cell without touching a native control.
      await expect(
        page
          .getByTestId(`hilos-table-row-${String(data.rowKey)}`)
          .locator('td')
          .nth(3),
      ).toHaveText(String(worker.agentCount))
      return { frame, agentId, rowKey: String(data.rowKey) }
    },
  }
}
