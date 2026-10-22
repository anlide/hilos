// The Angular names page of a country over the real table binder and action
// lifecycle: the three states under the country's block in the shared head,
// the plus and the pencil by
// null against '' in the row and the correction alike, corrections under an
// empty base, the delete confirmation opened over the fresh row in focus with
// its value fixed, a known base that returns from the catalog on its own echo,
// one row in focus shared by both windows and neither interrupting the other's
// write, a route change, a card that disappears under an open confirmation, a
// viewer of the admin view mode, the table let go when the page ends, and the
// country's live name in the page owner and its breadcrumb entry. The windows'
// own behavior is tested beside them; the rules are the core's.
//
// And the names in the routed outlet over the real navigator and page
// subscription when their country is deleted elsewhere (HIL-1603): the one 404
// in place of the shared head and the table, the name window and its draft kept
// above it with Save refused, focus returned home from an opener the table took
// away, and every other way out as before.
import { HilosPages } from '@hilos/core'
import type { PageSubscriptionError } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nCountryNamesPage } from '../src/admin/i18n/details/HilosI18nCountryNamesPage.js'
import {
  answerAsCountryTab,
  countryCard,
  countryName,
  i18nBench,
  lastCrumb,
  liveCountryTab,
  mountOver,
  usInEnglish,
  usInFrench,
  usInSpanish,
} from './helpers/hilosI18n.js'
import type { LiveCountryTab } from './helpers/hilosI18n.js'

/** The one h1 of the page as drawn: the shared head's title. */
function title(): string | undefined {
  return document
    .querySelector('[data-id="hilos-page-head-title"]')
    ?.textContent?.trim()
}

function setup(viewer = false) {
  const bench = i18nBench(
    HilosPages.I18N_COUNTRY_NAMES,
    [usInEnglish, usInSpanish, usInFrench],
    viewer,
  )
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nCountryNamesPage, bench, {
    context: bench.context,
  })
  view.act(() => bench.countryCard())

  return { ...bench, ...view, unmounted }
}

/** The target the delete confirmation was handed, as its inputs. */
function deleteInputs(h: ReturnType<typeof setup>) {
  return h.fixture.debugElement.query(
    (node) => node.name === 'hilos-i18n-country-name-delete',
  ).componentInstance as {
    open: () => boolean
    target: () => { expectedName: string; kind: string } | null
  }
}

describe('HilosI18nCountryNamesPage', () => {
  it('tells loading from a card that is gone and draws the country block in the shared head above one names table', () => {
    const bench = i18nBench(HilosPages.I18N_COUNTRY_NAMES, [usInEnglish])
    const h = mountOver(HilosI18nCountryNamesPage, bench, {
      context: bench.context,
    })
    expect(
      document.querySelector('[aria-label="Loading country"]'),
    ).not.toBeNull()
    expect(h.el('hilos-page-head-skeleton')).not.toBeNull()
    expect(h.el('country-head-tile')).toBeNull()
    expect(document.querySelector('table')).toBeNull()
    h.act(() => bench.countryCard())
    const headings = document.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(title()).toBe('United States')
    expect(lastCrumb()).toBe('United States')
    expect(h.el('country-head-tile')?.textContent?.trim()).toBe('us')
    expect(h.el('country-head-code')?.textContent?.trim()).toBe('us')
    expect(h.el('country-head-description')?.textContent?.trim()).toBe(
      'Currency $ USD · Default locale not chosen',
    )
    expect(h.el('country-head-own')).toBeNull()
    expect(h.el('country-names')?.querySelector('h2')?.textContent).toContain(
      'Names in different languages',
    )
    expect(h.el('hilos-page-tabs')?.getAttribute('aria-label')).toBe(
      'Country pages',
    )
    const names = h.el(`hilos-page-tab-${HilosPages.I18N_COUNTRY_NAMES}`)
    expect(names?.classList.contains('active')).toBe(true)
    expect(names?.getAttribute('aria-current')).toBe('page')
    const overview = h.el(`hilos-page-tab-${HilosPages.I18N_COUNTRY}`)
    expect(overview?.getAttribute('href')).toBe('/hilos/i18n/countries/us')
    expect(overview?.getAttribute('aria-current')).toBeNull()
    expect(document.querySelector('table')).not.toBeNull()
    h.act(() =>
      bench.countryCard({
        ...countryCard,
        summary: { ...countryCard.summary, name: 'USA' },
      }),
    )
    expect(title()).toBe('USA')
    expect(lastCrumb()).toBe('USA')
    h.act(() =>
      bench.countryCard({
        ...countryCard,
        summary: { ...countryCard.summary, name: '', isOwn: true },
      }),
    )
    expect(title()).toBe('Unnamed country')
    expect(lastCrumb()).toBe('Unnamed country')
    expect(h.el('country-head-code')?.textContent?.trim()).toBe('us')
    expect(
      h.el('country-head-own')?.querySelector('.visually-hidden')?.textContent,
    ).toContain('Custom country.')
    h.act(() => bench.countryCard([]))
    expect(document.querySelector('table')).toBeNull()
    expect(h.el('country-names-unavailable')?.textContent).toContain(
      'no longer available',
    )
    expect(h.el('country-head-tile')).toBeNull()
    expect(title()).toBe('Country')
  })

  it('draws the plus by null and the pencil by "" in rows, corrections and cards, with a trash on every stored value', () => {
    const h = setup()
    h.click('hilos-table-expand-11')
    for (const surface of ['table', '[data-id="hilos-table-cards"]']) {
      const one = (id: string) =>
        document.querySelector(`${surface} [data-id="${id}"]`)
      for (const id of [
        'i18n-country-name-edit-en',
        'i18n-country-name-edit-es',
        'i18n-country-name-add-fr',
        'i18n-country-name-locale-edit-en-GB',
        'i18n-country-name-locale-edit-en-US',
        'i18n-country-name-locale-add-en-CA',
        'i18n-names-delete-en',
        'i18n-names-delete-es',
        'i18n-names-delete-en-GB',
        'i18n-names-delete-en-US',
      ]) {
        expect(one(id), `${surface} ${id}`).not.toBeNull()
      }
      for (const id of ['i18n-names-delete-fr', 'i18n-names-delete-en-CA']) {
        expect(one(id), `${surface} ${id}`).toBeNull()
      }
    }
    expect(h.el('i18n-country-name-edit-en')?.getAttribute('aria-label')).toBe(
      'Edit name · United States in English',
    )
    expect(
      h.el('i18n-country-name-locale-add-en-CA')?.getAttribute('aria-label'),
    ).toBe('Add locale correction · United States in English · en-CA')
    expect(h.el('i18n-names-delete-es')?.getAttribute('aria-label')).toBe(
      'Delete country name in Español',
    )
    expect(h.el('i18n-names-delete-en-US')?.getAttribute('aria-label')).toBe(
      'Remove locale correction en-US',
    )
    expect(
      [...document.querySelectorAll('th')].filter(
        (cell) => cell.textContent?.trim() === 'Actions',
      ),
    ).toHaveLength(1)
  })

  it('opens a correction under an empty base and takes its parent row into focus', () => {
    const h = setup()
    h.act(() => h.delta({ ...usInEnglish, name: '' }))
    h.click('hilos-table-expand-11')
    h.click('i18n-country-name-locale-add-en-CA')
    expect(h.el('i18n-country-name-title')?.textContent).toContain(
      'Add locale correction · United States',
    )
    expect(h.el('i18n-country-name-window')?.textContent).toContain('en-CA')
    expect(h.focuses.at(-1)).toBe('11')
    h.click('i18n-country-name-submit')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_name_add',
      payload: {
        countryId: 102,
        inLanguageId: 11,
        baseNameId: 301,
        localeId: 503,
        name: '',
      },
    })
  })

  it('opens the confirmation over the fresh row, fixes its value, follows the row and lets it go on close', () => {
    const h = setup()
    h.act(() => h.delta({ ...usInEnglish, name: 'Fresh' }))
    h.click('i18n-names-delete-en')
    expect(h.focuses.at(-1)).toBe('11')
    expect(deleteInputs(h).open()).toBe(true)
    expect(deleteInputs(h).target()).toMatchObject({
      kind: 'base',
      expectedName: 'Fresh',
    })
    expect(h.el('modal')?.textContent).toContain(
      'Delete name · United States in English',
    )
    h.act(() => h.delta({ ...usInEnglish, name: 'Newer' }))
    expect(deleteInputs(h).target()?.expectedName).toBe('Fresh')
    h.click('i18n-country-name-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
    expect(h.el('modal')).toBeNull()
    h.click('i18n-names-delete-en')
    expect(deleteInputs(h).target()?.expectedName).toBe('Newer')
  })

  it('takes a saved empty correction from the fresh row and removes only it', async () => {
    const h = setup()
    h.click('hilos-table-expand-11')
    h.click('i18n-names-delete-en-US')
    expect(deleteInputs(h).target()).toMatchObject({
      kind: 'override',
      expectedName: '',
    })
    h.click('i18n-country-name-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_name_override_delete',
      payload: {
        countryId: 102,
        inLanguageId: 11,
        nameId: 402,
        localeId: 502,
        expectedName: '',
      },
    })
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('holds a known base through its own echo, which brings the catalog name back, and closes on the ack', async () => {
    const h = setup()
    h.act(() => h.delta({ ...usInEnglish, name: 'My States' }))
    h.click('i18n-names-delete-en')
    h.click('i18n-country-name-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_name_delete',
      payload: {
        countryId: 102,
        inLanguageId: 11,
        nameId: 301,
        expectedName: 'My States',
      },
    })
    h.act(() => h.delta({ ...usInEnglish, corrections: [] }))
    expect(h.el('modal')).not.toBeNull()
    expect(
      h.el('i18n-country-name-delete-notice')?.textContent?.trim() ?? '',
    ).toBe('')
    await h.settle(() => h.succeed(0, 'Name deleted.'))
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.el('hilos-table-row-11')?.textContent).toContain('United States')
  })

  it('shares one row in focus: each window lets the other go before it takes its own', () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    expect(deleteInputs(h).open()).toBe(true)
    h.click('i18n-country-name-edit-es')
    expect(deleteInputs(h).open()).toBe(false)
    expect(h.el('i18n-country-name-window')).not.toBeNull()
    expect(h.focuses.slice(-2)).toEqual(['', '15'])
    h.click('i18n-names-delete-en')
    expect(h.el('i18n-country-name-window')).toBeNull()
    expect(deleteInputs(h).open()).toBe(true)
    expect(h.focuses.at(-1)).toBe('11')
  })

  it("lets neither window take the row from under the other's write in flight", async () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    h.click('i18n-country-name-delete-confirm')
    h.click('i18n-country-name-edit-es')
    expect(deleteInputs(h).open()).toBe(true)
    expect(h.el('i18n-country-name-window')).toBeNull()
    expect(h.focuses.at(-1)).toBe('11')
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
    h.click('i18n-country-name-edit-es')
    h.type('i18n-country-name-input', 'Estados Unidos')
    h.click('i18n-country-name-submit')
    h.click('i18n-names-delete-en')
    expect(deleteInputs(h).open()).toBe(false)
    expect(h.el('i18n-country-name-window')).not.toBeNull()
    expect(h.focuses.at(-1)).toBe('15')
  })

  it('closes both windows on a route change and lets their row go', () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { countryCode: 'ca' } }),
    )
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { countryCode: 'us' } }),
    )
    h.click('i18n-country-name-edit-en')
    h.type('i18n-country-name-input', 'Draft')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { countryCode: 'ca' } }),
    )
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('binds a new name window for the country the address names now', () => {
    const h = setup()
    h.click('i18n-country-name-edit-en')
    const former = document.querySelector('hilos-i18n-country-name-window')
    expect(former).not.toBeNull()
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { countryCode: 'ca' } }),
    )
    expect(h.el('modal')).toBeNull()
    const next = document.querySelector('hilos-i18n-country-name-window')
    expect(next).not.toBeNull()
    expect(next).not.toBe(former)
  })

  it('keeps the confirmation open with its explanation when the card disappears', () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    h.act(() => h.countryCard([]))
    expect(document.querySelector('table')).toBeNull()
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-country-name-delete-notice')?.textContent).toContain(
      'The name no longer exists.',
    )
    expect(
      h.el<HTMLButtonElement>('i18n-country-name-delete-confirm')?.disabled,
    ).toBe(true)
  })

  it('locks every trash for a viewer while the name windows open and read', () => {
    const h = setup(true)
    h.click('hilos-table-expand-11')
    const trashes = [
      ...document.querySelectorAll<HTMLButtonElement>(
        '[data-id^="i18n-names-delete-"]',
      ),
    ]
    expect(trashes.length).toBeGreaterThan(0)
    for (const trash of trashes) {
      expect(trash.disabled).toBe(true)
      expect(trash.getAttribute('aria-describedby')).toBeTruthy()
    }
    for (const id of [
      'i18n-country-name-add-fr',
      'i18n-country-name-locale-edit-en-US',
    ]) {
      expect(h.el<HTMLButtonElement>(id)?.disabled).toBe(false)
      h.click(id)
      expect(h.el('i18n-country-name-window')).not.toBeNull()
      expect(
        h.el<HTMLButtonElement>('i18n-country-name-submit')?.disabled,
      ).toBe(true)
      h.submit()
      h.click('i18n-country-name-cancel')
    }
    expect(h.calls).toHaveLength(0)
  })

  it('binds a new context once and lets the table go when the page ends', () => {
    const h = setup()
    const bound = h.listenerCount()
    h.click('i18n-country-name-edit-en')
    h.act(() => h.fixture.componentRef.setInput('context', { ...h.context }))
    expect(h.el('modal')).toBeNull()
    expect(h.listenerCount()).toBe(bound)
    h.fixture.destroy()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listenerCount()).toBe(h.unmounted)
  })

  it('names the country from the first paint, renames it live over the same table, and lets the name go with its binding', () => {
    const bench = i18nBench(HilosPages.I18N_COUNTRY_NAMES, [usInEnglish])
    bench.countryCard({
      ...countryCard,
      summary: { ...countryCard.summary, name: null },
    })
    const h = mountOver(HilosI18nCountryNamesPage, bench, {
      context: bench.context,
    })
    expect(lastCrumb()).toBe('Unnamed country')
    expect(countryName(bench)).toEqual(['Unnamed country', 'Unnamed country'])
    const table = document.querySelector('table')
    for (const [live, shown] of [
      ['USA', 'USA'],
      ['', 'Unnamed country'],
      ['  Spaced  ', '  Spaced  '],
    ] as const) {
      h.act(() =>
        bench.countryCard({
          ...countryCard,
          summary: { ...countryCard.summary, name: live },
        }),
      )
      expect(countryName(bench)).toEqual([shown, shown])
    }
    expect(document.querySelector('table')).toBe(table)
    expect(bench.unregister).not.toHaveBeenCalled()
    h.act(() =>
      bench.route.set({ ...bench.route.get(), params: { countryCode: 'ca' } }),
    )
    h.act(() => bench.countryCard(null))
    expect(countryName(bench)).toEqual(['Country', 'Country'])
    h.act(() =>
      bench.countryCard({
        ...countryCard,
        countryId: 103,
        code: 'ca',
        summary: { ...countryCard.summary, name: 'Canada' },
      }),
    )
    expect(countryName(bench)).toEqual(['Canada', 'Canada'])
    const next = i18nBench(HilosPages.I18N_COUNTRY_NAMES, [usInEnglish])
    h.act(() => h.fixture.componentRef.setInput('context', next.context))
    expect(countryName(bench)).toEqual(['Country', 'Country'])
    h.act(() => next.countryCard())
    expect(countryName(next)).toEqual(['United States', 'United States'])
    const other = next.scopes.openPage(HilosPages.I18N_LANGUAGE)
    other.data.set('pageLabel', 'Language')
    other.data.set('pageBreadcrumb', [
      { page: HilosPages.I18N_COUNTRY, label: 'Country' },
    ])
    other.data.set('countryCard', countryCard)
    expect(next.router.pageIdentity.get()?.breadcrumb[0]?.label).toBe('Country')
    const main = next.scopes.openPage(HilosPages.I18N_COUNTRY)
    answerAsCountryTab(main, HilosPages.I18N_COUNTRY)
    main.data.set('countryCard', countryCard)
    expect(countryName(next)).toEqual(['United States', 'United States'])
    h.fixture.destroy()
    expect(countryName(next)).toEqual(['Country', 'Country'])
  })
})

/** The ordinary 404 of a country names page whose country does not exist. */
const NAMES_NOT_FOUND: PageSubscriptionError = {
  page: HilosPages.I18N_COUNTRY_NAMES,
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** The live 404 a viewer gets when the country is deleted under its names. */
const NAMES_DELETED: PageSubscriptionError = {
  ...NAMES_NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

/**
 * A country with no names, so one that can be deleted: names refer to their
 * country and stop its deletion, and the window open under it is the one the
 * plus of a language without a name opens.
 */
const namelessCard = {
  ...countryCard,
  summary: { ...countryCard.summary, nameCount: 0 },
}

function liveNames(): LiveCountryTab {
  return liveCountryTab(
    HilosPages.I18N_COUNTRY_NAMES,
    HilosI18nCountryNamesPage,
    [usInFrench],
  )
}

/**
 * Open the French name's add window from its plus in the table and type a
 * draft into it.
 *
 * @param live The answered page.
 */
function draftName(live: LiveCountryTab) {
  const opener = document.querySelector<HTMLElement>(
    'tr [data-id="i18n-country-name-add-fr"]',
  )!
  opener.focus()
  opener.click()
  live.detect()
  const input = live.el<HTMLInputElement>('i18n-country-name-input')!
  expect(document.activeElement).toBe(input)
  live.type('i18n-country-name-input', 'Draft name')
  expect(input.value).toBe('Draft name')

  return { opener, input, mounts: live.mounted.mock.calls.length }
}

/** Every page error drawn, wherever it stands. */
function pageErrors(): NodeListOf<Element> {
  return document.querySelectorAll('[data-id="page-error"]')
}

describe('HilosI18nCountryNamesPage under a country deleted elsewhere', () => {
  it('keeps the name window and its draft above the one 404, refuses Save, and returns focus home from the table opener', async () => {
    const live = liveNames()
    await live.answer(namelessCard)
    expect(live.el('hilos-page-head-block')).not.toBeNull()
    expect(live.el('country-head-tile')).not.toBeNull()
    expect(document.querySelector('table')).not.toBeNull()
    const { opener, input, mounts } = draftName(live)
    expect(mounts).toBe(1)

    await live.refuse(NAMES_DELETED)

    expect(live.mounted).toHaveBeenCalledTimes(mounts)
    expect(live.el('i18n-country-name-input')).toBe(input)
    expect(input.value).toBe('Draft name')
    expect(input.disabled).toBe(false)
    expect(document.activeElement).toBe(input)
    expect(live.el('i18n-country-name-notice')?.textContent).toContain(
      'Deleted elsewhere — your text stays to copy.',
    )
    const submit = live.el<HTMLButtonElement>('i18n-country-name-submit')!
    expect(submit.disabled).toBe(true)
    expect(submit.textContent?.trim()).toBe('Deleted')
    const shell = live.el('hilos-admin-page')!
    expect(pageErrors()).toHaveLength(1)
    expect(shell.contains(pageErrors()[0]!)).toBe(true)
    expect(
      shell
        .querySelector('[data-id="page-error-transition-message"]')
        ?.textContent?.trim(),
    ).toBe('It was deleted just now.')
    for (const gone of [
      'hilos-page-head',
      'hilos-breadcrumb',
      'hilos-page-head-title',
      'hilos-admin-title',
      'hilos-page-tabs',
      'country-head-tile',
      'country-names',
      'country-names-unavailable',
    ]) {
      expect(live.el(gone)).toBeNull()
    }
    expect(document.querySelector('table')).toBeNull()
    expect(opener.isConnected).toBe(false)
    const headings = document.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.textContent?.trim()).toBe('404')

    live.submit(input.form)
    live.click('i18n-country-name-submit')
    expect(live.calls).toEqual([])

    live.click('i18n-country-name-cancel')
    expect(live.el('modal-confirm-discard')).not.toBeNull()
    expect(live.el('i18n-country-name-input')).toBe(input)
    await live.settle(() => live.el('modal-confirm-discard')!.click())

    expect(live.el('i18n-country-name-window')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(live.el('page-error-transition-message')).not.toBeNull()
    expect(document.activeElement).toBe(live.el('page-error-home'))
    expect(live.calls).toEqual([])
  })

  it('draws the same 404 in place of the head and the table with no window open', async () => {
    const live = liveNames()
    await live.answer(namelessCard)

    await live.refuse(NAMES_DELETED)

    expect(pageErrors()).toHaveLength(1)
    expect(
      live
        .el('hilos-admin-page')
        ?.querySelector('[data-id="page-error-transition-message"]')
        ?.textContent?.trim(),
    ).toBe('It was deleted just now.')
    expect(live.el('hilos-page-head')).toBeNull()
    expect(document.querySelector('table')).toBeNull()
    expect(live.el('modal')).toBeNull()
    expect(live.el('hilos-page-state')?.getAttribute('data-state')).toBe(
      'error',
    )
  })

  it('keeps the window and its draft through a later 404 that brings no line', async () => {
    const live = liveNames()
    await live.answer(namelessCard)
    const { input, mounts } = draftName(live)
    await live.refuse(NAMES_DELETED)

    await live.refuse(NAMES_NOT_FOUND)

    expect(live.el('page-error-transition-message')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(live.el('hilos-admin-page')?.contains(pageErrors()[0]!)).toBe(true)
    expect(live.el('i18n-country-name-input')).toBe(input)
    expect(input.value).toBe('Draft name')
    expect(live.mounted).toHaveBeenCalledTimes(mounts)
  })

  it.each([
    {
      name: "the server's 403",
      leave: (live: LiveCountryTab) =>
        live.subscription.handleSubscriptionError({
          ...NAMES_NOT_FOUND,
          httpCode: 403,
          errorCode: 'forbidden',
          title: 'Forbidden',
          message: 'Server refusal words',
        }),
      code: '403',
    },
    {
      name: 'the access the client saw go',
      leave: (live: LiveCountryTab) => live.navigation.denyCurrentPage(),
      code: '403',
    },
    {
      name: 'leaving for another country',
      leave: (live: LiveCountryTab) =>
        live.navigation.navigate('/hilos/i18n/countries/fr/names'),
      code: null,
    },
  ])(
    'takes the window and its draft away on $name after the 404',
    async ({ leave, code }) => {
      const live = liveNames()
      await live.answer(namelessCard)
      draftName(live)
      await live.refuse(NAMES_DELETED)

      await live.settle(() => leave(live))

      expect(live.el('i18n-country-name-window')).toBeNull()
      expect(live.el('modal')).toBeNull()
      expect(live.el('hilos-admin-page')).toBeNull()
      if (code === null) {
        expect(pageErrors()).toHaveLength(0)
      } else {
        expect(pageErrors()).toHaveLength(1)
        expect(pageErrors()[0]!.getAttribute('data-error-code')).toBe(code)
      }
    },
  )

  it('never mounts the names page for a 404 that answers its first subscribe', async () => {
    const live = liveNames()

    await live.refuse(NAMES_NOT_FOUND)

    expect(live.mounted).not.toHaveBeenCalled()
    expect(live.el('hilos-admin-page')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(live.el('page-error-transition-message')).toBeNull()
  })
})
