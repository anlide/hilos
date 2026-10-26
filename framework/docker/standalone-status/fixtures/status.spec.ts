import { expect, test } from '@playwright/test'
import { appendFileSync } from 'node:fs'

// spec-owner: stand — browserless native last-run tooling fixture.
for (const name of ['status', 'quarantine']) {
  test(name, { tag: name === 'quarantine' ? '@quarantine:F-023' : [] }, () => {
    appendFileSync(
      process.env.HILOS_STATUS_FIXTURE_COUNTER!,
      JSON.stringify({ name, id: test.info().testId }) + '\n',
    )
    expect(process.env.HILOS_STATUS_FIXTURE_FAIL?.split(',')).not.toContain(
      name,
    )
  })
}
