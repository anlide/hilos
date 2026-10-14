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
    h.window('hilosDaemonEnvDiverged', [row('PORT')])
    h.window('hilosDaemonEnvSourcesDiffer', [row('HOST', true)])
    expect(h.register).toHaveBeenCalledTimes(2)
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
    expect(h.register).toHaveBeenCalledTimes(2)
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(2)
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
    h.window('hilosDaemonEnvDiverged', [])
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

  it('tears down both bindings and ignores a replaced context', () => {
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
    expect(h.unregister).toHaveBeenCalledTimes(2)
    h.scope.data.set(DAEMON_ENV_MISMATCH_DATA, summary({ nodes: ['old-node'] }))
    h.detect()
    expect(
      h.el('hilos-daemon-env-mismatch-standalone-node')?.textContent,
    ).toContain('new-node')
    expect(h.register).toHaveBeenCalledTimes(2)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })
})
