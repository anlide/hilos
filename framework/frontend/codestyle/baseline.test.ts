// The baseline under test on its own, apart from any rule: the four verdicts it
// gives a record, and the two things the update button refuses to do.
//
// The guard test cannot cover these, being silent by design on a clean tree. The
// PHP half is tested the same way by framework/tests/Unit/CodeStyle.
import { expect, it } from 'vitest'

import { Baseline } from './baseline.js'

/** A rule and file the records below owe against. */
const KEY = 'STYLE-INLINE framework/frontend/vue/src/HilosActionError.vue'

/** A second one, so ordering and per-record verdicts are visible at all. */
const OTHER_KEY = 'STYLE-INLINE demo/chat/frontend/src/views/Main/Main.vue'

/**
 * Builds the violation lines of one key, whose text the baseline never reads —
 * it counts them and hands the surplus back.
 *
 * @param key Rule and file the lines belong to
 * @param count How many the scan found
 * @returns The lines, numbered so a surplus is recognizable in an assertion
 */
function found(key: string, count: number): string[] {
  return Array.from(
    { length: count },
    (_, index) => `${key}:${index + 1} — a site`,
  )
}

it('says nothing about a file whose count matches its record', () => {
  const baseline = Baseline.fromText(`${KEY} 2 # HIL-449\n`)

  expect(baseline.reconcile({ [KEY]: found(KEY, 2) })).toEqual([])
})

it('hands back only the sites above the count', () => {
  const baseline = Baseline.fromText(`${KEY} 2 # HIL-449\n`)

  expect(baseline.reconcile({ [KEY]: found(KEY, 4) })).toEqual([
    `${KEY}:3 — a site`,
    `${KEY}:4 — a site`,
  ])
})

it('asks for a count that has fewer sites left to be lowered', () => {
  const baseline = Baseline.fromText(`${KEY} 3 # HIL-449\n`)

  expect(baseline.reconcile({ [KEY]: found(KEY, 1) })).toEqual([
    `baseline record "${KEY}" allows 3, only 1 left — lower the count`,
  ])
})

it('asks for a record with nothing left to cover to be deleted', () => {
  const baseline = Baseline.fromText(`${KEY} 1 # HIL-449\n`)

  expect(baseline.reconcile({})).toEqual([
    `baseline record "${KEY}" is paid off — delete the line`,
  ])
})

it('reports a record it could not read, and one that owes no leaf', () => {
  const baseline = Baseline.fromText(
    `${KEY} two # HIL-449\n${OTHER_KEY} 1 # later\n`,
  )

  expect(baseline.reconcile({})).toEqual([
    `baseline line 1 is malformed: ${KEY} two # HIL-449`,
    `baseline record "${OTHER_KEY}" names no owing leaf: later`,
  ])
})

it('reads a file of comments and blank lines as a baseline of no debt', () => {
  const baseline = Baseline.fromText('# a header\n\n#     a shape\n')

  expect(baseline.reconcile({ [KEY]: found(KEY, 1) })).toEqual([
    `${KEY}:1 — a site`,
  ])
})

it('rewrites a record down to what the tree has left, keeping its leaf', () => {
  const baseline = Baseline.fromText(`${KEY} 3 # HIL-449\n`)

  const update = baseline.update({ [KEY]: found(KEY, 1) })

  expect(update.text()).toContain(`${KEY} 1 # HIL-449\n`)
  expect(update.message()).toBe(
    'Baseline regenerated from the current tree — review the diff before committing it.',
  )
})

it('drops a record the tree has nothing left for', () => {
  const baseline = Baseline.fromText(`${KEY} 3 # HIL-449\n`)

  expect(baseline.update({}).text()).not.toContain(KEY)
})

it('never raises a count, and names the sites the record does not cover', () => {
  const baseline = Baseline.fromText(`${KEY} 1 # HIL-449\n`)

  const update = baseline.update({ [KEY]: found(KEY, 3) })

  expect(update.text()).toContain(`${KEY} 1 # HIL-449\n`)
  expect(update.message()).toContain(
    `${KEY}: kept at 1, the tree has 3 — the update mode never raises a count`,
  )
  expect(update.message()).toContain(`  ${KEY}:2 — a site`)
  expect(update.message()).toContain(`  ${KEY}:3 — a site`)
})

it('never adds a record for a key it has never seen', () => {
  const baseline = Baseline.fromText('')

  const update = baseline.update({ [OTHER_KEY]: found(OTHER_KEY, 2) })

  expect(update.text()).not.toContain(OTHER_KEY)
  expect(update.message()).toContain(
    `${OTHER_KEY}: not written, the tree has 2 — the update mode never adds a record`,
  )
})

it('writes nothing at all while its own records do not parse', () => {
  const baseline = Baseline.fromText(`${KEY} two # HIL-449\n`)

  const update = baseline.update({ [KEY]: found(KEY, 1) })

  expect(update.text()).toBeNull()
  expect(update.message()).toContain('Baseline left untouched')
  expect(update.message()).toContain(`baseline line 1 is malformed`)
})
