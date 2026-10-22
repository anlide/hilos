import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages, type PageSubscriptionError } from '@hilos/core'
import { HilosI18nCountryNamesPage } from '../src/admin/i18n/details/HilosI18nCountryNamesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  countryCard,
  el,
  click,
  change,
  lastCrumb,
  liveCountryTab,
  retiredCountryHeaderLeft,
  settle,
} from './hilosI18nHarness.js'
import { nameRow } from './hilosI18nDetailsHarness.js'

function setup(viewer = false) {
  const rows = [
    nameRow,
    {
      ...nameRow,
      inLanguageId: 13,
      nameId: null,
      rowKey: '13',
      code: 'fr',
      name: null,
      corrections: [],
    },
    {
      ...nameRow,
      inLanguageId: 15,
      nameId: 301,
      rowKey: '15',
      code: 'es',
      name: '',
      corrections: [],
    },
  ]
  const h = harness(HilosPages.I18N_COUNTRY_NAMES, rows, viewer)
  const view = h.mount(<HilosI18nCountryNamesPage context={h.context} />)
  return { ...h, view }
}

describe('React country names page', () => {
  it('waits for the card, draws the country block and the Overview · Names row, then distinguishes its deletion', () => {
    const h = setup()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    expect(el('country-head-tile')).toBeNull()
    expect(el('hilos-page-tabs')).toBeNull()
    expect(el('hilos-viewport-table')).toBeNull()
    expect(
      document.querySelector('[aria-label="Loading country"]'),
    ).not.toBeNull()
    act(() => h.card())
    const headings = document.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(headings[0]!.textContent).toBe('Guernsey')
    // The chain ends at the country, not at Names.
    expect(lastCrumb()).toBe('Guernsey')
    const block = el('hilos-page-head-block')
    expect(
      block.querySelector('[data-id="country-head-tile"]')!.textContent,
    ).toBe('gg')
    expect(
      block.querySelector('[data-id="country-head-code"]')!.textContent,
    ).toBe('gg')
    expect(block.querySelector('[data-id="country-head-own"]')).not.toBeNull()
    expect(
      block.querySelector('[data-id="country-head-description"]')!.textContent,
    ).toBe('Currency £ GBP · Default locale not chosen')
    expect(block.textContent).not.toMatch(/[0-9]/)
    expect(retiredCountryHeaderLeft()).toBe(false)
    expect(el('hilos-admin-children')).toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-page-tabs"]'),
    ).toHaveLength(1)
    expect(el('hilos-page-tabs').getAttribute('aria-label')).toBe(
      'Country pages',
    )
    expect(
      el('hilos-page-tab-hilos_i18n_country_names').getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    expect(el('hilos-page-tab-hilos_i18n_country').textContent).toBe('Overview')
    expect(el('hilos-page-tab-hilos_i18n_country').getAttribute('href')).toBe(
      '/hilos/i18n/countries/gg',
    )
    expect(
      el('hilos-page-tab-hilos_i18n_country').getAttribute('aria-current'),
    ).toBeNull()
    act(() =>
      h.card({
        ...countryCard,
        enabled: true,
        summary: {
          ...countryCard.summary,
          name: 'Live country',
          defaultLocaleId: 11143,
          defaultLocaleCode: 'en-GG',
          nameCount: 5,
        },
      }),
    )
    expect(el('hilos-page-head-title').textContent).toBe('Live country')
    expect(el('country-head-description').textContent).toBe(
      'Currency £ GBP · Default locale en-GG',
    )
    expect(document.body.textContent).not.toMatch(/Preview|Synchroniz|Flags/)
    act(() => h.card(null))
    expect(el('country-names-unavailable')).not.toBeNull()
    expect(el('hilos-page-head-title').textContent).toBe('Country')
    expect(el('country-head-tile')).toBeNull()
    expect(el('country-head-description')).toBeNull()
    expect(el('hilos-viewport-table')).toBeNull()
    expect(h.calls).toEqual([])
  })
  it.each([
    ['Guernsey', 'Guernsey', false],
    [null, 'Unnamed country', true],
    ['', 'Unnamed country', false],
  ] as const)(
    'binds %j from the Names tab as the country name %j, follows the next country and releases it on unmount (viewer %s)',
    (name, expected, viewer) => {
      const h = setup(viewer)
      const identity = h.navigation.pageIdentity
      act(() =>
        h.card({ ...countryCard, summary: { ...countryCard.summary, name } }),
      )
      expect(lastCrumb()).toBe(expected)
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(el('hilos-page-head-title').textContent).toBe(expected)
      expect(identity.get()?.pageHeadOwner?.label).toBe(expected)
      expect(identity.get()?.label).toBe('Country names')
      expect(el('country-head-code').textContent).toBe('gg')
      expect(el('hilos-page-tabs')).not.toBeNull()
      act(() =>
        h.card({
          ...countryCard,
          summary: { ...countryCard.summary, name: 'Renamed' },
        }),
      )
      expect(lastCrumb()).toBe('Renamed')
      expect(el('hilos-page-head-title').textContent).toBe('Renamed')
      act(() => {
        h.route.set({
          page: HilosPages.I18N_COUNTRY_NAMES,
          params: { countryCode: 'fr' },
          admin: true,
        })
        h.card({
          ...countryCard,
          countryId: 103,
          code: 'fr',
          summary: { ...countryCard.summary, name: 'France' },
        })
      })
      expect(el('hilos-page-head-title').textContent).toBe('France')
      expect(identity.get()?.pageHeadOwner?.label).toBe('France')
      expect(el('country-head-code').textContent).toBe('fr')
      expect(el('hilos-page-tab-hilos_i18n_country').getAttribute('href')).toBe(
        '/hilos/i18n/countries/fr',
      )
      h.view.unmount()
      expect(identity.get()?.pageHeadOwner?.label).toBe('Country')
      expect(identity.get()?.breadcrumb.at(-1)?.label).toBe('Country')
    },
  )
  it('renders add/edit/delete by null for bases and corrections in both projections', () => {
    const h = setup()
    act(() => h.card())
    click('hilos-table-expand-11')
    for (const projection of [
      document.querySelector('table')!,
      el('hilos-table-cards'),
    ]) {
      for (const id of [
        'i18n-country-name-edit-en',
        'i18n-country-name-edit-es',
        'i18n-country-name-add-fr',
        'i18n-country-name-locale-edit-en-GB',
        'i18n-country-name-locale-edit-en-US',
        'i18n-country-name-locale-add-en-CA',
        'i18n-names-delete-es',
        'i18n-names-delete-en-US',
      ])
        expect(projection.querySelector(`[data-id="${id}"]`)).not.toBeNull()
      expect(
        projection.querySelector('[data-id="i18n-names-delete-fr"]'),
      ).toBeNull()
      expect(
        projection.querySelector('[data-id="i18n-names-delete-en-CA"]'),
      ).toBeNull()
    }
  })
  it('adds an absent base through the page with null name coordinates', async () => {
    const h = setup()
    act(() => h.card())
    click('i18n-country-name-add-fr')
    expect(el('i18n-country-name-title').textContent).toContain('Add name')
    expect(h.focuses.at(-1)).toBe('13')
    change('i18n-country-name-input', 'Guernesey')
    click('i18n-country-name-submit')
    expect(h.calls).toEqual([
      {
        action: 'hilos_i18n_country_name_add',
        payload: {
          countryId: 143,
          inLanguageId: 13,
          localeId: null,
          baseNameId: null,
          name: 'Guernesey',
        },
        requestId: expect.any(String),
      },
    ])
    await settle(() => h.succeed())
    expect(el('i18n-country-name-window')).toBeNull()
  })
  it('opens a correction beneath an empty base and coordinates its shared focus with deletion', async () => {
    const h = setup()
    act(() => {
      h.card()
      h.delta({ ...nameRow, name: '' })
    })
    click('hilos-table-expand-11')
    click('i18n-country-name-locale-edit-en-US')
    expect(el('i18n-country-name-title').textContent).toContain(
      'Edit locale correction',
    )
    expect(h.focuses).toContain('11')
    await settle(() => click('i18n-names-delete-en'))
    expect(el('i18n-country-name-window')).toBeNull()
    expect(el('i18n-country-name-delete-window')).not.toBeNull()
    click('i18n-country-name-edit-es')
    expect(el('i18n-country-name-delete-window')).toBeNull()
    expect(el('i18n-country-name-window')).not.toBeNull()
    expect(h.focuses.at(-1)).toBe('15')
  })
  it('freezes the fresh focused text after await and keeps newer values for the server refusal', async () => {
    const h = setup()
    act(() => h.card())
    await settle(() => {
      click('i18n-names-delete-en')
      h.delta({ ...nameRow, name: 'Fresh' })
    })
    expect(el('i18n-country-name-delete-window').textContent).toContain('Fresh')
    act(() => h.delta({ ...nameRow, name: 'Newer' }))
    click('i18n-country-name-delete-confirm')
    expect(h.calls[0]?.payload).toMatchObject({ expectedName: 'Fresh' })
    await settle(() => h.refuse(0, 'The name changed'))
    expect(el('i18n-country-name-delete-error').textContent).toContain(
      'The name changed',
    )
    click('i18n-country-name-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
  })
  it('cancels a late delete opening without releasing a newly opened name', async () => {
    const h = setup()
    act(() => h.card())
    await settle(() => {
      click('i18n-names-delete-en')
      click('i18n-country-name-edit-es')
    })
    expect(el('i18n-country-name-delete-window')).toBeNull()
    expect(el('i18n-country-name-window')).not.toBeNull()
    expect(h.focuses.at(-1)).toBe('15')
  })
  it.each(['idle', 'pending'] as const)(
    'keeps a %s draft or confirmation through card deletion and cleans up on subject change',
    async (mode) => {
      const h = setup()
      act(() => h.card())
      if (mode === 'idle') {
        click('i18n-country-name-edit-en')
        change('i18n-country-name-input', 'Copy me')
      } else {
        await settle(() => click('i18n-names-delete-en'))
        click('i18n-country-name-delete-confirm')
      }
      act(() => h.card(null))
      if (mode === 'idle') {
        expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe(
          'Copy me',
        )
        expect(el('i18n-country-name-submit').textContent).toBe('Deleted')
      } else
        expect(
          el<HTMLButtonElement>('i18n-country-name-delete-cancel').disabled,
        ).toBe(true)
      act(() => {
        h.route.set({
          page: HilosPages.I18N_COUNTRY_NAMES,
          params: { countryCode: 'fr' },
          admin: true,
        })
        h.card({ ...countryCard, countryId: 103, code: 'fr' })
      })
      expect(el('modal')).toBeNull()
      expect(h.unregister).toHaveBeenCalled()
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.I18N_COUNTRY_NAMES,
        'hilosI18nCountryNames',
        expect.objectContaining({ filter: { country: 103 } }),
      )
      click('i18n-country-name-edit-en')
      if (mode === 'pending') await settle(() => h.succeed())
      expect(el('i18n-country-name-window')).not.toBeNull()
      h.view.unmount()
      expect(h.focuses.at(-1)).toBe('')
      for (const [event, listeners] of h.listeners)
        expect(listeners.size).toBe(h.baseListenerCounts.get(event) ?? 0)
    },
  )
  it('abandons an awaited focus from the former subject without disturbing the new form', async () => {
    const h = setup()
    act(() => h.card())
    await settle(() => {
      click('i18n-names-delete-en')
      h.route.set({
        page: HilosPages.I18N_COUNTRY_NAMES,
        params: { countryCode: 'fr' },
        admin: true,
      })
      h.card({ ...countryCard, countryId: 103, code: 'fr' })
    })
    expect(el('i18n-country-name-delete-window')).toBeNull()
    click('i18n-country-name-edit-en')
    change('i18n-country-name-input', 'New subject')
    click('i18n-country-name-submit')
    expect(h.calls[0]?.payload).toMatchObject({
      countryId: 103,
      name: 'New subject',
    })
    expect(h.focuses.at(-1)).toBe('11')
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })

  it('releases the old context and keeps its late failure out of the new opening', async () => {
    const h = setup()
    act(() => h.card())
    click('i18n-country-name-edit-en')
    change('i18n-country-name-input', 'Old')
    click('i18n-country-name-submit')
    const next = harness(HilosPages.I18N_COUNTRY_NAMES, [nameRow])
    next.card()
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountryNamesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    click('i18n-country-name-edit-en')
    change('i18n-country-name-input', 'New')
    await settle(() => h.refuse(0, 'Old failure'))
    expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe('New')
    expect(el('i18n-country-name-error').textContent).not.toContain(
      'Old failure',
    )
    expect(h.unregister).toHaveBeenCalled()
  })
  it('lets a viewer read the form and correction while every write remains disabled', () => {
    const h = setup(true)
    act(() => h.card())
    expect(el<HTMLButtonElement>('i18n-names-delete-en').disabled).toBe(true)
    click('hilos-table-expand-11')
    click('i18n-country-name-locale-edit-en-US')
    change('i18n-country-name-input', 'Viewer')
    click('i18n-country-name-submit')
    expect(el<HTMLButtonElement>('i18n-country-name-submit').disabled).toBe(
      true,
    )
    expect(h.calls).toEqual([])
  })
  it('uses the controller pending/Apply gate and its refusal state', () => {
    const h = setup()
    act(() => h.card())
    act(() => h.delta(nameRow, 'row_removed'))
    expect(el('hilos-table-row-11').textContent).toContain('English')
    expect(el('hilos-table-apply')).not.toBeNull()
    click('hilos-table-apply')
    expect(el('hilos-table-row-11').textContent).toContain('Removed')
    act(() =>
      h.emit('tableWindowRefused', {
        data: {
          page: HilosPages.I18N_COUNTRY_NAMES,
          tableKey: h.tableKey,
          errorCode: 'source_unavailable',
        },
      }),
    )
    expect(el('hilos-table-unavailable')).not.toBeNull()
  })
  it('draws the immediate catalog base return, removes corrections and waits for the deletion ack', async () => {
    const h = setup()
    act(() => h.card({ ...countryCard, countryId: 143, code: 'gg' }))
    await settle(() => click('i18n-names-delete-en'))
    expect(el('i18n-country-name-delete-window').textContent).toContain(
      'returns immediately as an unlocked name',
    )
    click('i18n-country-name-delete-confirm')
    act(() => h.delta({ ...nameRow, name: 'Catalog base', corrections: [] }))
    expect(el('hilos-table-row-11').textContent).toContain('Catalog base')
    expect(el('i18n-country-name-delete-window')).not.toBeNull()
    expect(h.calls[0]?.payload).toEqual({
      countryId: 143,
      inLanguageId: 11,
      nameId: 301,
      expectedName: 'Ukrainian',
    })
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
    expect(el('i18n-country-name-edit-en')).not.toBeNull()
    expect(el('hilos-table-expand-11')).toBeNull()
  })
  it('shows an absent custom base after deletion and inherits the base after removing a correction', async () => {
    const h = setup()
    act(() => h.card())
    click('hilos-table-expand-11')
    await settle(() => click('i18n-names-delete-en-US'))
    click('i18n-country-name-delete-confirm')
    act(() =>
      h.delta({
        ...nameRow,
        corrections: nameRow.corrections.map((item) =>
          item.localeCode === 'en-US' ? { ...item, name: null } : item,
        ),
      }),
    )
    await settle(() => h.succeed())
    expect(el('i18n-country-name-locale-add-en-US')).not.toBeNull()
    expect(el('i18n-names-delete-en-US')).toBeNull()
    expect(el('hilos-table-row-11').textContent).toContain('Ukrainian')
    await settle(() => click('i18n-names-delete-en'))
    click('i18n-country-name-delete-confirm')
    act(() =>
      h.delta({ ...nameRow, nameId: null, name: null, corrections: [] }),
    )
    await settle(() => h.succeed(1))
    expect(el('i18n-country-name-add-en')).not.toBeNull()
    expect(el('i18n-names-delete-en')).toBeNull()
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
 * A writing language with no name yet. Names refer to their country and stop
 * its deletion, so a country that can be deleted has none, and the window open
 * under its deletion is the one its plus opens.
 */
const WRITING_ROW = {
  ...nameRow,
  inLanguageId: 51,
  nameId: null,
  rowKey: '51',
  code: 'qk',
  nativeName: 'Writing',
  name: null,
  corrections: [],
}

/** A country with no names, so one that can be deleted. */
const NAMELESS_CARD = {
  ...countryCard,
  summary: { ...countryCard.summary, nameCount: 0 },
}

function liveNames() {
  return liveCountryTab(
    HilosPages.I18N_COUNTRY_NAMES,
    [WRITING_ROW],
    (context) => <HilosI18nCountryNamesPage context={context} />,
  )
}

/**
 * Open the base name's add window from its plus in the table and type a draft
 * into it.
 *
 * @param live The answered page.
 */
function draftName(live: ReturnType<typeof liveNames>) {
  const opener = document.querySelector<HTMLElement>(
    'tr [data-id="i18n-country-name-add-qk"]',
  )!
  opener.focus()
  fireEvent.click(opener)
  const input = el<HTMLInputElement>('i18n-country-name-input')
  expect(document.activeElement).toBe(input)
  change('i18n-country-name-input', 'Draft name')
  expect(input.value).toBe('Draft name')
  return { opener, input, mounts: live.mounted.mock.calls.length }
}

/** Every page error drawn, wherever it stands. */
function pageErrors(): NodeListOf<Element> {
  return document.querySelectorAll('[data-id="page-error"]')
}

describe('React country names under a country deleted elsewhere', () => {
  it('keeps the name window and its draft above the one 404, refuses Save, and returns focus home from the table opener', async () => {
    const live = liveNames()
    await live.answer(NAMELESS_CARD)
    const { opener, input, mounts } = draftName(live)
    expect(mounts).toBe(2)

    await settle(() => live.subscription.handleSubscriptionError(NAMES_DELETED))

    expect(live.mounted).toHaveBeenCalledTimes(mounts)
    expect(el('i18n-country-name-input')).toBe(input)
    expect(input.value).toBe('Draft name')
    expect(input.disabled).toBe(false)
    expect(document.activeElement).toBe(input)
    expect(el('i18n-country-name-notice').textContent).toContain(
      'Deleted elsewhere — your text stays to copy.',
    )
    const submit = el<HTMLButtonElement>('i18n-country-name-submit')
    expect(submit.disabled).toBe(true)
    expect(submit.textContent).toBe('Deleted')
    const shell = el('hilos-admin-page')
    expect(pageErrors()).toHaveLength(1)
    expect(shell.contains(pageErrors()[0]!)).toBe(true)
    expect(
      shell.querySelector('[data-id="page-error-transition-message"]')
        ?.textContent,
    ).toBe('It was deleted just now.')
    for (const gone of [
      'hilos-page-head',
      'hilos-breadcrumb',
      'hilos-page-tabs',
      'country-names',
      'country-names-unavailable',
    ])
      expect(el(gone)).toBeNull()
    expect(document.querySelector('table')).toBeNull()
    expect(opener.isConnected).toBe(false)
    const headings = document.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.textContent).toBe('404')

    fireEvent.submit(input.form!)
    click('i18n-country-name-submit')
    expect(live.calls).toEqual([])

    click('i18n-country-name-cancel')
    expect(el('modal-confirm-discard')).not.toBeNull()
    expect(el('i18n-country-name-input')).toBe(input)
    await settle(() => click('modal-confirm-discard'))

    expect(el('i18n-country-name-window')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(el('page-error-transition-message')).not.toBeNull()
    expect(document.activeElement).toBe(el('page-error-home'))
    expect(live.calls).toEqual([])
  })

  it('draws the same 404 in place of the head and the table with no window open', async () => {
    const live = liveNames()
    await live.answer(NAMELESS_CARD)

    await settle(() => live.subscription.handleSubscriptionError(NAMES_DELETED))

    expect(pageErrors()).toHaveLength(1)
    expect(
      el('hilos-admin-page').querySelector(
        '[data-id="page-error-transition-message"]',
      )?.textContent,
    ).toBe('It was deleted just now.')
    expect(el('hilos-page-head')).toBeNull()
    expect(document.querySelector('table')).toBeNull()
    expect(el('modal')).toBeNull()
    expect(el('hilos-page-state').getAttribute('data-state')).toBe('error')
  })

  it('keeps the window and its draft through a later 404 that brings no line', async () => {
    const live = liveNames()
    await live.answer(NAMELESS_CARD)
    const { input, mounts } = draftName(live)
    await settle(() => live.subscription.handleSubscriptionError(NAMES_DELETED))

    await settle(() =>
      live.subscription.handleSubscriptionError(NAMES_NOT_FOUND),
    )

    expect(el('page-error-transition-message')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(el('hilos-admin-page').contains(pageErrors()[0]!)).toBe(true)
    expect(el('i18n-country-name-input')).toBe(input)
    expect(input.value).toBe('Draft name')
    expect(live.mounted).toHaveBeenCalledTimes(mounts)
  })

  it.each([
    {
      name: "the server's 403",
      leave: (live: ReturnType<typeof liveNames>) =>
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
      leave: (live: ReturnType<typeof liveNames>) =>
        live.navigation.denyCurrentPage(),
      code: '403',
    },
    {
      name: 'leaving for another country',
      leave: (live: ReturnType<typeof liveNames>) =>
        live.navigation.navigate('/hilos/i18n/countries/fr/names'),
      code: null,
    },
  ])(
    'takes the window and its draft away on $name after the 404',
    async ({ leave, code }) => {
      const live = liveNames()
      await live.answer(NAMELESS_CARD)
      draftName(live)
      await settle(() =>
        live.subscription.handleSubscriptionError(NAMES_DELETED),
      )

      await settle(() => leave(live))

      expect(el('i18n-country-name-window')).toBeNull()
      expect(el('modal')).toBeNull()
      expect(el('hilos-admin-page')).toBeNull()
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

    await settle(() =>
      live.subscription.handleSubscriptionError(NAMES_NOT_FOUND),
    )

    expect(live.mounted).not.toHaveBeenCalled()
    expect(el('hilos-admin-page')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(el('page-error-transition-message')).toBeNull()
  })
})
