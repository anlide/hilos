import type { Expect, Page } from '@playwright/test'

import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { shownByTestId, tableRowKeys } from './table.js'

// The e2e packages do not import framework source. These public names and words
// mirror core/src/admin/maintenance/hilosMaintenanceNodes.ts and HilosPages.
export const MAINTENANCE_NODES_PATH = '/hilos/maintenance/nodes'
const PAGE = 'hilos_maintenance_nodes'
const TABLE = 'hilosMaintenanceNodes'
const TABLE_ID = 'hilos-maintenance-nodes-table'

/**
 * Read a node's actual report and role from the visible table projection.
 *
 * @param expect The spec's expect.
 * @param page The Nodes observer.
 * @param nodeId A real stand member.
 * @param phase The phase reported by that node.
 * @param quiesced The acknowledgement text, independent of the phase.
 * @param role The reported leader and initiator role text.
 */
export async function expectMaintenanceNode(
  expect: Expect,
  page: Page,
  nodeId: string,
  phase: string,
  quiesced: 'Yes' | 'No' | '—',
  role: string,
): Promise<void> {
  const table = page.getByTestId(TABLE_ID)
  const row = shownByTestId(table, `hilos-table-row-${nodeId}`)
  await expect(
    row.getByTestId(`hilos-maintenance-nodes-row-${nodeId}`),
  ).toHaveText(nodeId)
  await expect(row.getByTestId('hilos-table-row-message')).toHaveCount(0)
  // Read the four declared columns; the table also owns a trailing marks cell.
  const cells = row.getByRole('cell')
  await expect(cells.nth(0)).toHaveText(nodeId)
  await expect(cells.nth(1)).toHaveText(quiesced)
  await expect(cells.nth(2)).toHaveText(phase)
  await expect(cells.nth(3)).toHaveText(role)
}

/**
 * Prove a fresh initial page window contains every real member's current report,
 * then read the inactive facts in Vue. Later deltas cannot fill a missing initial.
 *
 * @param expect The spec's expect.
 * @param page The Nodes observer after cold entry or reload.
 * @param log The delivery log armed before navigation.
 * @param nodeIds All masters and slaves of this stand, excluding foreign nodes.
 * @param leaderId The leader recognized by the cluster.
 * @param after Packet bookmark taken before this navigation.
 */
export async function expectMaintenanceNodesInitial(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  nodeIds: readonly string[],
  leaderId: string,
  after = 0,
): Promise<void> {
  const replies = () =>
    log.packets
      .slice(after)
      .flatMap((packet) => packet.frames)
      .filter(({ wire }) => {
        const frame = deliveryObject(wire)
        return (
          frame.type === 'page_response' &&
          deliveryObject(frame.data).page === PAGE
        )
      })
  await expect.poll(() => replies().length).toBeGreaterThan(0)
  const first = replies()[0]!
  expect(first.failure).toBeUndefined()
  const payload = deliveryObject(
    deliveryObject(deliveryObject(first.wire).data).payload,
  )
  const window = deliveryObject(deliveryObject(payload.windows)[TABLE])
  expect(window.rows).toEqual(expect.any(Array))
  const rows = (window.rows as unknown[]).map(deliveryObject)
  expect(rows.map((row) => row.rowKey).sort()).toEqual([...nodeIds].sort())
  expect(window.totalCount).toBe(nodeIds.length)
  expect(window.totalExact).toBe(true)
  for (const row of rows) {
    expect(deliveryObject(row.slots).node).toEqual({
      nodeId: row.rowKey,
      phase: 'inactive',
      quiesced: null,
      isLeader: row.rowKey === leaderId,
      isInitiator: false,
      reportState: 'current',
    })
  }
  await expect
    .poll(() => tableRowKeys(page, TABLE_ID))
    .toEqual([...nodeIds].sort())
  for (const nodeId of nodeIds) {
    await expectMaintenanceNode(
      expect,
      page,
      nodeId,
      'inactive',
      '—',
      nodeId === leaderId ? 'Leader' : '—',
    )
  }
}
