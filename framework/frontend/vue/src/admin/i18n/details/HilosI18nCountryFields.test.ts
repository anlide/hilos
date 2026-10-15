import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import HilosI18nCountryFields from './HilosI18nCountryFields.vue'

enableAutoUnmount(afterEach)
const values = {
  idPrefix: 'country',
  countryId: 140,
  code: 'ie',
  currencySymbol: '€',
  currencyCode: 'EUR',
  defaultLocaleId: 11140,
  defaultLocaleCode: 'en-IE',
  localeOptions: [
    { value: '11140', label: 'en-IE — switched off' },
    { value: '', label: 'Not chosen' },
  ],
  localeHint: 'Suggested by the built-in catalog — save to keep it.',
  describedBy: 'country-plate',
}

describe('HilosI18nCountryFields', () => {
  it.each(['add', 'edit', 'view'] as const)(
    'renders %s with labeled fields and visible descriptions',
    (mode) => {
      const view = mount(HilosI18nCountryFields, { props: { ...values, mode } })
      const controls = view.findAll<HTMLInputElement | HTMLSelectElement>(
        'input, select',
      )
      expect(controls).toHaveLength(4)
      expect(controls.map((control) => control.element.disabled)).toEqual(
        mode === 'add'
          ? [false, false, false, true]
          : mode === 'edit'
            ? [true, false, false, false]
            : [true, true, true, true],
      )
      for (const control of controls) {
        expect(
          view.get(`label[for="${control.attributes('id')}"]`).text(),
        ).not.toBe('')
        expect(control.attributes('data-id')).toBe(control.attributes('id'))
        const description = control.attributes('aria-describedby')
        if (mode === 'view') expect(description).toBe('country-plate')
        else expect(view.get(`#${description}`).text()).not.toBe('')
        expect(control.attributes('title')).toBeUndefined()
      }
      if (mode === 'view') {
        expect(view.findAll('.form-text')).toHaveLength(0)
        expect(view.find('[data-autofocus]').exists()).toBe(false)
      } else {
        expect(view.get('[data-autofocus]').attributes('data-id')).toBe(
          mode === 'add' ? 'country-code' : 'country-currency-symbol',
        )
        expect(view.get('[data-id="country-code-hint"]').text()).toBe(
          mode === 'add'
            ? 'ISO 3166-1 alpha-2.'
            : 'The country code never changes: its URL and the external catalog use it.',
        )
      }
      if (mode === 'add')
        expect(
          view.get('[data-id="country-default-locale-hint"]').text(),
        ).toContain('A new country has no locales yet')
      if (mode === 'edit')
        expect(view.get('[data-id="country-default-locale-hint"]').text()).toBe(
          values.localeHint,
        )
    },
  )

  it('emits each editable input, including the creation code', async () => {
    const view = mount(HilosI18nCountryFields, {
      props: { ...values, mode: 'add' },
    })
    await view.get('[data-id="country-code"]').setValue('gg')
    await view.get('[data-id="country-currency-symbol"]').setValue('£')
    await view.get('[data-id="country-currency-code"]').setValue('GBP')
    expect(view.emitted('update:code')).toEqual([['gg']])
    expect(view.emitted('update:currencySymbol')).toEqual([['£']])
    expect(view.emitted('update:currencyCode')).toEqual([['GBP']])
    await view.setProps({ mode: 'edit' })
    await view.get('select').setValue('')
    expect(view.emitted('update:defaultLocaleId')).toEqual([[null]])
  })
})
