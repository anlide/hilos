import { describe, expect, it } from 'vitest'

import {
  createHilosLogRotationsActions,
  formatRetentionRule,
  formatRotationFileCounts,
  formatRotationRule,
  formatRotationState,
  formatRotationWeight,
  hasRotationNodes,
  resolveHilosLogRotationRow,
  rotationsEmptyState,
  rotationTakeoutAddress,
  rotationTakeoutCommand,
  HILOS_ROTATION_STATE_DUE,
  HILOS_ROTATION_STATE_TAKEN,
  LOGS_SIGNAL_SCHEMAS,
  LOGS_TAKEOUT_CONFIRM_ACTION,
  LOGS_TAKEOUT_UNDO_ACTION,
  ROTATIONS_HEADER_SIGNAL,
  type HilosLogRotationRow,
  type HilosLogRotationsContext,
  type HilosLogRotationsHeader,
} from '../../../src/admin/logs/hilosLogRotations.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(
  overrides: Partial<HilosLogRotationRow> = {},
): HilosLogRotationRow {
  return {
    rowKey: 'node-1:1800000000',
    batchAt: 1800000000,
    node: 'node-1',
    path: 'archive/2027-01-15-08-00-00/',
    absolutePath: '/var/log/hilos/archive/2027-01-15-08-00-00/',
    agentFileCount: 12,
    workerFileCount: 8,
    workerMonopolisticFileCount: 2,
    bytes: 1024,
    retentionState: 'kept',
    pruneNotBefore: null,
    ...overrides,
  }
}

function header(
  overrides: Partial<HilosLogRotationsHeader> = {},
): HilosLogRotationsHeader {
  return {
    available: true,
    nodes: [],
    rotationCron: null,
    rotationMaxAgeSeconds: 0,
    rotationMaxLiveSizeBytes: 0,
    retentionKeepBatches: 0,
    retentionMaxAgeSeconds: 0,
    ...overrides,
  }
}

function rotationTableRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { batch: slot } }
}

describe('resolveHilosLogRotationRow', () => {
  it('reads the batch slot into the view-model', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('node-2:1800000000', {
        batchAt: 1800000000,
        node: 'node-2',
        path: 'archive/2027-01-15-08-00-00/',
        absolutePath: '/var/log/hilos/archive/2027-01-15-08-00-00/',
        agentFileCount: 11,
        workerFileCount: 8,
        workerMonopolisticFileCount: 2,
        bytes: 4096,
        retentionState: HILOS_ROTATION_STATE_DUE,
        pruneNotBefore: 1800086400,
      }),
    )

    expect(resolved).toEqual({
      rowKey: 'node-2:1800000000',
      batchAt: 1800000000,
      node: 'node-2',
      path: 'archive/2027-01-15-08-00-00/',
      absolutePath: '/var/log/hilos/archive/2027-01-15-08-00-00/',
      agentFileCount: 11,
      workerFileCount: 8,
      workerMonopolisticFileCount: 2,
      bytes: 4096,
      retentionState: HILOS_ROTATION_STATE_DUE,
      pruneNotBefore: 1800086400,
    })
  })

  it('keeps a nameless node null, because that is the single-node installation', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('-:1800000000', { batchAt: 1800000000, bytes: 10 }),
    )

    expect(resolved.node).toBeNull()
  })

  it('keeps an unreported log root null, so no address is invented for it', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('node-1:1800000000', { batchAt: 1800000000, bytes: 10 }),
    )

    expect(resolved.absolutePath).toBeNull()
  })

  it('keeps an absent prune deadline null, which the modal says in words', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('node-1:1800000000', { batchAt: 1800000000, bytes: 10 }),
    )

    expect(resolved.pruneNotBefore).toBeNull()
  })

  it('takes the identity from the row key, never from inside the slot', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('node-1:1800000000', undefined),
    )

    expect(resolved.rowKey).toBe('node-1:1800000000')
    expect(resolved.batchAt).toBe(0)
  })
})

describe('the header schema', () => {
  it('is registered under the page signal the backend sends it as', () => {
    expect(Object.keys(LOGS_SIGNAL_SCHEMAS)).toEqual([ROTATIONS_HEADER_SIGNAL])
  })

  it('accepts the third availability state, which is a null and not a false', () => {
    const parsed = LOGS_SIGNAL_SCHEMAS[ROTATIONS_HEADER_SIGNAL].safeParse({
      ...header(),
      available: null,
    })

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.available).toBeNull()
  })

  it('refuses a payload whose node list is not a list of names', () => {
    const parsed = LOGS_SIGNAL_SCHEMAS[ROTATIONS_HEADER_SIGNAL].safeParse({
      ...header(),
      nodes: 'node-1',
    })

    expect(parsed.success).toBe(false)
  })

  it('refuses a payload missing a rule the screen has to print', () => {
    const withoutKeepBatches: Record<string, unknown> = { ...header() }
    delete withoutKeepBatches.retentionKeepBatches
    const parsed =
      LOGS_SIGNAL_SCHEMAS[ROTATIONS_HEADER_SIGNAL].safeParse(withoutKeepBatches)

    expect(parsed.success).toBe(false)
  })
})

describe('hasRotationNodes', () => {
  it('is false for a single-node installation, which names no node at all', () => {
    expect(hasRotationNodes(header({ nodes: [] }))).toBe(false)
  })

  it('is false before the header arrives, so no column flashes on entry', () => {
    expect(hasRotationNodes(null)).toBe(false)
  })

  it('is true once the picture names nodes', () => {
    expect(hasRotationNodes(header({ nodes: ['node-1', 'node-2'] }))).toBe(true)
  })
})

describe('rotationsEmptyState', () => {
  it('waits rather than reporting a fault before any picture arrives', () => {
    expect(rotationsEmptyState(null, 0, false)).toBe('unknown')
    expect(rotationsEmptyState(header({ available: null }), 0, false)).toBe(
      'unknown',
    )
  })

  it('reports the fault when the picture arrived and nothing could be read', () => {
    expect(rotationsEmptyState(header({ available: false }), 0, false)).toBe(
      'unreadable',
    )
  })

  it('tells an installation that never rotated from a filter that matched nothing', () => {
    expect(rotationsEmptyState(header(), 0, false)).toBe('never')
    expect(rotationsEmptyState(header(), 0, true)).toBe('nomatch')
  })

  it('says nothing at all when there are rows', () => {
    expect(rotationsEmptyState(header(), 3, true)).toBe('rows')
  })

  it('lets an unreadable picture outrank an empty window, which it explains', () => {
    expect(rotationsEmptyState(header({ available: false }), 0, true)).toBe(
      'unreadable',
    )
  })
})

describe('formatRotationWeight', () => {
  it('reports a zero-byte batch as a measurement and not as missing data', () => {
    expect(formatRotationWeight(row({ bytes: 0 }))).toBe('0 B')
  })

  it('climbs to the largest unit that leaves a readable number', () => {
    expect(formatRotationWeight(row({ bytes: 1024 }))).toBe('1.0 KB')
    expect(formatRotationWeight(row({ bytes: 1536 * 1024 * 1024 }))).toBe(
      '1.5 GB',
    )
  })
})

describe('formatRotationFileCounts', () => {
  it('shows the three classes an operator acts on, daemon streams apart', () => {
    expect(formatRotationFileCounts(row())).toBe('12 / 8 / 2')
  })
})

describe('formatRotationState', () => {
  it('labels the verdicts this build knows', () => {
    expect(formatRotationState(row())).toBe('Kept')
    expect(
      formatRotationState(row({ retentionState: HILOS_ROTATION_STATE_DUE })),
    ).toBe('Awaiting carry-off')
    expect(
      formatRotationState(row({ retentionState: HILOS_ROTATION_STATE_TAKEN })),
    ).toBe('Taken — removed at the next cleanup')
  })

  /**
   * The taken badge names the consequence and not only the fact: confirming a
   * takeout is the one permission that lets the batch be deleted, and an operator
   * who reads "Taken" alone is not told what they have just allowed.
   */
  it('says of a taken batch what happens to it next', () => {
    expect(
      formatRotationState(row({ retentionState: HILOS_ROTATION_STATE_TAKEN })),
    ).toContain('cleanup')
  })

  it('prints a verdict it does not know rather than calling it protected', () => {
    expect(formatRotationState(row({ retentionState: 'evicted' }))).toBe(
      'evicted',
    )
  })
})

describe('formatRotationRule', () => {
  it('lists every axis that is on, and joins them as alternatives', () => {
    expect(
      formatRotationRule(
        header({
          rotationCron: '0 4 * * *',
          rotationMaxAgeSeconds: 3600,
          rotationMaxLiveSizeBytes: 512 * 1024 * 1024,
        }),
      ),
    ).toBe(
      'Rotates on the schedule 0 4 * * *, or 1 h after the last rotation, or when the live logs reach 512.0 MB',
    )
  })

  it('leaves a disabled axis out instead of printing it as a zero', () => {
    expect(formatRotationRule(header({ rotationMaxLiveSizeBytes: 1024 }))).toBe(
      'Rotates when the live logs reach 1.0 KB',
    )
  })

  it('says outright that nothing but a restart rotates, rather than printing a blank', () => {
    expect(formatRotationRule(header())).toBe(
      'Rotates only when the node restarts',
    )
  })
})

describe('formatRetentionRule', () => {
  it('joins the two criteria with "and", because both hold at once', () => {
    expect(
      formatRetentionRule(
        header({ retentionKeepBatches: 7, retentionMaxAgeSeconds: 2592000 }),
      ),
    ).toBe(
      'Recommends carrying off a batch outside the newest 7 and older than 30 d',
    )
  })

  it('says outright that nothing is ever recommended when both criteria are off', () => {
    expect(formatRetentionRule(header())).toBe(
      'Nothing is ever recommended for carrying off',
    )
  })
})

describe('rotationTakeoutAddress', () => {
  it('leads with the node, because the batch is on that machine and only there', () => {
    expect(rotationTakeoutAddress(row())).toBe(
      'node-1:/var/log/hilos/archive/2027-01-15-08-00-00/',
    )
  })

  it('is the path alone where there is no node to name', () => {
    expect(rotationTakeoutAddress(row({ node: null }))).toBe(
      '/var/log/hilos/archive/2027-01-15-08-00-00/',
    )
  })

  it('has nothing to say when the node reported no log root', () => {
    expect(rotationTakeoutAddress(row({ absolutePath: null }))).toBeNull()
  })
})

describe('rotationTakeoutCommand', () => {
  it('copies the batch into a folder named after it, under the node it came from', () => {
    expect(rotationTakeoutCommand(row())).toBe(
      'rsync -a node-1:/var/log/hilos/archive/2027-01-15-08-00-00/ ./cold-logs/node-1/2027-01-15-08-00-00/',
    )
  })

  it('drops the node level of the destination where there is no node', () => {
    expect(rotationTakeoutCommand(row({ node: null }))).toBe(
      'rsync -a /var/log/hilos/archive/2027-01-15-08-00-00/ ./cold-logs/2027-01-15-08-00-00/',
    )
  })

  it('offers no command when there is no address to copy from', () => {
    expect(rotationTakeoutCommand(row({ absolutePath: null }))).toBeNull()
  })
})

describe('createHilosLogRotationsActions', () => {
  /** A context whose action lifecycle records what was dispatched over it. */
  function dispatchContext(
    sent: Array<{ action: string; payload: unknown }>,
  ): HilosLogRotationsContext {
    const actions = {
      dispatch(action: string, payload: unknown) {
        sent.push({ action, payload })

        return { done: Promise.resolve(), loading: null }
      },
    } as unknown as ActionLifecycle

    return { actions } as unknown as HilosLogRotationsContext
  }

  it('names the batch by the pair that identifies it — the node and the stamp', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosLogRotationsActions(dispatchContext(sent)).sendTakeoutConfirm(
      row({ node: 'node-2', batchAt: 1800000000 }),
    )

    expect(sent).toEqual([
      {
        action: LOGS_TAKEOUT_CONFIRM_ACTION,
        payload: { nodeId: 'node-2', batchTimestamp: 1800000000 },
      },
    ])
  })

  it('sends the empty id for a nameless node, which is how the wire says "this one"', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosLogRotationsActions(dispatchContext(sent)).sendTakeoutConfirm(
      row({ node: null }),
    )

    expect(sent[0]?.payload).toEqual({
      nodeId: '',
      batchTimestamp: 1800000000,
    })
  })

  it('withdraws under its own action name, naming the same batch', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosLogRotationsActions(dispatchContext(sent)).sendTakeoutUndo(
      row({ node: 'node-2', batchAt: 1800000000 }),
    )

    expect(sent).toEqual([
      {
        action: LOGS_TAKEOUT_UNDO_ACTION,
        payload: { nodeId: 'node-2', batchTimestamp: 1800000000 },
      },
    ])
  })

  it('withdraws from a nameless node with the empty id too', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosLogRotationsActions(dispatchContext(sent)).sendTakeoutUndo(
      row({ node: null }),
    )

    expect(sent[0]?.payload).toEqual({
      nodeId: '',
      batchTimestamp: 1800000000,
    })
  })
})
