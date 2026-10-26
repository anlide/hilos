import { expect, test } from '@playwright/test'
import { appendFileSync } from 'node:fs'

// spec-owner: stand — browserless native last-run tooling fixture.
test('ordinary', () => {
  appendFileSync(
    process.env.HILOS_STATUS_FIXTURE_COUNTER!,
    JSON.stringify({ name: 'ordinary', id: test.info().testId }) + '\n',
  )
  expect(process.env.HILOS_STATUS_FIXTURE_FAIL?.split(',')).not.toContain(
    'ordinary',
  )
})
