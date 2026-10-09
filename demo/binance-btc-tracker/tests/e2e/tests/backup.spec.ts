import { test, expect, type Page } from '@playwright/test'
import {
  dismissToasts,
  shownByTestId,
  watchFirstRowTop,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf, setAdmin } from '../helpers/adminGrant'
import { clickSubmit, signUp } from '../helpers/session'
import { setAdminViewMode } from '../helpers/adminViewMode'
import { gotoPage, PAGE_READY, PAGE_REFUSED } from '../helpers/page'

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

/** Become admin, and open the backup page with its live table. */
async function openBackups(
  page: import('@playwright/test').Page,
): Promise<void> {
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/backup')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

/**
 * Name the newest STORED archive, read off a fresh snapshot of the page.
 *
 * The row of a run in progress sits above them all and is not an archive at all,
 * so the newest one is the first row that offers a delete — which is also the
 * only row a cleanup can aim at. The snapshot is fresh because the page does not
 * show the row it created itself (HIL-432, see the tests below).
 */
async function newestArchiveKey(
  page: import('@playwright/test').Page,
): Promise<string> {
  await gotoPage(page, '/hilos/backup')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const key = await page
    .locator('[data-id^="hilos-table-row-"]')
    .filter({ has: page.locator('[data-id^="hilos-backup-delete-"]') })
    .first()
    .getAttribute('data-id')
  expect(key).toBeTruthy()

  return key ?? ''
}

/**
 * Ask for one schema-only backup the way a person does: press the table's main
 * action, pick the scope in the dialog it opens, confirm. The scope is picked every
 * time, because the dialog opens on the first scope rather than on the last one
 * picked (HIL-1021). Resolves once the dialog has closed — that is the accepted
 * create; a refused one keeps the dialog open, and the caller's toast assertion
 * would then time out on the refusal instead.
 *
 * @param page Page of an admin standing on the backup page.
 */
async function askCreate(page: Page): Promise<void> {
  await page.getByTestId('hilos-table-main-action').click()
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeVisible()
  await dialog
    .getByTestId('hilos-backup-create-scope')
    .selectOption('schema-only')
  const confirm = dialog.getByTestId('hilos-backup-create-confirm')
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.click()
  await expect(dialog).toBeHidden()
}

/**
 * Run one schema-only backup from the page's own dialog, wait for it to land, and
 * name the row it made. The card a finished run raises is what says the archive
 * is on disk; scope schema-only keeps the dump small.
 */
async function createBackup(
  page: import('@playwright/test').Page,
): Promise<string> {
  await askCreate(page)
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText('is ready.'),
  ).toBeVisible({ timeout: 60_000 })
  await dismissToasts(page)

  return newestArchiveKey(page)
}

/** Delete one archive by its row key and wait for its actions to disappear. */
async function deleteBackup(
  page: import('@playwright/test').Page,
  rowKey: string,
): Promise<void> {
  await dismissToasts(page)
  await page
    .locator(`[data-id="${rowKey}"] [data-id^="hilos-backup-delete-"]`)
    .click()
  await page.getByTestId('hilos-backup-delete-confirm').click()
  await expect(
    page.locator(`[data-id="${rowKey}"] [data-id^="hilos-backup-delete-"]`),
  ).toHaveCount(0, { timeout: 20_000 })
}

/** The card a finished run raises, naming the archive it made. */
const READY_CARD = /Backup "([^"]+)" is ready\./

/**
 * Run one schema-only backup from the page's own dialog and name the archive it made.
 *
 * The name is read off the card the finished run raises rather than off the top of
 * the table: the card is addressed to this browser alone, while the newest row may
 * be an archive a parallel test has just made.
 *
 * @param page Page of an admin standing on the backup page.
 * @returns The backup id, which is also the key of its row.
 */
async function createNamedBackup(page: Page): Promise<string> {
  await dismissToasts(page)
  await askCreate(page)
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
  const card = page.getByTestId('hilos-toasts').getByText(READY_CARD)
  await expect(card).toBeVisible({ timeout: 60_000 })
  const backupId = READY_CARD.exec((await card.textContent()) ?? '')?.[1] ?? ''
  expect(backupId).not.toBe('')
  await dismissToasts(page)

  return backupId
}

/** The property on `window` the bar watcher raises once it has seen the bar drawn. */
const BULK_BAR_SEEN = '__hilosE2eBulkBarSeen'

/**
 * Start recording whether the bar of a bulk run gets drawn.
 *
 * The bar stands exactly as long as the run, and a run over two archives can end
 * inside one retry of a web-first assertion: waiting for the bar to be visible would
 * race the run, passing or failing by how fast the storage agent answered. A watcher
 * armed before the run does not care how soon the bar goes again — it records the bar
 * being put into the document, and the spec reads the record once the report says the
 * run is over. Added nodes are inspected rather than the document queried, so a bar
 * taken down again before the watcher's turn came still counts.
 *
 * @param page Page the run is started from.
 */
async function watchForBulkBar(page: Page): Promise<void> {
  await page.evaluate(
    ({ mark, selector }) => {
      const flags = window as unknown as Record<string, boolean>
      flags[mark] = document.querySelector(selector) !== null
      new MutationObserver((records, observer) => {
        const drawn = records.some(
          (record) =>
            (record.type === 'attributes' &&
              record.target instanceof Element &&
              record.target.matches(selector)) ||
            Array.from(record.addedNodes).some(
              (node) =>
                node instanceof Element &&
                (node.matches(selector) ||
                  node.querySelector(selector) !== null),
            ),
        )
        if (drawn) {
          flags[mark] = true
          observer.disconnect()
        }
      }).observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-id'],
      })
    },
    { mark: BULK_BAR_SEEN, selector: '[data-id="hilos-table-progress-bulk"]' },
  )
}

/**
 * Read what {@link watchForBulkBar} recorded.
 *
 * @param page Page the watcher was armed on.
 * @returns Whether the bar of a bulk run was drawn since the watcher was armed.
 */
async function bulkBarWasDrawn(page: Page): Promise<boolean> {
  return page.evaluate(
    (mark) => (window as unknown as Record<string, boolean>)[mark] === true,
    BULK_BAR_SEEN,
  )
}

/** The page-state transitions recorded since the watcher was armed. */
const PAGE_LIFE_STATES = '__hilosE2ePageStates'

/** The original table node, retained even if the page unmounts it. */
const PAGE_LIFE_NODE = '__hilosE2ePageNode'

/**
 * Remember the page's table and every subsequent change of its outlet state.
 *
 * @param page The ready backup page to observe before starting the run.
 */
async function watchPageLife(page: Page): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    PAGE_READY,
  )
  await page.evaluate(
    ({ nodeMark, statesMark }) => {
      const node = document.querySelector('[data-id="hilos-viewport-table"]')
      const marker = document.querySelector('[data-id="hilos-page-state"]')
      if (node === null || marker === null) {
        throw new Error('The backup page must be mounted before watching it')
      }
      const record = window as unknown as Record<string, unknown>
      const states: string[] = []
      record[nodeMark] = node
      record[statesMark] = states
      new MutationObserver((records) => {
        records.forEach((mutation, index) => {
          // The next old value preserves an intermediate state even when several
          // transitions arrive in the same observer callback.
          states.push(
            records[index + 1]?.oldValue ??
              (mutation.target as Element).getAttribute('data-state') ??
              '',
          )
        })
      }).observe(marker, {
        attributes: true,
        attributeFilter: ['data-state'],
        attributeOldValue: true,
      })
    },
    { nodeMark: PAGE_LIFE_NODE, statesMark: PAGE_LIFE_STATES },
  )
}

/**
 * Read the original node's lifetime and the transitions since it was marked.
 *
 * @param page The page on which the watcher was armed.
 * @returns Whether the original node is still connected, and its state journal.
 */
async function pageLifeRecord(
  page: Page,
): Promise<{ connected: boolean; states: string[] }> {
  return page.evaluate(
    ({ nodeMark, statesMark }) => {
      const record = window as unknown as Record<string, unknown>

      return {
        connected:
          (record[nodeMark] as Element | undefined)?.isConnected ?? false,
        states: (record[statesMark] as string[] | undefined) ?? [],
      }
    },
    { nodeMark: PAGE_LIFE_NODE, statesMark: PAGE_LIFE_STATES },
  )
}

// HIL-441 acceptance (carried over from HIL-428): the backup page is part of the
// framework admin surface, closed by default. A guest never sees the page or its
// action controls — the 401 mounts the in-place sign-in surface instead, so the
// page's actions are unreachable from the UI.
test('closes the backup page to a guest', async ({ page }) => {
  // Anonymous: the ADMIN level denies the subscription with a 401 before any
  // page payload is sent; the auth gate renders sign-in in place of the page.
  await gotoPage(page, '/hilos/backup')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-main-action')).toHaveCount(0)
  // The destructive control is behind the same door as the rest of the page
  // (HIL-276): no surface, no restore.
  await expect(page.locator('[data-id^="hilos-backup-restore-"]')).toHaveCount(
    0,
  )
})

test('refuses the backup page to a signed-in non-admin', async ({ page }) => {
  // Signed in but not admin: 403, the error page replaces the backup surface.
  await signUp(page)
  await gotoPage(page, '/hilos/backup')
  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '403')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-main-action')).toHaveCount(0)
  await expect(page.locator('[data-id^="hilos-backup-restore-"]')).toHaveCount(
    0,
  )
})

test('shuts the open backup page the moment the admin flag is revoked', async ({
  page,
  browser,
}) => {
  // HIL-621 acceptance. Everything above this test asks the question at
  // subscribe time; this one asks it of a page that is ALREADY open. The verdict
  // used to be reached once and then only re-checked as a gate on delivery, so a
  // revoke left the archive list readable until the person reloaded.
  // Revocation requires another administrator, even when this test runs alone.
  const otherAdmin = await browser.newContext()
  try {
    await grantAdminToSelf(await otherAdmin.newPage())
  } finally {
    await otherAdmin.close()
  }
  const userId = await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/backup')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  await setAdmin(userId, false)

  // No gotoPage and no reload between the revoke and these assertions - that is
  // the whole point. The page shows the same 403 a fresh subscribe would have
  // answered with, and the archive list is gone rather than hidden behind it.
  // This is the losing half, which the client draws ahead of the server. The
  // gaining half needs the server's answer to pass at all, and stands directly
  // below - here it also crosses two workers (HIL-644).
  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '403')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-main-action')).toHaveCount(0)
  await expect(page.locator('[data-id^="hilos-backup-restore-"]')).toHaveCount(
    0,
  )
})

test('opens the refused backup page the moment admin is granted', async ({
  page,
  browser,
}) => {
  // HIL-644 acceptance, and the case the revoke above cannot make: the gaining
  // half only ever arrives from the server, so nothing the client draws by itself
  // can stand in for it. It also crosses two workers - /hilos/backup is served by
  // the hilos index agent while the grant is written by the sessions library on
  // its own monopolistic worker (HIL-729) - which is exactly the seam that made
  // HIL-621's sweep miss this page and leave a spinner where the honest 403 used
  // to be. The visitor is made an admin and revoked first, so the refused page is
  // a person's who holds an account: the grant is the same re-decision either way.
  // This test prepares its own second administrator before revoking the first.
  const otherAdmin = await browser.newContext()
  try {
    await grantAdminToSelf(await otherAdmin.newPage())
  } finally {
    await otherAdmin.close()
  }
  const userId = await grantAdminToSelf(page)
  await setAdmin(userId, false)
  await gotoPage(page, '/hilos/backup', PAGE_REFUSED)
  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '403')

  await setAdmin(userId, true)

  // No gotoPage and no reload between the grant and these assertions, same as the
  // revoke above: the page is re-answered where it stands. The table appearing is
  // the whole verdict arriving - a page payload the client had no way to invent.
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(error).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-main-action')).toBeVisible()
})

test('points to the verifier circle in Maintenance', async ({ page }) => {
  await openBackups(page)
  await expect(page.getByTestId('hilos-backup-circle-panel')).toHaveCount(0)
  await expect(page.getByTestId('hilos-backup-circle-pointer')).toBeVisible()

  await page.getByTestId('hilos-backup-circle-pointer-open').click()
  await expect(page).toHaveURL(/\/hilos\/maintenance$/)
  await expect(page.getByTestId('hilos-maintenance-circle-panel')).toBeVisible()
})

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
  await askCreate(page)

  // Acceptance is acked at once and the request must not fail: a misconfigured
  // storage root or CLI entry is refused synchronously and would toast here.
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)

  // The run shows as the bar above the table, and it is up before the child has done
  // anything: the runtime row is written when the run is admitted, so the bar is not a
  // race against a fast schema-only dump (HIL-820).
  const bar = page.getByTestId('hilos-table-progress')
  await expect(bar).toBeVisible({ timeout: 30_000 })
  // The caption beside it is the page's, filled from the bar's own figures: the phase
  // as the code names it, or "In progress" before the child has announced one.
  await expect(bar).toContainText(
    /In progress|dumping|archiving|digesting|publishing/,
  )

  // And the set holds no row for it. Every key here is a stored archive's own id — a
  // run has no archive and therefore no key to invent one from, which is the whole
  // point of the bar. No Apply gate is raised either: a bar is not a pending change.
  for (const key of await keysOf()) {
    expect(key).toMatch(/^hilos-table-row-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/)
  }
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // The archive arrives before the bar comes down (the agent rescans the store and only
  // then clears the runtime), so there is no blink with neither of them on screen.
  await expect
    .poll(async () => (await keysOf()).some((key) => !keysBefore.has(key)), {
      timeout: 30_000,
    })
    .toBe(true)

  // Work that ended is not a deleted record: the bar goes and leaves nothing in its
  // place — no placeholder, no empty block holding its margin.
  await expect(bar).toHaveCount(0, { timeout: 30_000 })

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
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
  // The row leaves either a placeholder or, when it was the last archive on this
  // shared stand, an empty table. What must be gone is the backup itself — it
  // stops offering its actions in both cases.
  const deleted = page.locator(`[data-id="${createdKey}"]`)
  await expect(
    deleted.locator('[data-id^="hilos-backup-delete-"]'),
  ).toHaveCount(0, { timeout: 15_000 })
})

// HIL-1089: a live backup creation and deletion move the option numbers in an open
// scope dropdown without reloading the page.
test('moves the scope counts when a backup lands and when it goes, without a reload', async ({
  page,
}) => {
  await openBackups(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Backups')

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const scopeFilter = page.locator('[data-id="hilos-table-filter-scope"]')
  const scopeToggle = scopeFilter.locator('[data-id="hilos-dropdown-toggle"]')

  const openScope = async (): Promise<void> => {
    if ((await scopeToggle.getAttribute('aria-expanded')) !== 'true') {
      await scopeToggle.click()
    }
  }

  const anyFacet = page.locator('[data-id="hilos-table-facet-scope-any"]')
  const schemaOnlyFacet = page.locator(
    '[data-id="hilos-table-facet-scope-schema-only"]',
  )

  const readScopeCounts = async (): Promise<{
    any: number
    schemaOnly: number
  }> => {
    await openScope()
    await expect(anyFacet).toBeVisible()
    await expect(schemaOnlyFacet).toBeVisible()
    const anyText = (await anyFacet.innerText()).trim()
    const schemaOnlyText = (await schemaOnlyFacet.innerText()).trim()

    return {
      any: parseInt(anyText, 10),
      schemaOnly: parseInt(schemaOnlyText, 10),
    }
  }

  const initial = await readScopeCounts()

  // Create a schema-only backup from the page dialog
  await askCreate(page)
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText('is ready.'),
  ).toBeVisible({ timeout: 60_000 })
  await dismissToasts(page)

  // Re-open scope dropdown and assert counts grew without a reload
  await expect
    .poll(
      async () => {
        const current = await readScopeCounts()

        return (
          current.any > initial.any && current.schemaOnly > initial.schemaOnly
        )
      },
      { timeout: 30_000 },
    )
    .toBe(true)

  const afterCreate = await readScopeCounts()

  // Identify the created archive row to delete it
  const createdRow = page.locator('[data-id^="hilos-table-row-"]').first()
  const createdKey = await createdRow.getAttribute('data-id')
  expect(createdKey).toBeTruthy()

  await deleteBackup(page, createdKey!)

  // Assert counts returned down
  await expect
    .poll(
      async () => {
        const current = await readScopeCounts()

        return (
          current.any < afterCreate.any &&
          current.schemaOnly < afterCreate.schemaOnly
        )
      },
      { timeout: 30_000 },
    )
    .toBe(true)

  expect(fullLoads).toBe(0)
})

// HIL-1021 acceptance, the half the leaf was raised for. A create used to be refused
// only by a toast in the corner, gone in seconds; asked in a dialog, the refusal
// stands as the first line of the dialog's body for as long as the dialog is open,
// with the scope still picked. The stand has backups configured, so the one refusal
// a browser can provoke here is an unknown scope — the select offers only lawful
// ones, and the spec adds the unlawful option to the DOM itself before picking it.
test('keeps the create dialog open with the refusal in it when the backend says no', async ({
  page,
}) => {
  await openBackups(page)

  await page.getByTestId('hilos-table-main-action').click()
  const dialog = page.getByTestId('modal')
  await expect(dialog).toBeVisible()
  // The room for a refusal is taken before there is one (form-error): the slot
  // stands, the plate does not.
  await expect(dialog.getByTestId('hilos-action-error-slot')).toBeAttached()
  await expect(dialog.getByTestId('hilos-action-error')).toHaveCount(0)

  const scope = dialog.getByTestId('hilos-backup-create-scope')
  await scope.evaluate((select: HTMLSelectElement) => {
    const option = document.createElement('option')
    option.value = 'no-such-scope'
    option.textContent = 'No such scope'
    select.append(option)
  })
  await scope.selectOption('no-such-scope')
  const confirm = dialog.getByTestId('hilos-backup-create-confirm')
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.click()

  // The dialog stays, the refusal is its first line, and the scope is still picked;
  // the toast in the corner is the second addressee (HIL-779), not a replacement.
  const refusal = dialog.getByTestId('hilos-action-error')
  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('Invalid backup scope: no-such-scope')
  await expect(dialog).toBeVisible()
  await expect(scope).toHaveValue('no-such-scope')
  await expect(page.getByTestId('hilos-toast-error')).toBeVisible()
  await expect(confirm).toBeEnabled()
})

// HIL-768 acceptance, and the reason the leaf landed a sender at all: a finished
// create is the first toast addressed to a SESSION rather than to the socket that
// asked. Two tabs of one browser is where the promise is either kept or broken -
// the card reaches both, and closing it in either takes it out of the other.
//
// What this does NOT assert is the countdown or the reading hold: both are timing,
// and a browser spec that sat still for twenty seconds to watch a card would be
// slow and flaky on a loaded box (toasts.md, "Not e2e, on purpose").
test('agrees between two tabs about the card a finished backup raised', async ({
  context,
}) => {
  // Two tabs of the same context share the session cookie, so the admin grant in
  // the first signs the second in as the same browser.
  const tabA = await context.newPage()
  await openBackups(tabA)

  const tabB = await context.newPage()
  await gotoPage(tabB, '/')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')

  // The run is started from tab A, which has to be in front for the click. That
  // also freezes tab B's countdown while it waits, so neither tab burns the card
  // down before the close below.
  await tabA.bringToFront()
  await askCreate(tabA)
  await expect(tabA.getByTestId('hilos-toast-error')).toHaveCount(0)

  // The card arrives in the tab that did NOT ask, on a page that knows nothing
  // about backups: the addressee is the browser, not the screen.
  const cardInB = tabB.getByTestId('hilos-toasts').getByText('is ready.')
  await expect(cardInB).toBeVisible({ timeout: 60_000 })
  await expect(
    tabA.getByTestId('hilos-toasts').getByText('is ready.'),
  ).toBeVisible()

  // Closing is one person's answer, and the person is one per session. Tab B goes
  // to the front to click, which freezes tab A's countdown - so what takes the
  // card out of tab A can only be the close.
  await tabB.bringToFront()
  await tabB.getByTestId('hilos-toast-close').click()
  await expect(
    tabA.getByTestId('hilos-toasts').getByText('is ready.'),
  ).toHaveCount(0, { timeout: 15_000 })

  // Delete the backup this test made, so the suite stays idempotent on a shared
  // storage directory. The page is opened AFRESH first: the row a tab created does
  // not reach that same tab live today (HIL-432, which is what parks the create
  // test above), and a cleanup must not stand on the defect it is not about.
  await tabA.bringToFront()
  await gotoPage(tabA, '/hilos/backup')
  await expect(tabA.getByTestId('hilos-viewport-table')).toBeVisible()
  const created = tabA.locator('[data-id^="hilos-table-row-"]').first()
  const createdKey = await created.getAttribute('data-id')
  await created.locator('[data-id^="hilos-backup-delete-"]').click()
  await tabA.getByTestId('hilos-backup-delete-confirm').click()
  // A removal leaves either a placeholder or an empty table when this was the
  // last archive; in both cases the deleted backup stops offering its actions.
  await expect(
    tabA.locator(`[data-id="${createdKey}"] [data-id^="hilos-backup-delete-"]`),
  ).toHaveCount(0, { timeout: 20_000 })
})

// HIL-1320: a backup in another tab must leave this page's component alive.
// A ready-looking replacement would lose selection and drafts just as surely as
// an error page, so record the node's lifetime and every outlet-state transition.
test('keeps the page of another tab standing through a backup that ends', async ({
  context,
}) => {
  // Two real schema-only dumps need the same headroom as the bulk-backup tests.
  test.slow()

  const tabA = await context.newPage()
  await openBackups(tabA)

  // A nonempty window makes the next archive land above B's window rather than
  // append into an empty table (BrowserContext::viewportPlacement).
  const floorKey = await createBackup(tabA)
  const floorId = floorKey.replace('hilos-table-row-', '')

  const tabB = await context.newPage()
  await gotoPage(tabB, '/hilos/backup', PAGE_READY)
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId(floorKey)).toBeVisible()
  await watchPageLife(tabB)

  await tabA.bringToFront()
  await askCreate(tabA)
  await expect(tabA.getByTestId('hilos-toast-error')).toHaveCount(0)
  // First establish that the new archive exists. Otherwise B's restore button
  // could still be enabled because the start of the run has not reached it yet.
  await expect(
    tabA.getByTestId('hilos-toasts').getByText('is ready.'),
  ).toBeVisible({ timeout: 60_000 })

  // B's own last frame is the runtime being cleared: the floor offers restore
  // again. The progress strip can disappear earlier, behind an announcement.
  await expect(
    shownByTestId(tabB, `hilos-backup-restore-${floorId}`),
  ).toBeEnabled({ timeout: 60_000 })
  await expect(
    tabB.getByTestId(`hilos-backup-blocked-why-${floorId}`),
  ).toHaveCount(0)

  expect(await pageLifeRecord(tabB)).toEqual({ connected: true, states: [] })

  await deleteBackup(tabA, await newestArchiveKey(tabA))
  await deleteBackup(tabA, floorKey)
})

test('offers a restore on this stand and holds it behind the typed id', async ({
  page,
}) => {
  // The stand runs with APP_ENV=test, so the restore button is offered here — that
  // is the environment half of HIL-276 asserted by being on the non-prod side of it.
  // What is NOT asserted is a restore actually running: it would overwrite the
  // stand's database and freeze the node for every other spec. The confirmation is
  // driven right up to the enabled button and then canceled.
  await openBackups(page)

  // A row to aim at. The live arrival of a created row is parked (HIL-432 above), so
  // the row is picked up from a fresh snapshot instead of from a delta.
  await askCreate(page)
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)

  // The row key is what the poll waits on, and the restore button is then named in
  // full. A `hilos-backup-restore-` prefix would not name it: the outcome cell, the
  // CLI hint and the confirmation field share that prefix, and the outcome cell comes
  // first in the row — so the first prefix match stops being the button as soon as
  // any restore has run here.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  let backupId = ''
  await expect
    .poll(
      async () => {
        await gotoPage(page, '/hilos/backup')
        await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
        if ((await rows.count()) === 0) {
          return 0
        }
        backupId =
          (await rows.first().getAttribute('data-id'))?.replace(
            'hilos-table-row-',
            '',
          ) ?? ''

        return page
          .locator(`[data-id="hilos-backup-restore-${backupId}"]`)
          .count()
      },
      { timeout: 60_000 },
    )
    .toBeGreaterThan(0)

  expect(backupId).toBeTruthy()

  // The other side of the rule a dark restore button follows (HIL-661): a live one
  // has no "why" button beside it, and its title is its name, not a reason. The
  // button stands in a cell, drawn by the row and by its card alike, so it is the
  // copy on screen that is pressed; the "why" is absent from both.
  const restoreButton = shownByTestId(page, `hilos-backup-restore-${backupId}`)
  await expect(restoreButton).toBeEnabled()
  await expect(
    page.locator(`[data-id="hilos-backup-blocked-why-${backupId}"]`),
  ).toHaveCount(0)
  await expect(restoreButton).toHaveAttribute(
    'aria-label',
    'Restore this backup',
  )
  await expect(restoreButton).toHaveAttribute('title', 'Restore this backup')
  await restoreButton.click()

  // The barrier is the id, not a yes/no: the likely mistake is restoring the wrong
  // archive, and only typing its id proves the operator read which one is selected.
  const confirm = page.getByTestId('hilos-backup-restore-confirm')
  const input = page.getByTestId('hilos-backup-restore-id')
  await expect(confirm).toBeDisabled()
  await input.fill('')
  await input.pressSequentially('not-the-id')
  await expect(confirm).toBeDisabled()
  await input.fill('')
  await input.pressSequentially(backupId)
  await expect(confirm).toBeEnabled()

  // Cancel rather than confirm — see the note at the top of this test.
  await page.keyboard.press('Escape')
  await expect(confirm).toHaveCount(0)

  // Clean up the archive this test created, so a shared storage directory does not
  // grow one per run.
  const row = page.locator(`[data-id="hilos-table-row-${backupId}"]`)
  await row.locator('[data-id^="hilos-backup-delete-"]').click()
  await page.getByTestId('hilos-backup-delete-confirm').click()
  await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
})

// HIL-804 acceptance: a mass operation over a real table, started from the framework's
// selection panel and confirmed in the framework's own dialog. What it has to prove is
// the report — how many rows the run changed, and which it left alone and why — so one
// of the two marked archives is gone before its turn comes.
//
// That row is staged by a neighbor rather than by a trap. A second tab deletes one
// archive while this tab still holds it on screen behind its pending gate; this tab marks
// it anyway, and by the time the run reaches it the index no longer holds it. The
// framework answers for it before the storage agent is asked: the backup table says
// whether a row is still in its set (containsRow(), HIL-997), so the vanished archive is
// never handed to the agent, and the report carries the framework's own sentence. The
// ordering is not a race: the other tab's delete is the index write, the "will leave"
// badge here is the delta that write sent, and nothing is marked before the badge is on
// screen.
//
// The agent's own reasons are not staged, because none of them can be without a race or
// a second node: "already gone" is now answered by the framework first; "being taken
// right now" names a run in flight, and a run has no row to mark (HIL-820); "out of
// reach" needs a node this one cannot reach; "could not be deleted" needs a delete that
// throws, and a missing or locked archive file is unlinked in silence
// (BackupPruner::deleteStored).
test('deletes marked backups in bulk and names the one that was gone before its turn', async ({
  context,
}) => {
  // Two real dumps, the same headroom as the test of another tab's page above.
  test.slow()

  const tabA = await context.newPage()
  await openBackups(tabA)
  const goneFirst = await createNamedBackup(tabA)
  const deletedInBulk = await createNamedBackup(tabA)

  // Both rows are read from a fresh page once both runs are over.
  await gotoPage(tabA, '/hilos/backup')
  const goneFirstRow = tabA.getByTestId(`hilos-table-row-${goneFirst}`)
  const deletedInBulkRow = tabA.getByTestId(`hilos-table-row-${deletedInBulk}`)
  await expect(goneFirstRow).toBeVisible()
  await expect(deletedInBulkRow).toBeVisible()

  // The neighbor deletes one of the two the ordinary way, from a tab of its own.
  const tabB = await context.newPage()
  await gotoPage(tabB, '/hilos/backup')
  await expect(tabB.getByTestId(`hilos-table-row-${goneFirst}`)).toBeVisible()
  await deleteBackup(tabB, `hilos-table-row-${goneFirst}`)

  // This tab was told, and holds the removal behind its gate: the row still stands, with
  // the badge that says it will leave.
  await tabA.bringToFront()
  await expect(
    goneFirstRow.getByTestId(`hilos-table-pending-remove-${goneFirst}`),
  ).toBeVisible()

  const firstRowTop = await watchFirstRowTop(tabA)

  // Mark and unmark one row: neither marking nor clearing shifts the table.
  await deletedInBulkRow
    .getByTestId(`hilos-table-select-${deletedInBulk}`)
    .check()
  await deletedInBulkRow
    .getByTestId(`hilos-table-select-${deletedInBulk}`)
    .uncheck()
  await firstRowTop.unchanged()

  // Both are marked through their rows — the table's copy of each checkbox, this being
  // a wide screen.
  await goneFirstRow.getByTestId(`hilos-table-select-${goneFirst}`).check()
  await deletedInBulkRow
    .getByTestId(`hilos-table-select-${deletedInBulk}`)
    .check()
  await expect(tabA.getByTestId('hilos-table-selection-count')).toHaveText(
    /2 marked/,
  )
  await firstRowTop.unchanged()

  // Delete from the panel. The dialog closes on the reply, and the reply answers that
  // the run was accepted, nothing more: the work is watched through the bar and ends in
  // the report.
  await dismissToasts(tabA)
  await tabA.getByTestId('hilos-table-bulk-delete').click()
  const confirm = tabA.getByTestId('hilos-table-bulk-confirm')
  await watchForBulkBar(tabA)
  await clickSubmit(confirm)
  await expect(confirm).toHaveCount(0)

  // The bar stood while the run went, and a run that reported has taken it down.
  expect(await bulkBarWasDrawn(tabA)).toBe(true)
  await firstRowTop.unchanged()

  // The report comes to the tab that started the run. It counts the changed rows and
  // names the untouched one with its reason in the Details modal; the archive the run did
  // delete is not named, its removal having already traveled as a delta of its own.
  const report = tabA.getByTestId('hilos-table-bulk-report')
  await expect(report).toBeVisible({ timeout: 30_000 })
  await expect(report).toContainText(/Changed 1 rows?, 1 untouched/)
  await firstRowTop.unchanged()

  await tabA.getByTestId('hilos-table-bulk-report-details').click()
  const list = tabA.getByTestId('hilos-table-bulk-report-list')
  await expect(list).toBeVisible()
  await expect(list).toContainText(goneFirst)
  await expect(list).toContainText('The row was gone by the time its turn came')
  await expect(list).not.toContainText(deletedInBulk)

  await tabA
    .locator('.modal-footer')
    .getByRole('button', { name: 'Close' })
    .click()
  await expect(list).toHaveCount(0)
  await expect(report).toBeVisible()

  await expect(tabA.getByTestId('hilos-table-progress-bulk')).toHaveCount(0)

  // The report stays until the reader dismisses it. Once dismissed, the waiting
  // pending row with its Apply button takes the line, proving precedence.
  await tabA.getByTestId('hilos-table-bulk-report-close').click()
  await expect(report).toHaveCount(0)
  await firstRowTop.unchanged()

  await expect(tabA.getByTestId('hilos-table-pending-row')).toBeVisible()
  await expect(tabA.getByTestId('hilos-table-apply')).toBeVisible()

  // And the store agrees: a fresh page holds neither archive, so nothing is left for a
  // cleanup to do.
  await gotoPage(tabA, '/hilos/backup')
  await expect(tabA.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(tabA.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect(
    tabA.getByTestId(`hilos-table-row-${deletedInBulk}`),
  ).toHaveCount(0)
  await expect(tabA.getByTestId(`hilos-table-row-${goneFirst}`)).toHaveCount(0)
})

// HIL-802 acceptance, the "Order" menu. The backup table declares two orders of more
// than one column; the menu offers them after the order the table opened in, and that
// first item is the way back. Which order the window runs in is read off the headers:
// each carries aria-sort for its own column, set in the same call that asks the server
// for the window, so no archive has to be on the stand for the move to be seen.
test('switches the declared orders from the Order menu and goes back through its first item', async ({
  page,
}) => {
  await openBackups(page)

  const toggle = page
    .getByTestId('hilos-table-order')
    .getByTestId('hilos-dropdown-toggle')
  const opening = page.getByTestId('hilos-table-order-opening')
  const scopeThenDate = page.getByTestId('hilos-table-order-scope_created')
  const scopeThenDateMirror = page.getByTestId(
    'hilos-table-order-scope_created-mirror',
  )
  const statusThenDate = page.getByTestId('hilos-table-order-status_created')
  const dateHeader = page.locator(
    'th:has([data-id="hilos-table-sort-createdAt"])',
  )
  const scopeHeader = page.locator('th:has([data-id="hilos-table-sort-scope"])')
  const statusHeader = page.locator(
    'th:has([data-id="hilos-table-sort-status"])',
  )

  // Newest first is the order the table opens in, and the menu's first item is it.
  await expect(dateHeader).toHaveAttribute('aria-sort', 'descending')
  await toggle.click()
  await expect(opening).toHaveAttribute('aria-selected', 'true')
  await expect(scopeThenDate).toHaveAttribute('aria-selected', 'false')
  await expect(statusThenDate).toHaveAttribute('aria-selected', 'false')

  // A declared order is taken whole: scope first, then newest first within a scope.
  await scopeThenDate.click()
  await expect(scopeHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect(dateHeader).toHaveAttribute('aria-sort', 'descending')
  await expect(statusHeader).toHaveAttribute('aria-sort', 'none')
  await toggle.click()
  await expect(scopeThenDate).toHaveAttribute('aria-selected', 'true')
  await expect(opening).toHaveAttribute('aria-selected', 'false')

  // Right after a declared order stands its mirror, which nobody declared: the same
  // columns with every direction turned (HIL-1095), and the item lights up on its own.
  await scopeThenDateMirror.click()
  await expect(scopeHeader).toHaveAttribute('aria-sort', 'descending')
  await expect(dateHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect(statusHeader).toHaveAttribute('aria-sort', 'none')
  await toggle.click()
  await expect(scopeThenDateMirror).toHaveAttribute('aria-selected', 'true')
  await expect(scopeThenDate).toHaveAttribute('aria-selected', 'false')

  // One declared order gives way to the other whole, rather than to a mix of the two.
  await statusThenDate.click()
  await expect(statusHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect(scopeHeader).toHaveAttribute('aria-sort', 'none')
  await expect(dateHeader).toHaveAttribute('aria-sort', 'descending')

  // The first item returns the order the table opened in.
  await toggle.click()
  await expect(statusThenDate).toHaveAttribute('aria-selected', 'true')
  await opening.click()
  await expect(statusHeader).toHaveAttribute('aria-sort', 'none')
  await expect(scopeHeader).toHaveAttribute('aria-sort', 'none')
  await expect(dateHeader).toHaveAttribute('aria-sort', 'descending')
  await toggle.click()
  await expect(opening).toHaveAttribute('aria-selected', 'true')
})

// HIL-802 acceptance, the header. The table opens newest first, and a cycle counted by
// clicks — ascending, descending, back to the opening order — would spend its third click
// going "back" to the very descending order the second one had drawn: a click after which
// nothing moves. The cycle is read from the order on display instead, and skips it. The
// headers are read as in the menu test above.
test('a third click on the header the table opened by still moves the order', async ({
  page,
}) => {
  await openBackups(page)

  const dateHeader = page.locator(
    'th:has([data-id="hilos-table-sort-createdAt"])',
  )
  const sortByDate = page.getByTestId('hilos-table-sort-createdAt')
  await expect(dateHeader).toHaveAttribute('aria-sort', 'descending')

  await sortByDate.click()
  await expect(dateHeader).toHaveAttribute('aria-sort', 'ascending')

  // The second click lands on the opening order, which is also where a reset would go.
  await sortByDate.click()
  await expect(dateHeader).toHaveAttribute('aria-sort', 'descending')

  await sortByDate.click()
  await expect(dateHeader).toHaveAttribute('aria-sort', 'ascending')
})

test.describe('the backup section in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens the create and delete dialogs and marks a row, and has nothing to send from any of them', async ({
    page,
    browser,
  }) => {
    await openBackups(page)
    const backupId = await createNamedBackup(page)

    await setAdminViewMode(true)

    const guestContext = await browser.newContext()
    const guest = await guestContext.newPage()
    await gotoPage(guest, '/hilos/backup', PAGE_READY)
    await expect(guest.getByTestId('page-error')).toHaveCount(0)
    await expect(guest.getByTestId('hilos-viewport-table')).toBeVisible()

    const createButton = guest.getByTestId('hilos-table-main-action')
    await expect(createButton).toBeEnabled()
    await createButton.click()

    const dialog = guest.getByTestId('modal')
    await expect(dialog).toBeVisible()
    const scopeSelect = dialog.getByTestId('hilos-backup-create-scope')
    await expect(scopeSelect).toBeEnabled()
    await scopeSelect.selectOption('schema-only')

    const create = dialog.getByTestId('hilos-backup-create-confirm')
    await expect(create).toBeDisabled()
    await expect(create).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )

    const createCancel = dialog.getByTestId('hilos-backup-create-cancel')
    await expect(createCancel).toBeEnabled()
    await createCancel.click()
    await expect(dialog).toBeHidden()

    const row = guest.getByTestId(`hilos-table-row-${backupId}`)
    await expect(row).toBeVisible()
    const deleteButton = row.getByTestId(`hilos-backup-delete-${backupId}`)
    await expect(deleteButton).toBeEnabled()
    await deleteButton.click()

    const confirm = guest.getByTestId('hilos-backup-delete-confirm')
    await expect(confirm).toBeDisabled()
    await expect(confirm).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )

    await guest.getByTestId('hilos-backup-delete-cancel').click()
    await expect(confirm).toBeHidden()

    await row.getByTestId(`hilos-table-select-${backupId}`).check()
    await expect(guest.getByTestId('hilos-table-selection-count')).toHaveText(
      /1 marked/,
    )
    const bulk = guest.getByTestId('hilos-table-bulk-delete')
    await expect(bulk).toBeDisabled()
    await expect(bulk).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )

    await guest.getByTestId('hilos-table-selection-clear').click()
    await expect(
      row.getByTestId(`hilos-table-select-${backupId}`),
    ).not.toBeChecked()

    await gotoPage(page, '/hilos/backup')
    await expect(page.getByTestId(`hilos-table-row-${backupId}`)).toBeVisible()

    await deleteBackup(page, `hilos-table-row-${backupId}`)
    await guestContext.close()
  })
})

// NOT covered on purpose: a refused restore reaching the tab as a toast. Producing
// the refusal needs an archive the backend rejects — a checksum mismatch, or a run
// the agent is already busy with — and both mean corrupting or occupying the shared
// stand to assert one string. The refusals themselves are unit-tested where they are
// decided (RestoreUiGateTest, BackupAgentTest), and the delivery path is the same
// addressed action_error the create failure already uses.
//
// Covered above rather than here: who may reach the control at all. The restore
// button lives behind the page's own door — AbstractHilosBackupPage inherits
// AbstractHilosPage's ADMIN access level, which closes the actions along with the
// subscription — so the two assertions belong in the guest and non-admin tests that
// already stand at the top of this file, and that is where they were added.
