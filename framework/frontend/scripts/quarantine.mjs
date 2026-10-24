import { relative } from 'node:path'

export const QUARANTINE_TAG_PREFIX = '@quarantine:'
export const QUARANTINE_LINE_PREFIX = 'hilos-quarantine: '
const FLAKE_PATTERN = /^@quarantine:(F-[0-9]{3,})$/
const STATUSES = new Set([
  'passed',
  'failed',
  'timedOut',
  'skipped',
  'interrupted',
  'notRun',
])

/** Read static tags, including the inherited tags exposed by TestCase.tags. */
export function quarantineFlake(tags) {
  const flakes = new Set()
  for (const tag of tags) {
    if (!tag.includes(QUARANTINE_TAG_PREFIX)) continue
    const match = FLAKE_PATTERN.exec(tag)
    if (!match) throw new Error(`Malformed quarantine tag: ${tag}`)
    flakes.add(match[1])
  }
  if (flakes.size > 1) {
    throw new Error(`Conflicting quarantine tags: ${[...flakes].join(', ')}`)
  }
  return [...flakes][0] ?? null
}

/** Convert one public Playwright TestCase into the version-1 plan row. */
export function plannedTest(test, rootDir) {
  const titlePath = [test.title]
  for (let suite = test.parent; suite; suite = suite.parent) {
    if (suite.type === 'describe' && suite.title) titlePath.unshift(suite.title)
  }
  const row = {
    project: test.parent.project()?.name ?? '',
    file: relative(rootDir, test.location.file).replaceAll('\\', '/'),
    line: test.location.line,
    titlePath,
    flake: quarantineFlake(test.tags),
  }
  return { ...row, selector: testSelector(row) }
}

/** Fully qualified native --test-list entry; no regular expressions involved. */
export function testSelector(test) {
  const tokens = [test.project, test.file, ...test.titlePath]
  if (
    tokens.some((token) => /[\r\n›]/u.test(token) || token.trim() !== token) ||
    !test.titlePath.length ||
    test.titlePath.some((title) => !title)
  ) {
    throw new Error(
      'Test identity cannot be represented by a Playwright test list',
    )
  }
  return `[${test.project}] › ${test.file}:${test.line} › ${test.titlePath.join(' › ')}`
}

/** Split the selected collection, retaining its native report order. */
export function partitionTests(tests) {
  return {
    main: tests.filter((test) => test.flake === null),
    quarantine: tests.filter((test) => test.flake !== null),
  }
}

/** Line numbers locate a declaration; they never identify an observation. */
export function quarantineIdentity(test) {
  return JSON.stringify([test.flake, test.project, test.file, test.titlePath])
}

/**
 * Complete one run's observations against its plan, publishing each identity once.
 * A host failure may demote a browser pass; it never hides a test's own failure.
 *
 * @param {Array<object>} tests Selected plan rows.
 * @param {Array<object>} observations Actual reporter observations.
 * @param {{ reason?: string, hostError?: string }} options Missing/host reasons.
 */
export function normalizeResults(tests, observations, options = {}) {
  const observed = new Map()
  for (const observation of observations) {
    if (!STATUSES.has(observation.status)) {
      throw new Error(`Unknown quarantine status: ${observation.status}`)
    }
    const key = quarantineIdentity(observation)
    if (observed.has(key)) throw new Error('Duplicate quarantine observation')
    observed.set(key, observation)
  }
  const emitted = new Set()
  return tests.flatMap((test) => {
    if (test.flake === null) return []
    const key = quarantineIdentity(test)
    if (emitted.has(key)) return []
    emitted.add(key)
    const observation = observed.get(key)
    let status = observation?.status ?? 'notRun'
    let reason =
      observation?.reason ??
      options.reason ??
      options.hostError ??
      'No test result was reported'
    if (status === 'passed' && options.hostError) {
      status = 'failed'
      reason = options.hostError
    }
    return [
      {
        flake: test.flake,
        status,
        file: test.file,
        line: test.line,
        project: test.project,
        titlePath: test.titlePath,
        reason,
      },
    ]
  })
}

/** The public one-line protocol consumed by the repository runner and host. */
export function quarantineLine(observation) {
  return QUARANTINE_LINE_PREFIX + JSON.stringify(observation)
}

/** Refuse a missing or incompatible sidecar instead of broadening selection. */
export function readPlan(value) {
  if (value?.version !== 1 || !Array.isArray(value.tests)) {
    throw new Error('Invalid quarantine plan version or tests')
  }
  for (const test of value.tests) {
    if (
      typeof test.project !== 'string' ||
      typeof test.file !== 'string' ||
      !test.file ||
      !Number.isInteger(test.line) ||
      test.line < 1 ||
      !Array.isArray(test.titlePath) ||
      test.titlePath.some((title) => typeof title !== 'string') ||
      (test.flake !== null && !/^F-[0-9]{3,}$/.test(test.flake)) ||
      test.selector !== testSelector(test)
    )
      throw new Error('Invalid quarantine plan test')
  }
  return value.tests
}
