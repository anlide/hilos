import { test, expect } from '@playwright/test'
import { setAdmin } from '../helpers/adminGrant'
import { signUp } from '../helpers/session'

// Backup admin e2e (/hilos/backup): the one flow a human would try — press the
// button, watch the run, read the row, delete it. It is deliberately end-to-end
// rather than unit-shaped, because every defect this page has had lived on a seam
// no unit test crosses: the monopoly agent writes the index on its own worker, the
// page is served by another, the row travels as a table fragment, and the frontend
// normalizes it. A green unit suite proved none of that.
//
// The run is real: a mysqldump child writes an archive under the project data dir,
// so the spec cleans up after itself by deleting the backup it made. Scope
// schema-only keeps the dump small.

/** Sign up, become admin, and open the backup page with its live table. */
async function openBackups(page: import('@playwright/test').Page): Promise<void> {
  const { userId } = await signUp(page)
  await setAdmin(userId, true)

  await page.goto('/hilos/backup')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('creates a backup, shows it as a completed row, and deletes it', async ({
  page,
}) => {
  await openBackups(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Backups')

  const rows = page.locator('[data-id^="hilos-table-row-"]')
  // Row keys, not a row count: the first page may already be full (page size 10),
  // and then a new backup replaces one rather than adding to the tally.
  const keysOf = async (): Promise<string[]> =>
    rows.evaluateAll((cells) =>
      cells.map((cell) => cell.getAttribute('data-id') ?? ''),
    )
  const keysBefore = new Set(await keysOf())

  // Create: the smallest scope, so the dump is quick.
  await page
    .getByTestId('hilos-backup-create-scope')
    .selectOption('schema-only')
  await page.getByTestId('hilos-backup-create').click()

  // Acceptance is acked at once and the request must not fail: a misconfigured
  // storage root or CLI entry is refused synchronously and would land here.
  await expect(page.getByTestId('hilos-backup-create-error')).toHaveCount(0)

  // The run lands as a new row, live — no Apply gate for the tab that asked.
  await expect
    .poll(async () => (await keysOf()).some((key) => !keysBefore.has(key)), {
      timeout: 30_000,
    })
    .toBe(true)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // The in-progress row is transient: it must not survive the run it reports.
  await expect(page.locator('.progress-bar-animated')).toHaveCount(0, {
    timeout: 30_000,
  })

  // The committed row carries its own fields — not an empty shell. Each of these
  // read as a dash while the row payload was being swallowed as an entity.
  const row = rows.first()
  await expect(row).toContainText('schema-only')
  await expect(row).toContainText('test')
  await expect(row.getByText('success')).toBeVisible()
  // A finished run always reports a duration, even a sub-second one.
  await expect(row).toContainText(/\d+(s|m)/)

  // Delete it again: the row this test created leaves the table without a reload,
  // so the suite is idempotent on a shared storage directory.
  const createdKey = await row.getAttribute('data-id')
  await row.locator('[data-id^="hilos-backup-delete-"]').click()
  await page.getByTestId('hilos-backup-delete-confirm').click()
  await expect(page.getByTestId('hilos-backup-delete-error')).toHaveCount(0)
  // The row does not vanish: a removal leaves a placeholder in its slot, so the
  // window never collapses under the reader (table-subscription.md). What must be
  // gone is the backup itself — the row stops offering its actions.
  const deleted = page.locator(`[data-id="${createdKey}"]`)
  await expect(deleted).toContainText('Removed', { timeout: 15_000 })
  await expect(deleted.locator('[data-id^="hilos-backup-delete-"]')).toHaveCount(
    0,
  )
})

// NOT covered on purpose: there is no gating case here, because the page has no
// gate to assert. AbstractHilosBackupPage declares neither an ACCESS guard on its
// subscription nor AUTH_ACTIONS on create / delete / set-keep, so today any
// connection — including an anonymous one — can list and delete backups. Writing a
// test that asserts the current behavior would freeze the hole in place; the gate
// is a fix, and the test lands with it.
