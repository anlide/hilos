import { expect, test } from '@playwright/test'
import { createHash } from 'node:crypto'
import {
  appendFileSync,
  existsSync,
  readFileSync,
  writeFileSync,
} from 'node:fs'
import { join } from 'node:path'

// spec-owner: stand — synthetic CLI fixtures without an application or browser.
const COUNTER_FILE = process.env.HILOS_FIXTURE_COUNTER!

for (const name of [
  'normal-pass',
  'normal-fail',
  'quarantine-pass',
  'quarantine-fail',
]) {
  test(
    name,
    { tag: name.startsWith('quarantine') ? '@quarantine:F-023' : [] },
    () => {
      const info = test.info()
      const report = join(process.cwd(), 'playwright-report/index.html')
      appendFileSync(
        COUNTER_FILE,
        JSON.stringify({
          name,
          project: info.project.name,
          phase: process.env.HILOS_E2E_PHASE,
          retry: info.retry,
          repeat: info.repeatEachIndex,
          reportHash: existsSync(report)
            ? createHash('sha256').update(readFileSync(report)).digest('hex')
            : null,
        }) + '\n',
      )
      writeFileSync(info.outputPath('proof.txt'), name)
      expect(name.endsWith('fail')).toBe(false)
    },
  )
}

test.describe('parameter [.*] (a+b)? Юникод', () => {
  for (const role of ['admin', 'viewer']) {
    test(
      `parameter ${role}`,
      { tag: role === 'viewer' ? '@quarantine:F-024' : [] },
      () => {
        appendFileSync(
          COUNTER_FILE,
          JSON.stringify({
            name: role,
            project: test.info().project.name,
            phase: process.env.HILOS_E2E_PHASE,
            line: test.info().line,
          }) + '\n',
        )
      },
    )
  }
})
