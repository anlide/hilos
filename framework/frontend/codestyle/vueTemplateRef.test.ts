// VUE-TEMPLATE-REF under test against the seeded fixtures, which are the only
// thing proving the checker still fires. The real SDK and demo sources are
// judged by guard.test.ts, which runs every rule of this project against one
// baseline — a rule holding a repository assertion of its own would be a second
// guard, and the baseline is one file.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './vueTemplateRef.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/** The document every report line ends with, kept here so a test reads short. */
const DOC = '(see docs/agents/code-style/vue-template-refs.md)'

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

/**
 * @param name Name the ref-bearing value is held under
 * @param field Field read off it
 * @returns The reason every line about that read carries
 */
function reason(name: string, field: string): string {
  return (
    `'${name}.${field}' reads a ref held as a field, and a template expression` +
    ' unwraps no such ref — so the value is an object and the test on it is' +
    ` always true; read '${name}.${field}.value', or unwrap it with a computed` +
    ' in <script setup>'
  )
}

it('reports exactly the bare reads the bad fixture seeds', () => {
  expect(reportFixture('badVueTemplateRef.vue')).toEqual([
    `VUE-TEMPLATE-REF ${FIXTURES}/badVueTemplateRef.vue:6 — ${reason('action', 'error')} ${DOC}`,
    `VUE-TEMPLATE-REF ${FIXTURES}/badVueTemplateRef.vue:7 — ${reason('action', 'error')} ${DOC}`,
    `VUE-TEMPLATE-REF ${FIXTURES}/badVueTemplateRef.vue:8 — ${reason('action', 'failure')} ${DOC}`,
    `VUE-TEMPLATE-REF ${FIXTURES}/badVueTemplateRef.vue:9 — ${reason('own', 'busy')} ${DOC}`,
  ])
})

it('stays silent on the look-alikes the good fixture seeds', () => {
  expect(reportFixture('goodVueTemplateRef.vue')).toEqual([])
})

it('reads only single-file components, where unwrapping is a question', () => {
  // The same text in a .ts file is ordinary code: a field read there hands over
  // the ref itself, and whoever wrote it said so.
  expect(
    checkSource(
      'framework/frontend/vue/src/useSomething.ts',
      '<script setup lang="ts">\nconst own = useTrackedAction()\n</script>\n' +
        '<template>\n<p v-if="own.error" />\n</template>\n',
    ),
  ).toEqual([])
})

it('says nothing about a value it was never told bears refs', () => {
  expect(
    checkSource(
      `${FIXTURES}/unnamed.vue`,
      '<script setup lang="ts">\nconst own = useSomethingElse()\n</script>\n' +
        '<template>\n<p v-if="own.error" />\n</template>\n',
    ),
  ).toEqual([])
})
