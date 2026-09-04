// The TypeScript half of WIRE-KEY-CASE under test against the seeded fixtures,
// which are the only thing proving the checker still fires. The real SDK and demo
// sources are judged by guard.test.ts, which runs every rule of this project
// against one baseline. The PHP half is split the same way, between
// framework/tests/Unit/CodeStyle/{RuleFixtureTest,CodeStyleGuardTest}.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './wireKeyCase.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/**
 * Runs the checker over a fixture exactly as the repository scan would.
 *
 * @param name File name inside the fixture directory
 * @returns Reported lines, in source order
 */
function reportFixture(name: string): string[] {
  const relativePath = `${FIXTURES}/${name}`

  return checkSource(
    relativePath,
    readFileSync(join(REPOSITORY_ROOT, relativePath), 'utf8'),
  )
}

it('reports exactly the keys the bad fixture seeds', () => {
  expect(reportFixture('badWireKeys.ts')).toEqual([
    "WIRE-KEY-CASE framework/frontend/codestyle/fixtures/badWireKeys.ts:9 — field key 'override_value'" +
      ' is not camelCase; one spelling has to serve PHP, the wire and TS' +
      ' (see docs/agents/code-style/cross-layer-field-names.md)',
    "WIRE-KEY-CASE framework/frontend/codestyle/fixtures/badWireKeys.ts:12 — field key 'ValueSource'" +
      ' is not camelCase; one spelling has to serve PHP, the wire and TS' +
      ' (see docs/agents/code-style/cross-layer-field-names.md)',
    "WIRE-KEY-CASE framework/frontend/codestyle/fixtures/badWireKeys.ts:17 — field key 'default_value'" +
      ' is not camelCase; one spelling has to serve PHP, the wire and TS' +
      ' (see docs/agents/code-style/cross-layer-field-names.md)',
    'WIRE-KEY-CASE framework/frontend/codestyle/fixtures/badWireKeys.ts:18 — field key' +
      " 'default_reference_key' is not camelCase; one spelling has to serve PHP, the wire and TS" +
      ' (see docs/agents/code-style/cross-layer-field-names.md)',
    "WIRE-KEY-CASE framework/frontend/codestyle/fixtures/badWireKeys.ts:23 — field key 'created_at'" +
      ' is not camelCase; one spelling has to serve PHP, the wire and TS' +
      ' (see docs/agents/code-style/cross-layer-field-names.md)',
  ])
})

it('stays silent on the look-alikes the good fixture seeds', () => {
  expect(reportFixture('goodWireKeys.ts')).toEqual([])
})
