import {
  DAEMON_ENV_ENVIRONMENT_DATA,
  DAEMON_ENV_NODE_SILENT_DATA,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  type HilosDaemonEnvKeyRow,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosDaemonEnvPage } from '../src/admin/daemon/HilosDaemonEnvPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

function harness() {
  return daemonHarness(HilosDaemonEnvPage, HilosPages.DAEMON_ENV)
}
function key(
  name: string,
  overrides: Partial<HilosDaemonEnvKeyRow> = {},
): HilosDaemonEnvKeyRow {
  return {
    rowKey: name,
    key: name,
    type: 'string',
    required: false,
    declaredBy: 'framework',
    sensitive: false,
    perNode: false,
    source: 'process',
    value: { kind: 'text', text: 'visible-value' },
    disk: null,
    ...overrides,
  }
}
function windowRows(keys: readonly HilosDaemonEnvKeyRow[]) {
  return keys.map((key) => ({ rowKey: key.key, slots: { key } }))
}

describe('HilosDaemonEnvPage', () => {
  it('starts both node windows with the existing default filter and draws the full payload summary', () => {
    const h = harness()
    expect(h.register).toHaveBeenCalledTimes(2)
    h.window('hilosDaemonEnvKeys', [])
    expect(h.register.mock.calls[0]?.[1].descriptor().filter).toEqual({
      node: 'n1',
    })
    const keys = [
      key('MISSING', {
        required: true,
        source: 'missing',
        value: { kind: 'none' },
      }),
      key('SAMPLE', { source: 'example' }),
      key('DRIFT', {
        disk: { source: 'env', value: { kind: 'text', text: 'edited-value' } },
      }),
    ]
    h.scope.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: true,
      keys,
    })
    h.window('hilosDaemonEnvKeys', windowRows([keys[0]!]))
    h.window('hilosDaemonEnvOrphans', [])
    expect(h.el('hilos-daemon-env-keys')?.textContent?.trim()).toBe('3')
    expect(h.el('hilos-daemon-env-missing')?.textContent?.trim()).toBe('1')
    expect(h.el('hilos-daemon-env-example')?.textContent?.trim()).toBe('1')
    expect(h.el('hilos-daemon-env-drift')?.textContent?.trim()).toBe('1')
    expect(h.el('hilos-daemon-env-alarm-drift-DRIFT')?.textContent).toContain(
      'edited-value',
    )
    expect(h.el('hilos-daemon-node-state')).toBeNull()
    expect(h.el('hilos-daemon-env-ladder')?.textContent).toContain(
      'Launch environment',
    )
    expect(
      h
        .el('hilos-daemon-env-ladder')
        ?.querySelector('table')
        ?.getAttribute('aria-labelledby'),
    ).toBe('hilos-daemon-env-ladder-heading')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    expect(h.listeners()).toBe(0)
  })

  it('renders typed values, hidden marks and length-only secret and orphan readings', () => {
    const h = harness()
    const keys = [
      key('HIDDEN', { value: HIDDEN_VALUE }),
      key('SECRET', { sensitive: true, value: { kind: 'secret', length: 13 } }),
      key('BOOL', { type: 'boolean', value: { kind: 'text', text: 'true' } }),
      key('INTEGER', { type: 'integer', value: { kind: 'text', text: '42' } }),
      key('EMPTY', { value: { kind: 'text', text: '' } }),
    ]
    h.scope.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: true,
      keys,
    })
    h.window('hilosDaemonEnvKeys', windowRows(keys))
    h.window('hilosDaemonEnvOrphans', [
      {
        rowKey: 'ORPHAN',
        slots: {
          orphan: {
            key: 'ORPHAN',
            value: { kind: 'secret', length: 9 },
            similarTo: 'INTEGER',
          },
        },
      },
    ])
    expect(
      h.el('hilos-table-row-HIDDEN')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(h.el('hilos-table-row-SECRET')?.textContent).toContain('13 chars')
    expect(h.el('hilos-table-row-BOOL')?.querySelector('input')?.checked).toBe(
      true,
    )
    expect(h.el('hilos-table-row-EMPTY')?.textContent).toContain('empty')
    expect(h.el('hilos-table-row-ORPHAN')?.textContent).toContain('9 chars')
    expect(h.el('hilos-table-row-ORPHAN')?.textContent).toContain('INTEGER')
    expect(h.root.textContent).not.toContain('visible-value')
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('mirrors attention changes, drives search and clears values when the node is silent', () => {
    const h = harness()
    h.window('hilosDaemonEnvKeys', [])
    h.window('hilosDaemonEnvOrphans', [])
    expect(h.el('hilos-daemon-env-keys-empty-attention')).toBeNull()
    const attention = h.root.querySelector<HTMLInputElement>(
      'input[data-id^="hilos-table-filter-attention"]',
    )!
    attention.click()
    h.detect()
    h.window('hilosDaemonEnvKeys', [])
    expect(h.el('hilos-daemon-env-keys-empty-attention')).not.toBeNull()
    attention.click()
    h.detect()
    h.window('hilosDaemonEnvKeys', [])
    expect(h.el('hilos-daemon-env-keys-empty-attention')).toBeNull()
    const search = h.el('hilos-table-search') as HTMLInputElement
    search.value = 'PORT'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    h.detect()
    expect(h.sendViewport.mock.calls.at(-1)?.[2].filter).toMatchObject({
      search: 'PORT',
    })
    const keys = [key('PORT')]
    h.scope.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: true,
      keys,
    })
    h.window('hilosDaemonEnvKeys', windowRows(keys))
    expect(h.root.textContent).toContain('visible-value')
    h.scope.data.set(DAEMON_ENV_NODE_SILENT_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-env-silent')?.textContent).toContain(
      'nothing is shown',
    )
    expect(h.el('hilos-daemon-env-keys')).toBeNull()
    expect(h.root.textContent).not.toContain('visible-value')
    h.window('hilosDaemonEnvKeys', [])
    h.window('hilosDaemonEnvOrphans', [])
    expect(h.el('hilos-daemon-env-keys-empty-silent')).not.toBeNull()
    expect(h.el('hilos-daemon-env-orphans-empty-silent')).not.toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('replaces both tables and all watchers on node and context changes', () => {
    const h = harness()
    h.route.set({
      page: HilosPages.DAEMON_ENV,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    h.window('hilosDaemonEnvKeys', [])
    expect(h.register.mock.calls.at(-2)?.[1].descriptor().filter).toEqual({
      node: 'n2',
    })
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.DAEMON_ENV)
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    h.scope.data.set(DAEMON_ENV_NODE_SILENT_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-env-silent')).toBeNull()
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(6)
    expect(h.listeners()).toBe(0)
  })
})
