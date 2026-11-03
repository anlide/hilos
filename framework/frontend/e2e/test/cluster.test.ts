import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { activeClusterAgents, readClusterBrowserStand } from '../cluster.js'

const nodeNames = {
  m1: 'Главный, узел=🌍',
  m2: 'Second master',
  s1: 'Data node',
}

beforeEach(() => {
  for (const [key, value] of Object.entries({
    BASE_URL: 'https://entry.example.test',
    CLUSTER_LEADER: 'm1',
    CLUSTER_FOLLOWER: 'm2',
    CLUSTER_MASTERS: 'm1,m2',
    CLUSTER_NODE_HOSTS: 'm1=master-one,m2=master-two,s1=slave-one',
    CLUSTER_NODE_IPS: 'm1=192.0.2.1,m2=192.0.2.2,s1=192.0.2.3',
    CLUSTER_NODE_NAMES: JSON.stringify(nodeNames),
    CLUSTER_NODE_LOGS: JSON.stringify({
      m1: '/logs/m1',
      m2: '/logs/m2',
      s1: '/logs/s1',
    }),
  })) {
    vi.stubEnv(key, value)
  }
})

afterEach(() => vi.unstubAllEnvs())

describe('cluster browser environment', () => {
  it('preserves free-text names alongside every member address', () => {
    expect(readClusterBrowserStand()).toEqual({
      baseURL: 'https://entry.example.test',
      leader: 'm1',
      follower: 'm2',
      masters: ['m1', 'm2'],
      nodeNames,
      nodeHosts: { m1: 'master-one', m2: 'master-two', s1: 'slave-one' },
      nodeIps: { m1: '192.0.2.1', m2: '192.0.2.2', s1: '192.0.2.3' },
      nodeLogs: { m1: '/logs/m1', m2: '/logs/m2', s1: '/logs/s1' },
    })
  })

  it.each(['', '{', 'null', '[]', '42', '"name"'])(
    'refuses a missing or malformed name map: %s',
    (value) => {
      vi.stubEnv('CLUSTER_NODE_NAMES', value)
      expect(() => readClusterBrowserStand()).toThrow()
    },
  )

  it.each([null, 42, '', '   '])('refuses an invalid node name: %s', (name) => {
    vi.stubEnv('CLUSTER_NODE_NAMES', JSON.stringify({ ...nodeNames, m2: name }))
    expect(() => readClusterBrowserStand()).toThrow(
      'Invalid cluster node name for m2',
    )
  })

  it.each([
    [
      'CLUSTER_NODE_NAMES',
      JSON.stringify({ m1: nodeNames.m1, m2: nodeNames.m2 }),
    ],
    ['CLUSTER_NODE_HOSTS', 'm1=master-one,m2=master-two'],
    ['CLUSTER_NODE_IPS', 'm1=192.0.2.1,m2=192.0.2.2'],
    ['CLUSTER_NODE_LOGS', JSON.stringify({ m1: '/logs/m1', m2: '/logs/m2' })],
  ])('refuses an incomplete member map: %s', (key, value) => {
    vi.stubEnv(key, value)
    expect(() => readClusterBrowserStand()).toThrow(
      'Incomplete cluster address maps for s1',
    )
  })

  it('refuses a name for a node with no corresponding addresses', () => {
    vi.stubEnv(
      'CLUSTER_NODE_NAMES',
      JSON.stringify({ ...nodeNames, x1: 'Foreign' }),
    )
    expect(() => readClusterBrowserStand()).toThrow(
      'Incomplete cluster address maps for x1',
    )
  })

  it.each([
    ['CLUSTER_NODE_HOSTS', 'm1=master-one,m1=master-two', 'Duplicate node'],
    [
      'CLUSTER_NODE_IPS',
      'm1=bad,m2=192.0.2.2,s1=192.0.2.3',
      'Invalid cluster IP',
    ],
    [
      'CLUSTER_NODE_LOGS',
      JSON.stringify({ m1: 'relative' }),
      'Invalid cluster log path',
    ],
  ])('keeps refusing malformed existing maps: %s', (key, value, reason) => {
    vi.stubEnv(key, value)
    expect(() => readClusterBrowserStand()).toThrow(reason)
  })
})

describe('current cluster agent journal', () => {
  let directory: string

  beforeEach(() => {
    directory = mkdtempSync(join(tmpdir(), 'hilos-cluster-journal-'))
  })

  afterEach(() => rmSync(directory, { recursive: true, force: true }))

  /** Write complete daemon lines to a real, test-owned journal. */
  function journal(...lines: string[]): string {
    const path = join(directory, 'daemon.log')
    writeFileSync(path, lines.map((line) => `[test] ${line}\n`).join(''))
    return path
  }

  it('starts a new daemon life without agents from the previous life', () => {
    const path = journal(
      "Agent 'hilos_moderation' started on worker #1",
      'Daemon started with epoll',
      "Agent 'hilos_moderation' started on worker #1",
      'Daemon started with epoll',
      "Agent 'hilos_files' started on worker #2",
    )
    expect(
      activeClusterAgents(path, ['hilos_moderation', 'hilos_files']),
    ).toEqual(['hilos_files'])
  })

  it.each(['Daemon stopped', 'Daemon stopped after a failure'])(
    'refuses a journal whose current daemon ended: %s',
    (stop) => {
      const path = journal(
        'Daemon started with epoll',
        "Agent 'hilos_moderation' started on worker #1",
        stop,
        "Agent 'hilos_moderation' started on worker #2",
      )
      expect(() => activeClusterAgents(path, ['hilos_moderation'])).toThrow(
        'No current daemon start',
      )
    },
  )

  it('ignores agent records while stopped and accepts a fresh empty daemon', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'hilos_moderation' started on worker #1",
      'Daemon stopped',
      "Agent 'hilos_moderation' started on worker #2",
      'Daemon started with epoll',
    )
    expect(activeClusterAgents(path, ['hilos_moderation'])).toEqual([])
  })

  it('drops every agent of a dead worker while keeping other workers', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'hilos_moderation' started on worker #1",
      "Agent 'hilos_files' started on worker #1",
      "Agent 'hilos_files' started on worker #2",
      'Worker #1 died hosting 2 agent(s): hilos_moderation, hilos_files',
    )
    expect(
      activeClusterAgents(path, ['hilos_moderation', 'hilos_files']),
    ).toEqual(['hilos_files'])
  })

  it('does not carry agents into a reused worker number without stop records', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'hilos_moderation' started on worker #1",
      "Agent 'hilos_files' started on worker #2",
      'Worker #1 started [type=agent]',
      "Agent 'hilos_files' started on worker #1",
    )
    expect(
      activeClusterAgents(path, ['hilos_moderation', 'hilos_files']),
    ).toEqual(['hilos_files', 'hilos_files'])
  })

  it('retains duplicate copies and stops only the named agent on its worker', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'hilos_moderation' started on worker #1",
      "Agent 'hilos_files' started on worker #1",
      "Agent 'hilos_moderation' started on worker #2",
      "Agent 'hilos_moderation' stopped on worker #1",
      "Agent 'hilos_files' started on worker #3",
    )
    expect(
      activeClusterAgents(path, ['hilos_moderation', 'hilos_files']),
    ).toEqual(['hilos_files', 'hilos_moderation', 'hilos_files'])
    expect(activeClusterAgents(path, ['hilos_moderation'])).toEqual([
      'hilos_moderation',
    ])
  })

  it('uses exact requested ids instead of Analytics names or a pattern', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'review.v2+name' started on worker #1",
      "Agent 'reviewXv2name' started on worker #2",
      "Agent 'hilos_analytics_writer' started on worker #3",
      "Agent 'hilos_moderation' starting on worker #4",
    )
    expect(activeClusterAgents(path, ['review.v2+name'])).toEqual([
      'review.v2+name',
    ])
    expect(activeClusterAgents(path, ['hilos_analytics_writer'])).toEqual([
      'hilos_analytics_writer',
    ])
  })

  it('refuses an incomplete lifecycle record for a requested agent', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'hilos_moderation' started on worker #1 (partial)",
    )
    expect(() => activeClusterAgents(path, ['hilos_moderation'])).toThrow(
      'Incomplete agent lifecycle record',
    )
  })

  it('refuses an unfinished last line even after a valid agent start', () => {
    const path = join(directory, 'daemon.log')
    writeFileSync(
      path,
      "[test] Daemon started with epoll\n[test] Agent 'hilos_moderation' started on worker #1",
    )
    expect(() => activeClusterAgents(path, ['hilos_moderation'])).toThrow(
      'Incomplete daemon journal',
    )
  })

  it('refuses agent records with no known daemon start', () => {
    const path = journal("Agent 'hilos_moderation' started on worker #1")
    expect(() => activeClusterAgents(path, ['hilos_moderation'])).toThrow(
      'No current daemon start',
    )
  })
})
