import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readClusterBrowserStand } from '../cluster.js'

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
