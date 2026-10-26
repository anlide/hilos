import { HIDDEN_VALUE, HilosPages } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonEnvOrphansPage } from '../src/admin/daemon/HilosDaemonEnvOrphansPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

function harness() {
  return daemonHarness(HilosDaemonEnvOrphansPage, HilosPages.DAEMON_ENV_ORPHANS)
}

describe('HilosDaemonEnvOrphansPage', () => {
  it('names its one table from the page heading and shows set, hidden and similar values', () => {
    const h = harness()
    h.scope.data.set('nodeSilent', false)
    h.window('hilosDaemonEnvOrphans', [
      {
        rowKey: 'DB_PASSWD',
        slots: {
          orphan: {
            key: 'DB_PASSWD',
            value: { kind: 'secret', length: 19 },
            similarTo: 'DB_PASSWORD',
          },
        },
      },
      {
        rowKey: 'PLAIN',
        slots: {
          orphan: { key: 'PLAIN', value: HIDDEN_VALUE, similarTo: null },
        },
      },
    ])
    const headings = [...h.root.querySelectorAll('h1')]
    expect(headings).toHaveLength(1)
    expect(headings[0]?.textContent?.trim()).toBe('n1')
    const tables = [...h.root.querySelectorAll('table.table-hover')]
    expect(tables).toHaveLength(1)
    expect(tables[0]?.getAttribute('aria-labelledby')).toBe(headings[0]?.id)
    expect(h.root.textContent).not.toContain('Orphans in .env')
    expect(h.el('hilos-table-row-DB_PASSWD')?.textContent).toContain(
      'Set · 19 chars',
    )
    expect(h.el('hilos-table-row-DB_PASSWD')?.textContent).toContain(
      'not in catalog',
    )
    expect(h.el('hilos-table-row-DB_PASSWD')?.textContent).toContain(
      'DB_PASSWORD',
    )
    expect(
      h.el('hilos-table-row-PLAIN')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(h.el('hilos-table-row-PLAIN')?.textContent).toContain('—')
    expect(h.el('hilos-table-row-PLAIN')?.textContent).not.toContain('19')
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('shows the live empty line and the silent line', () => {
    const h = harness()
    h.scope.data.set('nodeSilent', false)
    h.detect()
    h.window('hilosDaemonEnvOrphans', [])
    expect(h.el('hilos-daemon-env-orphans-empty')?.textContent?.trim()).toBe(
      'Every line in .env is declared in the catalog.',
    )
    h.scope.data.set('nodeSilent', true)
    h.detect()
    h.window('hilosDaemonEnvOrphans', [])
    expect(
      h.el('hilos-daemon-env-orphans-empty-silent')?.textContent?.trim(),
    ).toBe('Nothing is read while the node is silent.')
    h.fixture.destroy()
  })

  it('replaces the window when the node changes and releases it', () => {
    const h = harness()
    h.window('hilosDaemonEnvOrphans', [])
    h.route.set({
      page: HilosPages.DAEMON_ENV_ORPHANS,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    h.window('hilosDaemonEnvOrphans', [
      {
        rowKey: 'NEXT',
        slots: {
          orphan: { key: 'NEXT', value: HIDDEN_VALUE, similarTo: null },
        },
      },
    ])
    expect(h.root.querySelector('h1')?.textContent?.trim()).toBe('n2')
    expect(h.el('hilos-table-row-NEXT')).not.toBeNull()
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    expect(h.listeners()).toBe(0)
  })
})
