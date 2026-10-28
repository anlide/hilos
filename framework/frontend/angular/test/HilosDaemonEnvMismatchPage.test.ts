import {
  DAEMON_ENV_MISMATCH_DATA,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosDaemonEnvMismatchSummary,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonEnvMismatchPage } from '../src/admin/daemon/HilosDaemonEnvMismatchPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

function harness() {
  return daemonHarness(
    HilosDaemonEnvMismatchPage,
    HilosPages.DAEMON_ENV_MISMATCH,
  )
}
function summary(
  overrides: Partial<
    NonNullable<HilosDaemonEnvMismatchSummary['comparison']>
  > = {},
): HilosDaemonEnvMismatchSummary {
  return {
    clustered: true,
    servingNodeId: 'n1',
    comparison: {
      nodes: ['n1', 'n2', 'n3'],
      answering: ['n1', 'n2'],
      diverged: 1,
      sourcesDiffer: 1,
      perNode: ['CLUSTER_NODE_ID'],
      ...overrides,
    },
  }
}
function row(key: string, same = false) {
  return {
    rowKey: key,
    slots: {
      key: {
        key,
        kind: same ? 'sourcesDiffer' : 'diverged',
        position: same ? 1 : 0,
        sensitive: false,
        value: HIDDEN_VALUE,
        cells: [
          {
            nodeId: 'n1',
            state: 'known',
            type: 'string',
            source: 'env',
            digest: 'aaaa1111',
          },
          {
            nodeId: 'n2',
            state: 'known',
            type: 'string',
            source: same ? 'example' : 'process',
            digest: same ? 'aaaa1111' : 'bbbb2222',
          },
          {
            nodeId: 'n3',
            state: 'unknown',
            type: null,
            source: null,
            digest: null,
          },
        ],
      },
    },
  }
}

describe('HilosDaemonEnvMismatchPage', () => {
  it('waits for the picture and shows the standalone explanation without starting tables', () => {
    const h = harness()
    expect(h.el('hilos-daemon-env-mismatch-waiting')).not.toBeNull()
    h.scope.data.set(DAEMON_ENV_MISMATCH_DATA, {
      clustered: false,
      servingNodeId: 'standalone',
      comparison: null,
    })
    h.detect()
    expect(
      h.el('hilos-daemon-env-mismatch-standalone-node')?.textContent,
    ).toContain('standalone')
    expect(h.el('hilos-daemon-env-mismatch-waiting')).toBeNull()
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('starts once for a cluster and renders diverged, source-only, per-node and unknown cells', () => {
    const h = harness()
    h.scope.data.set(DAEMON_ENV_MISMATCH_DATA, summary())
    h.detect()
    h.window('hilosDaemonEnvMismatch', [row('PORT'), row('HOST', true)])
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(
      h.el('hilos-daemon-env-mismatch-tile-answering')?.textContent,
    ).toContain('2 of 3')
    expect(
      h
        .el('hilos-daemon-env-mismatch-cell-PORT-n1')
        ?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(
      h.el('hilos-daemon-env-mismatch-cell-PORT-n2')?.getAttribute('data-kind'),
    ).toBe('digest')
    expect(
      h.el('hilos-daemon-env-mismatch-cell-PORT-n2')?.textContent,
    ).toContain('#bbbb')
    expect(
      h.el('hilos-daemon-env-mismatch-cell-PORT-n3')?.getAttribute('data-kind'),
    ).toBe('unknown')
    expect(
      h.el('hilos-daemon-env-mismatch-cell-PORT-n3')?.textContent,
    ).toContain('?')
    expect(
      h.el('hilos-daemon-env-mismatch-cell-HOST-n2-from-example')?.textContent,
    ).toContain('From .env.example')
    expect(
      h.el('hilos-daemon-env-mismatch-sources-differ-HOST')?.textContent,
    ).toContain('Sources differ')
    expect(
      h
        .el('hilos-daemon-env-mismatch-cell-HOST-n2')
        ?.classList.contains('bg-warning-subtle'),
    ).toBe(true)
    expect(
      h.root.querySelectorAll('[data-id="hilos-viewport-table"]'),
    ).toHaveLength(1)
    expect(
      h.el('hilos-daemon-env-mismatch-per-node-CLUSTER_NODE_ID'),
    ).not.toBeNull()
    // Updating membership changes both core columns and projected Angular cell templates.
    h.scope.data.set(
      DAEMON_ENV_MISMATCH_DATA,
      summary({ nodes: ['n1', 'n4'], answering: ['n1'] }),
    )
    h.detect()
    expect(h.root.querySelector('thead')?.textContent).toContain('n4')
    expect(
      h.el('hilos-daemon-env-mismatch-cell-PORT-n4')?.getAttribute('data-kind'),
    ).toBe('unknown')
    expect(h.el('hilos-daemon-env-mismatch-cell-PORT-n2')).toBeNull()
    expect(h.register).toHaveBeenCalledTimes(1)
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    expect(h.listeners()).toBe(0)
  })

  it('distinguishes alone and agree and removes source-only and per-node sections', () => {
    const h = harness()
    h.scope.data.set(
      DAEMON_ENV_MISMATCH_DATA,
      summary({
        answering: ['n1'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      }),
    )
    h.detect()
    h.window('hilosDaemonEnvMismatch', [])
    expect(h.el('hilos-daemon-env-mismatch-empty-alone')).not.toBeNull()
    h.scope.data.set(
      DAEMON_ENV_MISMATCH_DATA,
      summary({ diverged: 0, sourcesDiffer: 0, perNode: [] }),
    )
    h.detect()
    expect(h.el('hilos-daemon-env-mismatch-empty-agree')).not.toBeNull()
    expect(h.el('hilos-daemon-env-mismatch-per-node')).toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('keeps row values live, holds movement for Apply and recovers refusal without subscribing again', () => {
    const h = harness()
    h.scope.data.set(
      DAEMON_ENV_MISMATCH_DATA,
      summary({ diverged: 2, sourcesDiffer: 0 }),
    )
    h.detect()
    const a = row('A')
    const b = row('B')
    h.window('hilosDaemonEnvMismatch', [a, b])
    const address = {
      page: HilosPages.DAEMON_ENV_MISMATCH,
      tableKey: 'hilosDaemonEnvMismatch',
    }
    const updated = {
      ...a,
      slots: {
        key: { ...a.slots.key, value: { kind: 'text', text: 'after' } },
      },
    }
    h.emit('tableViewportDelta', {
      data: { ...address, kind: 'row_updated', rowKey: 'A', row: updated },
    })
    h.detect()
    expect(h.el('hilos-daemon-env-mismatch-cell-A-n1')?.textContent).toContain(
      'after',
    )
    expect(h.el('hilos-table-apply')).toBeNull()
    const moved = {
      ...updated,
      slots: {
        key: { ...updated.slots.key, kind: 'sourcesDiffer', position: 1 },
      },
    }
    const order = () =>
      Array.from(
        h.root.querySelectorAll('tbody tr[data-id^="hilos-table-row-"]'),
      ).map((el) => el.getAttribute('data-id'))
    h.emit('tableViewportDelta', {
      data: {
        ...address,
        kind: 'row_moved',
        rowKey: 'A',
        position: 1,
        row: moved,
      },
    })
    h.detect()
    expect(order()).toEqual(['hilos-table-row-A', 'hilos-table-row-B'])
    h.el('hilos-table-apply')!.click()
    h.detect()
    expect(order()).toEqual(['hilos-table-row-B', 'hilos-table-row-A'])
    const nameId = h.root
      .querySelector('table')
      ?.getAttribute('aria-labelledby')
    expect(h.root.querySelector(`[id="${nameId}"]`)?.textContent).toContain(
      'Daemon',
    )
    expect(
      h.root.querySelector('[role="list"]')?.getAttribute('aria-labelledby'),
    ).toBe(nameId)
    h.emit('tableWindowRefused', {
      data: { ...address, errorCode: 'unavailable' },
    })
    h.detect()
    expect(h.root.textContent).toContain('List unavailable')
    expect(h.el('hilos-daemon-env-mismatch-empty-agree')).toBeNull()
    h.window('hilosDaemonEnvMismatch', [moved])
    expect(h.el('hilos-daemon-env-mismatch-sources-differ-A')).not.toBeNull()
    expect(h.register).toHaveBeenCalledTimes(1)
    h.fixture.destroy()
  })

  it('tears down the binding and ignores a replaced context', () => {
    const h = harness()
    h.scope.data.set(DAEMON_ENV_MISMATCH_DATA, summary())
    h.detect()
    const scopes = new ScopeManager()
    const replacement = scopes.openPage(HilosPages.DAEMON_ENV_MISMATCH)
    replacement.data.set(DAEMON_ENV_MISMATCH_DATA, {
      clustered: false,
      servingNodeId: 'new-node',
      comparison: null,
    })
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    h.scope.data.set(DAEMON_ENV_MISMATCH_DATA, summary({ nodes: ['old-node'] }))
    h.detect()
    expect(
      h.el('hilos-daemon-env-mismatch-standalone-node')?.textContent,
    ).toContain('new-node')
    expect(h.register).toHaveBeenCalledTimes(1)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })
})
