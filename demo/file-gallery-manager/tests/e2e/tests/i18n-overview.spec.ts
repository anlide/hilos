import type { Page } from '@playwright/test'
import { expect, test as base } from '../test.js'
import {
  clickSubmit,
  expectI18nOverview,
  expectI18nPageHead,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  PAGE_READY,
  watchClientDocument,
  watchI18nOverview,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })

// The React overview mounted in File Gallery Manager: real page scopes, a live
// redraw from the server, and count links followed by the router. The held
// first answer, the viewer, reload and the tab row stay outside this spec.

const APP_NAME = 'File Gallery Manager'
const TABS_LABEL = 'Internationalization pages'
const OVERVIEW_PATH = '/hilos/i18n'
const LISTS = [
  {
    countId: 'i18n-overview-languages-count',
    path: '/hilos/i18n/languages',
    currentPage: 'hilos_i18n_languages',
  },
  {
    countId: 'i18n-overview-countries-count',
    path: '/hilos/i18n/countries',
    currentPage: 'hilos_i18n_countries',
  },
] as const

/** The section head on Overview, Languages or Countries. */
async function expectSectionHead(
  page: Page,
  currentPage: string,
): Promise<void> {
  await expectI18nPageHead(expect, page, {
    currentPage,
    appName: APP_NAME,
    tabsLabel: TABS_LABEL,
  })
}

test('overview tiles follow the live summary and open their lists', async ({
  page,
  i18n,
}) => {
  await grantAdminToSelf(expect, page)
  const frames = watchI18nOverview(page)
  let document: Awaited<ReturnType<typeof watchClientDocument>> | undefined
  try {
    await test.step('Open Internationalization cold and match the first wire summary', async () => {
      await gotoPage(expect, page, OVERVIEW_PATH, PAGE_READY)
      await expectSectionHead(page, 'hilos_i18n')
      await expect.poll(() => frames.replies.length).toBeGreaterThan(0)
      const initial = frames.read()[0]!
      await expectI18nOverview(expect, page, initial)
    })

    const added =
      await test.step('A custom country raises both country counts without a reload', async () => {
        document = await watchClientDocument(page)
        const initial = frames.read()[0]!
        const next = {
          ...initial,
          countries: {
            ...initial.countries,
            totalCount: initial.countries.totalCount + 1,
            ownCount: initial.countries.ownCount + 1,
          },
        }
        await i18n.country({
          code: 'qp',
          currencySymbol: '¤',
          currencyCode: 'XQP',
        })
        await expectI18nOverview(expect, page, next)
        expect(frames.read().at(-1)).toEqual(next)
        await document.unchanged()
        return next
      })

    await test.step('Each count opens its list and Back returns the same tiles', async () => {
      if (document === undefined) {
        throw new Error('The overview document was not marked.')
      }
      const marked = document
      for (const list of LISTS) {
        await clickSubmit(expect, page.getByTestId(list.countId))
        await expect(page).toHaveURL(new RegExp(`${list.path}$`))
        await expectPageReady(expect, page)
        await expectSectionHead(page, list.currentPage)
        await marked.unchanged()
        await page.goBack()
        await expect(page).toHaveURL(new RegExp(`${OVERVIEW_PATH}$`))
        await expectPageReady(expect, page)
        await expectSectionHead(page, 'hilos_i18n')
        await expectI18nOverview(expect, page, added)
        await marked.unchanged()
      }
    })
  } finally {
    frames.stop()
    await document?.dispose()
  }
})
