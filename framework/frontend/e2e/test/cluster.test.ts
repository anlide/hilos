import {
  mkdirSync,
  mkdtempSync,
  openSync,
  renameSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  activeClusterAgents,
  readClusterBrowserStand,
  readClusterNodeNames,
  readClusterNodeJournal,
} from '../cluster.js'

vi.mock('node:fs', async (original) => {
  const fs = await original<typeof import('node:fs')>()
  return { ...fs, openSync: vi.fn(fs.openSync) }
})

const nodeNames = {
  m1: 'Главный, узел=🌍',
  m2: 'Second master',
  s1: 'Data node',
}

afterEach(() => vi.unstubAllEnvs())

describe('cluster node names', () => {
  beforeEach(() => {
    for (const key of [
      'BASE_URL',
      'CLUSTER_LEADER',
      'CLUSTER_FOLLOWER',
      'CLUSTER_MASTERS',
      'CLUSTER_SLAVES',
      'CLUSTER_NODE_HOSTS',
      'CLUSTER_NODE_IPS',
      'CLUSTER_NODE_LOGS',
    ]) {
      vi.stubEnv(key, undefined)
    }
  })

  it('preserves free-text names without other stand variables', () => {
    vi.stubEnv('CLUSTER_NODE_NAMES', JSON.stringify(nodeNames))
    expect(readClusterNodeNames()).toEqual(nodeNames)
  })

  it.each([undefined, '', '   ', '{', 'null', '[]', '{}', '42', '"name"'])(
    'refuses a missing, malformed or empty name map: %s',
    (value) => {
      vi.stubEnv('CLUSTER_NODE_NAMES', value)
      expect(() => readClusterNodeNames()).toThrow()
    },
  )

  it.each([null, 42, '', '   '])('refuses an invalid node name: %s', (name) => {
    vi.stubEnv('CLUSTER_NODE_NAMES', JSON.stringify({ ...nodeNames, m2: name }))
    expect(() => readClusterNodeNames()).toThrow(
      'Invalid cluster node name for m2',
    )
  })
})

describe('cluster browser environment', () => {
  beforeEach(() => {
    for (const [key, value] of Object.entries({
      BASE_URL: 'https://entry.example.test',
      CLUSTER_LEADER: 'm1',
      CLUSTER_FOLLOWER: 'm2',
      CLUSTER_MASTERS: 'm1,m2',
      CLUSTER_SLAVES: 's1',
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

  it('preserves free-text names alongside every member address', () => {
    expect(readClusterBrowserStand()).toEqual({
      baseURL: 'https://entry.example.test',
      leader: 'm1',
      follower: 'm2',
      masters: ['m1', 'm2'],
      slaves: ['s1'],
      nodeNames,
      nodeHosts: { m1: 'master-one', m2: 'master-two', s1: 'slave-one' },
      nodeIps: { m1: '192.0.2.1', m2: '192.0.2.2', s1: '192.0.2.3' },
      nodeLogs: { m1: '/logs/m1', m2: '/logs/m2', s1: '/logs/s1' },
    })
  })

  it('accepts an empty slave list', () => {
    vi.stubEnv('CLUSTER_SLAVES', '')
    expect(readClusterBrowserStand().slaves).toEqual([])
  })

  it('refuses a slave that is also a master', () => {
    vi.stubEnv('CLUSTER_SLAVES', 'm1')
    expect(() => readClusterBrowserStand()).toThrow(
      'Invalid cluster slave roles',
    )
  })

  it('refuses a duplicate slave', () => {
    vi.stubEnv('CLUSTER_SLAVES', 's1,s1')
    expect(() => readClusterBrowserStand()).toThrow(
      'Invalid cluster slave roles',
    )
  })

  it('refuses a slave absent from every address map', () => {
    vi.stubEnv('CLUSTER_SLAVES', 's1,s2')
    expect(() => readClusterBrowserStand()).toThrow(
      'Incomplete cluster address maps for s2',
    )
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

  it('follows starts across staging and archive and retains the bytes for attachment', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'stock' started on worker #1",
    )
    const batch = '2026-11-07-03-00-04Z'
    const staged = join(directory, 'staging', batch)
    mkdirSync(staged, { recursive: true })
    renameSync(path, join(staged, 'daemon.log'))
    expect(activeClusterAgents(path, ['stock'])).toEqual(['stock'])
    journal("Agent 'stock' started on worker #2")
    const snapshot = readClusterNodeJournal(path)
    const archived = join(directory, 'archive', batch)
    mkdirSync(join(directory, 'archive'))
    renameSync(staged, archived)
    expect(readClusterNodeJournal(path)).toEqual(snapshot)
    expect(activeClusterAgents(path, ['stock'])).toEqual(['stock', 'stock'])
    journal(
      'Daemon stopped',
      'Daemon started with epoll',
      "Agent 'new' started on worker #3",
    )
    expect(activeClusterAgents(path, ['stock', 'new'])).toEqual(['new'])
    rmSync(archived, { recursive: true })
    expect(snapshot.toString()).toContain("Agent 'stock' started on worker #1")
  })

  it('confirms the same segment when the carrier moves it during the read', async () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'stock' started on worker #1",
    )
    const batch = '2026-11-07-03-00-04Z'
    const staged = join(directory, 'staging', batch)
    mkdirSync(staged, { recursive: true })
    mkdirSync(join(directory, 'archive'))
    renameSync(path, join(staged, 'daemon.log'))
    const fs = await vi.importActual<typeof import('node:fs')>('node:fs')
    vi.mocked(openSync).mockImplementationOnce((...args) => {
      const descriptor = fs.openSync(...args)
      renameSync(staged, join(directory, 'archive', batch))
      return descriptor
    })
    expect(activeClusterAgents(path, ['stock'])).toEqual(['stock'])
  })

  it('replays a complete new boot after an interrupted batch and attaches both verbatim', () => {
    const path = journal(
      'Daemon started with epoll',
      "Agent 'stock' started on worker #7",
    )
    const staged = join(directory, 'staging', '2026-11-07-03-00-04Z')
    mkdirSync(staged, { recursive: true })
    const interrupted = Buffer.from("[test] Agent 'old' started on worker #2")
    writeFileSync(join(staged, 'daemon.log'), interrupted)
    expect(readClusterNodeJournal(path)).toEqual(
      Buffer.concat([
        interrupted,
        Buffer.from(
          "[test] Daemon started with epoll\n[test] Agent 'stock' started on worker #7\n",
        ),
      ]),
    )
    expect(activeClusterAgents(path, ['old', 'stock'])).toEqual(['stock'])
    journal(
      'previous message [test] Daemon started with epoll',
      "Agent 'stock' started on worker #7",
    )
    expect(() => activeClusterAgents(path, ['stock'])).toThrow(
      'Incomplete daemon journal',
    )
  })

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
