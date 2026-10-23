import { expect, test, type Locator, type Page } from '@playwright/test'
import {
  I18N_OVERVIEW_DATA,
  i18nOverviewSchema,
  type HilosI18nOverview,
} from '../../../../../framework/frontend/core/src/admin/i18n/index.js'
import {
  countSentFrames,
  dismissToasts,
  expectI18nPageHead,
  holdPageReply,
  watchClientDocument,
  watchI18nLivePages,
  watchSocketFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { switchLanguageOff } from '../helpers/i18n.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { typeInto } from '../helpers/session.js'

// The Vue overview's two tiles, first complete answer and live summary. Section
// navigation in React and Angular stays in their list/card specs until their
// overview tiles land in HIL-1726 and HIL-1727.

const ROOT = 'hilos_i18n'
const ROOT_PATH = '/hilos/i18n'
const SECTION = [
  { key: ROOT, path: ROOT_PATH, body: 'i18n-overview' },
  {
    key: 'hilos_i18n_languages',
    path: `${ROOT_PATH}/languages`,
    body: 'i18n-languages-catalog',
  },
  {
    key: 'hilos_i18n_countries',
    path: `${ROOT_PATH}/countries`,
    body: 'i18n-countries-catalog',
  },
] as const

/** The existing submit discipline, with focus explicit on these stable dialogs. */
async function submit(button: Locator): Promise<void> {
  await button.scrollIntoViewIfNeeded()
  await expect(button).toBeVisible()
  await expect(button).toBeEnabled()
  await button.focus()
  await expect(button).toBeFocused()
  await button.click()
}

/** Read the Vue section's shared head without accepting an extra current tab. */
async function expectHead(page: Page, currentPage = ROOT): Promise<void> {
  await expectI18nPageHead(expect, page, {
    currentPage,
    appName: 'BTC Tracker',
    tabsLabel: 'Internationalization pages',
  })
}

/** Compare both complete tiles together, including their names and breakdown. */
async function expectOverview(
  page: Page,
  overview: HilosI18nOverview,
): Promise<void> {
  const { languages, countries } = overview
  await expect(
    page.getByTestId(/^i18n-overview-(languages|countries)-count$/),
  ).toHaveText([
    `${languages.enabledCount} of ${languages.totalCount}`,
    `${countries.enabledCount} of ${countries.totalCount}`,
  ])
  for (const [subject, counts, caption] of [
    [
      'languages',
      languages,
      `Enabled · default ${languages.defaultLanguageName}`,
    ],
    [
      'countries',
      countries,
      `Enabled · ${countries.builtInCount} built-in, ${countries.ownCount} custom`,
    ],
  ] as const) {
    const tile = page.getByTestId(`i18n-overview-${subject}`)
    await expect(tile).toContainText(caption)
    const count = tile.getByTestId(`i18n-overview-${subject}-count`)
    await expect(count).toHaveRole('link')
    await expect(count).toHaveAttribute('href', `${ROOT_PATH}/${subject}`)
    await expect(count).toHaveAccessibleName(
      `${subject === 'languages' ? 'Languages' : 'Countries'}: ${counts.enabledCount} of ${counts.totalCount} enabled`,
    )
  }
  await expect(page.getByTestId('i18n-overview').getByRole('link')).toHaveCount(
    2,
  )
}

/** Observe every real root reply; a missing or partial overview stays evidence. */
function watchOverview(page: Page) {
  const replies: unknown[] = []
  const observer = watchSocketFrames(page, ({ wire }) => {
    // The socket is a JSON boundary. The schema below judges the whole overview,
    // including a root reply that omitted it, rather than filtering bad ones out.
    const message = wire as
      | {
          type?: unknown
          data?: {
            page?: unknown
            payload?: { data?: Record<string, unknown> }
          }
        }
      | undefined
    if (message?.type === 'page_response' && message.data?.page === ROOT) {
      replies.push(message.data.payload?.data?.[I18N_OVERVIEW_DATA])
    }
  })
  return {
    replies,
    read: () => replies.map((reply) => i18nOverviewSchema.parse(reply)),
    stop: () => observer.stop(),
  }
}

test('waits for the first whole response without invented zero tiles', async ({
  page,
}) => {
  await signUpAdmin(page)
  const answer = await holdPageReply(page, ROOT)
  const navigation = gotoPage(page, ROOT_PATH, PAGE_READY)
  const completed = navigation.catch((error: unknown) => error)
  try {
    await answer.received
    await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
      'data-state',
      'loading',
    )
    await expect(
      page.getByTestId(/^i18n-overview-(languages|countries)-count$/),
    ).toHaveCount(0)
    answer.release()
    await navigation
    await expectHead(page)
    await expect(page.getByTestId('i18n-overview-languages-count')).toHaveText(
      /^\d+ of \d+$/,
    )
    await expect(page.getByTestId('i18n-overview-countries-count')).toHaveText(
      /^\d+ of \d+$/,
    )
    await expect(
      page.getByTestId('i18n-overview').getByRole('link'),
    ).toHaveCount(2)
  } finally {
    answer.dispose()
    await completed
  }
})

test('updates the whole overview in its existing window after language and custom-country actions elsewhere', async ({
  page,
  context,
}) => {
  await signUpAdmin(page)
  await switchLanguageOff('de')
  const frames = watchOverview(page)
  await gotoPage(page, ROOT_PATH, PAGE_READY)
  await expectHead(page)
  await expect.poll(() => frames.replies.length).toBeGreaterThan(0)
  const initial = frames.read()[0]!
  // Real project counts, not the external built-in catalog or fixed seed totals.
  expect(initial.languages.totalCount).toBeGreaterThan(0)
  expect(initial.countries.totalCount).toBeGreaterThan(0)
  expect(initial.languages.defaultLanguageCode).toBe('en')
  await expectOverview(page, initial)
  const document = await watchClientDocument(page)
  const live = await watchI18nLivePages(expect, [page])
  const editor = await context.newPage()
  try {
    await gotoPage(editor, `${ROOT_PATH}/languages/de`, PAGE_READY)
    await expect(editor.getByTestId('language-card-enabled')).toHaveText(
      'Disabled',
    )
    await submit(editor.getByTestId('language-card-switch-on'))
    const on = editor.getByTestId('language-card-switch-on-confirm')
    await submit(on)
    await expect(on).toHaveCount(0)
    await expect(editor.getByTestId('language-card-enabled')).toHaveText(
      'Enabled',
    )
    const enabled = {
      ...initial,
      languages: {
        ...initial.languages,
        enabledCount: initial.languages.enabledCount + 1,
      },
    }
    await expectOverview(page, enabled)
    expect(frames.read().at(-1)).toEqual(enabled)
    await live.unchanged()
    await document.unchanged()
    await dismissToasts(editor)

    await submit(editor.getByTestId('language-card-switch-off'))
    const off = editor.getByTestId('language-card-switch-off-confirm')
    await submit(off)
    await expect(off).toHaveCount(0)
    await expect(editor.getByTestId('language-card-enabled')).toHaveText(
      'Disabled',
    )
    await expectOverview(page, initial)
    expect(frames.read().at(-1)).toEqual(initial)
    await live.unchanged()
    await document.unchanged()

    await gotoPage(editor, `${ROOT_PATH}/countries`, PAGE_READY)
    await submit(editor.getByTestId('hilos-table-main-action'))
    await typeInto(editor.getByTestId('i18n-country-add-code'), 'qx')
    await typeInto(editor.getByTestId('i18n-country-add-currency-symbol'), '¤')
    await typeInto(editor.getByTestId('i18n-country-add-currency-code'), 'XQX')
    await expect(editor.getByTestId('i18n-country-add-plate')).toHaveAttribute(
      'data-kind',
      'own',
    )
    await submit(editor.getByTestId('i18n-country-add-submit'))
    const add = editor.getByTestId('i18n-country-confirm-submit')
    await submit(add)
    await expect(add).toHaveCount(0)
    await expect(editor.getByTestId('hilos-toast-success')).toContainText(
      'Custom country qx added and switched off for now.',
    )
    const added = {
      ...initial,
      countries: {
        ...initial.countries,
        totalCount: initial.countries.totalCount + 1,
        ownCount: initial.countries.ownCount + 1,
      },
    }
    await expectOverview(page, added)
    expect(frames.read().at(-1)).toEqual(added)
    await live.unchanged()
    await document.unchanged()

    await gotoPage(editor, `${ROOT_PATH}/countries/qx`, PAGE_READY)
    await expect(editor.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await submit(editor.getByTestId('country-card-delete'))
    const remove = editor.getByTestId('i18n-country-delete-confirm')
    await submit(remove)
    await expect(remove).toHaveCount(0)
    await expectPageReady(editor)
    await expect(editor).toHaveURL(/\/hilos\/i18n\/countries$/)
    await expect(editor.getByTestId('hilos-toast-success')).toContainText(
      'Country qx deleted.',
    )
    await expectOverview(page, initial)
    expect(frames.read().at(-1)).toEqual(initial)
    // Other flushes may publish the same complete snapshot again. Every frame
    // still passes the schema; only the changes must follow these UI actions.
    const snapshots = frames.read()
    const transitions = snapshots.filter(
      (snapshot, index) =>
        index === 0 ||
        JSON.stringify(snapshot) !== JSON.stringify(snapshots[index - 1]),
    )
    expect(transitions).toEqual([initial, enabled, initial, added, initial])
    await live.unchanged()
    await document.unchanged()
  } finally {
    frames.stop()
    await document.dispose()
    await editor.close()
  }
})

test('opens each tile, uses Back and the page row, and keeps all three direct URLs through F5', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, ROOT_PATH, PAGE_READY)
  await expectHead(page)
  const document = await watchClientDocument(page)
  try {
    for (const tab of SECTION.slice(1)) {
      const subject =
        tab.key === 'hilos_i18n_languages' ? 'languages' : 'countries'
      await submit(page.getByTestId(`i18n-overview-${subject}-count`))
      await expect(page).toHaveURL(new RegExp(`${tab.path}$`))
      await expectPageReady(page)
      await expectHead(page, tab.key)
      await expect(page.getByTestId(tab.body)).toBeVisible()
      await page.goBack()
      await expect(page).toHaveURL(new RegExp(`${ROOT_PATH}$`))
      await expectPageReady(page)
      await expectHead(page)
      await expect(
        page.getByTestId('i18n-overview').getByRole('link'),
      ).toHaveCount(2)
      await document.unchanged()
    }
    for (const tab of SECTION.slice(1)) {
      const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
      await link.focus()
      await link.press('Enter')
      await expect(page).toHaveURL(new RegExp(`${tab.path}$`))
      await expectPageReady(page)
      await expectHead(page, tab.key)
      await document.unchanged()
    }
  } finally {
    await document.dispose()
  }
  for (const tab of SECTION) {
    await gotoPage(page, tab.path, PAGE_READY)
    await expectHead(page, tab.key)
    await expect(page.getByTestId(tab.body)).toBeVisible()
    await page.reload()
    await expectPageReady(page)
    await expect(page).toHaveURL(new RegExp(`${tab.path}$`))
    await expectHead(page, tab.key)
    await expect(page.getByTestId(tab.body)).toBeVisible()
  }
})

test('a viewer reads the same whole summary and follows both tiles without a write', async ({
  page,
  browser,
}) => {
  await signUpAdmin(page)
  const frames = watchOverview(page)
  await gotoPage(page, ROOT_PATH, PAGE_READY)
  await expect.poll(() => frames.replies.length).toBeGreaterThan(0)
  const initial = frames.read()[0]!
  await expectOverview(page, initial)
  await setAdminViewMode(true)
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const viewerContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const viewer = await viewerContext.newPage()
  const writes = countSentFrames(viewer, '"type":"action"')
  try {
    await gotoPage(viewer, ROOT_PATH, PAGE_READY)
    await expect(viewer.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'view',
    )
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expectHead(viewer)
    await expectOverview(viewer, initial)
    const document = await watchClientDocument(viewer)
    try {
      for (const tab of SECTION.slice(1)) {
        const subject =
          tab.key === 'hilos_i18n_languages' ? 'languages' : 'countries'
        await submit(viewer.getByTestId(`i18n-overview-${subject}-count`))
        await expect(viewer).toHaveURL(new RegExp(`${tab.path}$`))
        await expectPageReady(viewer)
        await expectHead(viewer, tab.key)
        await expect(viewer.getByTestId(tab.body)).toBeVisible()
        await submit(viewer.getByTestId(`hilos-page-tab-${ROOT}`))
        await expectPageReady(viewer)
        await expectHead(viewer)
        await expectOverview(viewer, initial)
        await document.unchanged()
      }
    } finally {
      await document.dispose()
    }
    // No action at all is needed to read this overview or its navigation.
    expect(writes()).toBe(0)
    expect(frames.read()).toEqual([initial])
  } finally {
    frames.stop()
    await viewerContext.close()
    await setAdminViewMode(false)
  }
})
