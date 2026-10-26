import { openNodePage } from './helpers/hilosDaemonNode.js'
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
  it('starts the catalog window with the existing default filter and draws the full payload summary', () => {
    const h = harness()
    expect(h.register).toHaveBeenCalledTimes(1)
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
    h.scope.data.set('node', {
      clustered: true,
      state: null,
      silentSince: null,
      role: null,
    })
    h.scope.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: true,
      keys,
    })
    h.window('hilosDaemonEnvKeys', windowRows([keys[0]!]))
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
    const rows = [
      ...(h.el('hilos-daemon-env-ladder')?.querySelectorAll('li') ?? []),
    ]
    expect(rows).toHaveLength(5)
    expect(rows.map((row) => row.querySelector('span')?.textContent)).toEqual([
      '1',
      '2',
      '3',
      '4',
      '',
    ])
    expect(
      rows.map((row) => row.querySelector('.badge')?.textContent?.trim()),
    ).toEqual([
      'Launch environment',
      '.env',
      '.env.example',
      'Catalog default',
      'No value',
    ])
    expect(rows.every((row) => !!row.querySelector('[title]'))).toBe(true)
    expect(
      h
        .el('hilos-daemon-env-ladder')
        ?.querySelector('ol')
        ?.getAttribute('aria-labelledby'),
    ).toBe('hilos-daemon-env-ladder-heading')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(1)
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
    h.scope.data.set('node', {
      clustered: true,
      state: null,
      silentSince: null,
      role: null,
    })
    h.scope.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: true,
      keys,
    })
    h.window('hilosDaemonEnvKeys', windowRows(keys))
    expect(
      h.el('hilos-table-row-HIDDEN')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(h.el('hilos-table-row-SECRET')?.textContent).toContain('13 chars')
    expect(h.el('hilos-table-row-BOOL')?.querySelector('input')?.checked).toBe(
      true,
    )
    expect(h.el('hilos-table-row-EMPTY')?.textContent).toContain('empty')
    expect(h.el('hilos-table-row-ORPHAN')).toBeNull()
    expect(h.root.textContent).not.toContain('visible-value')
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('mirrors attention changes, drives search and clears values when the node is silent', () => {
    const h = harness()
    h.window('hilosDaemonEnvKeys', [])
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
    h.scope.data.set('node', {
      clustered: true,
      state: null,
      silentSince: null,
      role: null,
    })
    h.scope.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: true,
      keys,
    })
    h.window('hilosDaemonEnvKeys', windowRows(keys))
    expect(h.root.textContent).toContain('visible-value')
    h.scope.data.set('node', {
      clustered: true,
      state: 'silent',
      silentSince: null,
      role: null,
    })
    h.scope.data.set(DAEMON_ENV_NODE_SILENT_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-env-silent')?.textContent).toContain(
      'nothing is shown',
    )
    expect(h.el('hilos-daemon-env-keys')).toBeNull()
    expect(h.root.textContent).not.toContain('visible-value')
    h.window('hilosDaemonEnvKeys', [])
    expect(h.el('hilos-daemon-env-keys-empty-silent')).not.toBeNull()
    expect(h.el('hilos-daemon-env-orphans-empty-silent')).toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('replaces the catalog table and all watchers on node and context changes', () => {
    const h = harness()
    h.route.set({
      page: HilosPages.DAEMON_ENV,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    h.window('hilosDaemonEnvKeys', [])
    expect(h.register.mock.calls.at(-1)?.[1].descriptor().filter).toEqual({
      node: 'n2',
    })
    const scopes = new ScopeManager()
    openNodePage(scopes, HilosPages.DAEMON_ENV)
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    h.scope.data.set('node', {
      clustered: true,
      state: 'silent',
      silentSince: null,
      role: null,
    })
    h.scope.data.set(DAEMON_ENV_NODE_SILENT_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-env-silent')).toBeNull()
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(3)
    expect(h.listeners()).toBe(0)
  })
})
