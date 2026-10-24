import { expect, it } from 'vitest'

import {
  normalizeResults,
  partitionTests,
  plannedTest,
  quarantineFlake,
  quarantineIdentity,
  quarantineLine,
  readPlan,
  testSelector,
} from './quarantine.mjs'

const row = {
  project: 'desktop',
  file: 'tests/space name.spec.ts',
  line: 12,
  titlePath: ['suite [.*] Юникод', 'admin (a+b)?'],
  flake: 'F-023',
}

it('reads static or inherited tags and normalizes repeated flake numbers', () => {
  expect(quarantineFlake(['@other'])).toBeNull()
  expect(quarantineFlake(['@quarantine:F-023', '@quarantine:F-023'])).toBe(
    'F-023',
  )
  expect(quarantineFlake(['@quarantine:F-1234'])).toBe('F-1234')
  const parent = {
    type: 'describe',
    title: 'inherited',
    parent: null,
    project: () => ({ name: 'desktop' }),
  }
  expect(
    plannedTest(
      {
        title: 'test',
        parent,
        tags: ['@quarantine:F-023'],
        location: { file: '/root/tests/a.spec.ts', line: 8 },
      },
      '/root/tests',
    ),
  ).toEqual({
    project: 'desktop',
    file: 'a.spec.ts',
    line: 8,
    titlePath: ['inherited', 'test'],
    flake: 'F-023',
    selector: '[desktop] › a.spec.ts:8 › inherited › test',
  })
})

it.each([
  ['@quarantine:F-023', '@quarantine:F-024'],
  ['@quarantine:F-2'],
  ['@quarantine:F-023suffix'],
  ['x@quarantine:F-023'],
])('refuses conflicting or malformed quarantine tags %s', (...tags) => {
  expect(() => quarantineFlake(tags)).toThrow()
})

it('qualifies project and title, distinguishing variants at the same line', () => {
  const viewer = { ...row, titlePath: [row.titlePath[0], 'viewer (a+b)?'] }
  const otherProject = { ...row, project: 'mobile' }
  expect(testSelector(row)).toBe(
    '[desktop] › tests/space name.spec.ts:12 › suite [.*] Юникод › admin (a+b)?',
  )
  expect(new Set([row, viewer, otherProject].map(testSelector)).size).toBe(3)
  expect(partitionTests([row, { ...viewer, flake: null }])).toEqual({
    main: [{ ...viewer, flake: null }],
    quarantine: [row],
  })
})

it('refuses identities the native line format cannot represent safely', () => {
  expect(() => testSelector({ ...row, titlePath: ['a\nb'] })).toThrow()
  expect(() => testSelector({ ...row, titlePath: ['a › b'] })).toThrow()
})

it.each(['passed', 'failed', 'timedOut', 'skipped', 'interrupted', 'notRun'])(
  'preserves actual %s and encodes one JSON line',
  (status) => {
    const reason = 'a "quote"\nand a newline'
    const results = normalizeResults([row], [{ ...row, status, reason }])
    expect(results).toEqual([{ ...row, status, reason }])
    expect(quarantineLine(results[0]).split('\n')).toHaveLength(1)
    expect(
      JSON.parse(quarantineLine(results[0]).slice('hilos-quarantine: '.length)),
    ).toEqual(results[0])
  },
)

it('completes missing/partial results and global setup failures as notRun', () => {
  const other = { ...row, titlePath: ['other'] }
  expect(
    normalizeResults([row, other], [{ ...row, status: 'passed', reason: '' }], {
      reason: 'globalSetup failed',
    }),
  ).toEqual([
    { ...row, status: 'passed', reason: '' },
    { ...other, status: 'notRun', reason: 'globalSetup failed' },
  ])
  expect(normalizeResults([], [])).toEqual([])
  expect(normalizeResults([row], [])[0].status).toBe('notRun')
})

it('lets the host demote passed once while retaining a test failure', () => {
  for (const status of ['passed', 'failed', 'timedOut']) {
    const results = normalizeResults(
      [row, row],
      [{ ...row, status, reason: 'browser' }],
      { hostError: 'cleanup failed' },
    )
    expect(results).toHaveLength(1)
    expect(results[0].status).toBe(status === 'passed' ? 'failed' : status)
    expect(results[0].reason).toBe(
      status === 'passed' ? 'cleanup failed' : 'browser',
    )
  }
})

it('uses identity without line numbers and refuses duplicate/unknown results', () => {
  expect(quarantineIdentity(row)).toBe(quarantineIdentity({ ...row, line: 99 }))
  const observation = { ...row, status: 'passed' }
  expect(() => normalizeResults([row], [observation, observation])).toThrow(
    'Duplicate',
  )
  expect(() =>
    normalizeResults([row], [{ ...observation, status: 'flaky' }]),
  ).toThrow('Unknown')
})

it('validates the sidecar version and canonical selectors before using a plan', () => {
  const plan = { version: 1, tests: [{ ...row, selector: testSelector(row) }] }
  expect(readPlan(plan)).toEqual(plan.tests)
  expect(() => readPlan({ ...plan, version: 2 })).toThrow()
  expect(() =>
    readPlan({ ...plan, tests: [{ ...row, selector: 'broad' }] }),
  ).toThrow()
})
