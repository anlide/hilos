import { mount } from '@vue/test-utils'
import { type HilosI18nLanguageRow } from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

import HilosI18nLanguageRowActions from './HilosI18nLanguageRowActions.vue'

const row: HilosI18nLanguageRow = {
  languageId: 15,
  rowKey: '15',
  code: 'es',
  nativeName: 'Español',
  rtl: false,
  enabled: false,
  isDefault: false,
  isOwn: false,
  canDelete: false,
  deleteReason: 'locales',
}

describe('HilosI18nLanguageRowActions', () => {
  it('gives the table and card copies distinct description ids with the same visible explanation', () => {
    const view = mount(
      defineComponent({
        setup: () => () =>
          h('div', [
            h(HilosI18nLanguageRowActions, { row }),
            h(HilosI18nLanguageRowActions, { row }),
          ]),
      }),
    )
    const reasons = view.findAll('[data-id="i18n-languages-delete-reason-es"]')
    const buttons = view.findAll('[data-id="i18n-languages-delete-es"]')
    expect(reasons).toHaveLength(2)
    const ids = reasons.map((reason) => reason.attributes('id'))
    expect(new Set(ids).size).toBe(2)
    reasons.forEach((reason, index) => {
      expect(reason.text()).toBe('Cannot delete: the language has locales.')
      expect(buttons[index]!.attributes('aria-describedby')).toBe(ids[index])
      expect(buttons[index]!.attributes('aria-label')).toBe(
        'Delete language Español',
      )
      expect((buttons[index]!.element as HTMLButtonElement).disabled).toBe(true)
    })
    view.unmount()
  })

  it('opens the existing edit or view control and a free language’s delete window', async () => {
    const view = mount(HilosI18nLanguageRowActions, {
      props: { row: { ...row, canDelete: true, deleteReason: null } },
    })
    await view.get('[data-id="i18n-languages-edit-es"]').trigger('click')
    expect(view.emitted('edit')).toHaveLength(1)
    await view.get('[data-id="i18n-languages-delete-es"]').trigger('click')
    expect(view.emitted('delete')).toHaveLength(1)
    expect(
      view
        .get('[data-id="i18n-languages-delete-es"]')
        .attributes('aria-describedby'),
    ).toBeUndefined()
    expect(
      view.find('[data-id="i18n-languages-delete-reason-es"]').exists(),
    ).toBe(false)
    await view.setProps({ row: { ...row, enabled: true } })
    expect(view.find('[data-id="i18n-languages-edit-es"]').exists()).toBe(false)
    await view.get('[data-id="i18n-languages-view-es"]').trigger('click')
    expect(view.emitted('edit')).toHaveLength(2)
    view.unmount()
  })
})
