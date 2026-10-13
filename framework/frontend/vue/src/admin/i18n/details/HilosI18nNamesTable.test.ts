import { mount } from '@vue/test-utils'
import {
  createHilosI18nNamesTable,
  createSignal,
  HILOS_I18N_LANGUAGE_NAMES,
  HILOS_I18N_COUNTRY_NAMES,
  type HilosI18nNamesTarget,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosI18nNameRow,
  type TableRow,
  type TableViewportController,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { h, nextTick } from 'vue'

import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

/** A names row as the backend sends it, its slot keyed `language`. */
function nameRow(
  code: string,
  nativeName: string,
  name: string | null,
  corrections: readonly Record<string, string | null>[] = [],
): TableRow {
  return {
    rowKey: code,
    slots: { language: { code, nativeName, name, corrections } },
  }
}

/** The names table of German with English, French and Spanish rows. */
function mountNames(
  target: HilosI18nNamesTarget = HILOS_I18N_LANGUAGE_NAMES,
  actions = false,
): {
  controller: TableViewportController<HilosI18nNameRow>
  wrapper: ReturnType<typeof mount>
} {
  const table = createHilosI18nNamesTable(
    {
      connection: {} as HilosConnection,
      scopes: new ScopeManager(),
      actions: {} as ActionLifecycle,
    },
    target,
    createSignal('de'),
  )
  table.controller.ingestWindow(
    [
      nameRow('en', 'English', 'German', [
        {
          localeCode: 'en-GB',
          countryCode: 'gb',
          countryName: 'United Kingdom',
          name: 'German (UK)',
        },
        {
          localeCode: 'en-US',
          countryCode: 'us',
          countryName: null,
          name: null,
        },
      ]),
      nameRow('es', 'Español', ''),
      nameRow('fr', 'Français', null),
    ],
    3,
    true,
    null,
    null,
    100,
  )
  const wrapper = mount(HilosI18nNamesTable, {
    props: { controller: table.controller },
    slots: actions
      ? {
          'name-actions': ({ row }: { row: HilosI18nNameRow }) =>
            h(
              'span',
              { 'data-id': `test-base-${row.code}` },
              row.name ?? 'absent',
            ),
          'correction-actions': ({
            row,
            correction,
          }: {
            row: HilosI18nNameRow
            correction: HilosI18nNameRow['corrections'][number]
          }) =>
            h(
              'span',
              { 'data-id': `test-correction-${correction.localeCode}` },
              `${row.code}:${correction.name ?? 'inherited'}`,
            ),
        }
      : {},
  })

  return { controller: table.controller, wrapper }
}

describe('HilosI18nNamesTable', () => {
  it('shows the language by its code in capitals with its native name', () => {
    const { wrapper } = mountNames()
    const row = wrapper.find('tr[data-id="hilos-table-row-en"]')

    expect(row.find('.text-uppercase').text()).toBe('en')
    expect(row.text()).toContain('English')
  })

  it('says "No name" where nothing is written and leaves a stored empty name empty', () => {
    const { wrapper } = mountNames()

    expect(wrapper.find('tr [data-id="i18n-names-none-fr"]').text()).toBe(
      'No name',
    )
    expect(wrapper.find('[data-id="i18n-names-none-es"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="i18n-names-none-en"]').exists()).toBe(false)
    expect(wrapper.find('tr[data-id="hilos-table-row-en"]').text()).toContain(
      'German',
    )
  })

  it('offers the chevron only on a row with corrections', () => {
    const { wrapper } = mountNames()

    expect(wrapper.find('tr [data-id="hilos-table-expand-en"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-expand-es"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-expand-fr"]').exists()).toBe(
      false,
    )
  })

  it('lists the corrections across the panel, an inherited one as "as in" the native name', async () => {
    const { controller, wrapper } = mountNames()
    controller.expandRow('en', true)
    await nextTick()

    const panel = wrapper.find('tr[data-id="hilos-table-row-detail-en"]')
    expect(panel.find('dl > div').classes()).toContain('col-md-12')
    const british = panel.find('[data-id="i18n-names-correction-en-GB"]')
    expect(british.text()).toContain('United Kingdom')
    expect(british.text()).toContain('German (UK)')
    const american = panel.find('[data-id="i18n-names-correction-en-US"]')
    // A country with no name in the default language is labelled by its code.
    expect(american.text()).toContain('US')
    expect(panel.find('[data-id="i18n-names-inherit-en-US"]').text()).toBe(
      'as in “English”',
    )
    expect(panel.find('[data-id="i18n-names-inherit-en-GB"]').exists()).toBe(
      false,
    )
  })
})

describe('optional names actions', () => {
  it('leaves optional action slots empty in both country table and cards', async () => {
    const { wrapper, controller } = mountNames(HILOS_I18N_COUNTRY_NAMES)
    controller.expandRow('en', true)
    await nextTick()
    expect(
      wrapper.get('tr[data-id="hilos-table-row-en"] .d-inline-flex').text(),
    ).toBe('')
    expect(
      wrapper
        .findAll('button')
        .every((button) =>
          button.attributes('data-id')?.startsWith('hilos-table-expand-'),
        ),
    ).toBe(true)
    expect(wrapper.text()).toContain('Actions')
    wrapper.unmount()
  })
})

describe('shared names action slots', () => {
  it.each([HILOS_I18N_LANGUAGE_NAMES, HILOS_I18N_COUNTRY_NAMES])(
    'passes the row and correction through both projections for $page',
    async (target) => {
      const { wrapper, controller } = mountNames(target, true)
      controller.expandRow('en', true)
      await nextTick()
      for (const surface of ['table', '[data-id="hilos-table-cards"]']) {
        expect(wrapper.get(`${surface} [data-id="test-base-en"]`).text()).toBe(
          'German',
        )
        expect(wrapper.get(`${surface} [data-id="test-base-es"]`).text()).toBe(
          '',
        )
        expect(wrapper.get(`${surface} [data-id="test-base-fr"]`).text()).toBe(
          'absent',
        )
        expect(
          wrapper.get(`${surface} [data-id="test-correction-en-GB"]`).text(),
        ).toBe('en:German (UK)')
        expect(
          wrapper.get(`${surface} [data-id="test-correction-en-US"]`).text(),
        ).toBe('en:inherited')
      }
      wrapper.unmount()
    },
  )
})
