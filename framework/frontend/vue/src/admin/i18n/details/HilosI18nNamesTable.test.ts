import { mount } from '@vue/test-utils'
import {
  createHilosI18nNamesTable,
  createSignal,
  HILOS_I18N_LANGUAGE_NAMES,
  ScopeManager,
  type HilosConnection,
  type HilosI18nNameRow,
  type TableRow,
  type TableViewportController,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

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
function mountNames(): {
  controller: TableViewportController<HilosI18nNameRow>
  wrapper: ReturnType<typeof mount>
} {
  const table = createHilosI18nNamesTable(
    {
      connection: {} as HilosConnection,
      scopes: new ScopeManager(),
    },
    HILOS_I18N_LANGUAGE_NAMES,
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
