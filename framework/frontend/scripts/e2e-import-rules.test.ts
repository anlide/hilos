import { ESLint } from 'eslint'
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  rmSync,
  symlinkSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, describe, expect, it } from 'vitest'

const demos = [
  'binance-btc-tracker',
  'chat',
  'ecommerce-shop',
  'online-testing',
  'pmd',
  'polls',
  'preferans',
  'tasks',
]
const ruleId = '@typescript-eslint/no-restricted-imports'

describe.each(demos)('%s e2e import guard', (demo) => {
  // fe-checks can run before any demo installs. Load each real config in the
  // same relative layout with only this workspace's installed lint packages.
  const directory = mkdtempSync(join(tmpdir(), 'hilos-e2e-imports-'))
  const cwd = join(directory, 'demo', demo, 'tests', 'e2e')
  const shared = join(directory, 'framework', 'frontend', 'scripts')
  const source = fileURLToPath(
    new URL(`../../../demo/${demo}/tests/e2e/`, import.meta.url),
  )
  mkdirSync(cwd, { recursive: true })
  mkdirSync(shared, { recursive: true })
  copyFileSync(
    join(source, 'eslint.config.mjs'),
    join(cwd, 'eslint.config.mjs'),
  )
  symlinkSync(
    fileURLToPath(new URL('../node_modules/', import.meta.url)),
    join(directory, 'node_modules'),
    'dir',
  )
  symlinkSync(
    fileURLToPath(new URL('./e2e-import-rules.mjs', import.meta.url)),
    join(shared, 'e2e-import-rules.mjs'),
  )
  afterAll(() => rmSync(directory, { recursive: true, force: true }))

  const eslint = new ESLint({
    cwd,
    overrideConfigFile: join(cwd, 'eslint.config.mjs'),
    // All demo configs share one parser in this process, so its inferred root
    // would be ambiguous. Production lint starts in one demo at a time.
    overrideConfig: {
      languageOptions: { parserOptions: { tsconfigRootDir: cwd } },
    },
  })

  async function lint(source: string, filePath = 'tests/import-probe.spec.ts') {
    const [result] = await eslint.lintText(source, { filePath })
    expect(result.fatalErrorCount, JSON.stringify(result.messages)).toBe(0)
    return result.messages
  }

  it.each([
    "import type { Page, TestType, test } from '@playwright/test'; export type { Page, TestType, test }",
    "import type * as playwright from '@playwright/test'; export type { playwright }",
    "import { expect, type test } from '@playwright/test'; export { expect }; export type { test }",
    "import { expect, defineConfig, devices } from '@playwright/test'; export { expect, defineConfig, devices }",
    "export { expect, defineConfig, devices } from '@playwright/test'",
    "export type { test, TestType } from '@playwright/test'",
    "export type * from '@playwright/test'",
    "import { test, expect } from '../test.js'; export { test, expect }",
    "import { test as base } from '../test.js'; export const test = base.extend<{ own: string }>({ own: 'fixture' })",
  ])(
    'allows types, other exports and local composition: %s',
    async (source) => {
      expect(await lint(source)).toEqual([])
    },
  )

  it.each([
    "import { test } from '@playwright/test'; export { test }",
    "import { test as base } from '@playwright/test'; export { base }",
    "import test from '@playwright/test'; export { test }",
    "import * as playwright from '@playwright/test'; export { playwright }",
    "export { test } from '@playwright/test'",
    "export { test as base } from '@playwright/test'",
    "export { default as base } from '@playwright/test'",
    "export * from '@playwright/test'",
    "export * as playwright from '@playwright/test'",
  ])('refuses a raw test binding or re-export: %s', async (source) => {
    const messages = await lint(source)
    expect(messages).toHaveLength(1)
    expect(messages[0]).toMatchObject({ ruleId, severity: 2 })
    expect(messages[0].message).toContain('local tests/e2e/test.js entry')
  })

  it('exempts only the package entry, including for cluster and standalone consumers', async () => {
    const source =
      "import { test as base } from '@playwright/test'; export { base }"
    expect(await lint(source, 'test.ts')).toEqual([])
    for (const filePath of [
      'helpers/test.ts',
      'cluster/live/import-probe.spec.ts',
      'standalone/import-probe.spec.ts',
    ]) {
      expect(await lint(source, filePath)).toEqual([
        expect.objectContaining({ ruleId, severity: 2 }),
      ])
    }
  })
})
