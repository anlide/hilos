// @vitest-environment jsdom
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { type HilosI18nLanguageRow } from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

import HilosI18nLanguageRowActions from './HilosI18nLanguageRowActions.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

// The server's sentences; the client copy words the same codes differently,
// so a row that showed the copy would fail these expectations.
const LOCALES = 'This language cannot be deleted because it has locales.'
const NAMES =
  'This language cannot be deleted because names written by a person reference it.'

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
  deleteDisabledReason: LOCALES,
}

/** A changed verdict as the server sends it: the code and its sentence. */
const named = {
  deleteReason: 'names',
  deleteDisabledReason: NAMES,
} as const
const free = {
  canDelete: true,
  deleteReason: null,
  deleteDisabledReason: null,
} as const

describe('HilosI18nLanguageRowActions', () => {
  it('gives table and card copies unique accessible descriptions and one direct button group', () => {
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
      expect(reason.text()).toBe(LOCALES)
      expect(reason.classes()).toEqual(['visually-hidden'])
      expect(reason.attributes('hidden')).toBeUndefined()
      expect(reason.attributes('aria-hidden')).toBeUndefined()
      expect(buttons[index]!.attributes('aria-describedby')).toBe(ids[index])
      expect(buttons[index]!.attributes('aria-label')).toBe(
        'Delete language Español',
      )
      expect((buttons[index]!.element as HTMLButtonElement).disabled).toBe(true)
    })
    const groups = view.findAll('.hilos-button-group')
    expect(groups).toHaveLength(2)
    for (const group of groups) {
      expect(group.classes()).toEqual([
        'hilos-button-group',
        'd-md-inline-flex',
        'gap-1',
      ])
      expect(group.find('.hilos-button-group').exists()).toBe(false)
      expect(
        [...group.element.children].map((child) => [
          child.tagName,
          child.getAttribute('data-id'),
        ]),
      ).toEqual([
        ['BUTTON', 'i18n-languages-edit-es'],
        ['BUTTON', 'hilos-action-reason'],
        ['BUTTON', 'i18n-languages-delete-es'],
        ['SPAN', 'i18n-languages-delete-reason-es'],
      ])
      expect(
        group.get('[data-id="hilos-action-reason"]').attributes('title'),
      ).toBe(LOCALES)
    }
    view.unmount()
  })

  it('opens the existing edit or view control and a free language’s delete window', async () => {
    const view = mount(HilosI18nLanguageRowActions, {
      props: { row: { ...row, ...free } },
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
    expect(view.find('[data-id="hilos-action-reason"]').exists()).toBe(false)
    await view.setProps({ row: { ...row, enabled: true } })
    expect(view.find('[data-id="i18n-languages-edit-es"]').exists()).toBe(false)
    await view.get('[data-id="i18n-languages-view-es"]').trigger('click')
    expect(view.emitted('edit')).toHaveLength(2)
    view.unmount()
  })

  it('keeps an open explanation when the live verdict clears, without emitting an action', async () => {
    const view = mount(HilosI18nLanguageRowActions, {
      props: { row },
      attachTo: document.body,
    })
    const opener = view.get('[data-id="hilos-action-reason"]')
    const button = opener.element as HTMLButtonElement
    button.focus()
    await opener.trigger('click')
    await flushPromises()
    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog).not.toBeNull()
    await view.setProps({ row: { ...row, ...named } })
    expect(view.get('[data-id="i18n-languages-delete-reason-es"]').text()).toBe(
      NAMES,
    )
    await view.setProps({ row: { ...row, ...free } })
    expect(view.find('[data-id="hilos-action-reason"]').exists()).toBe(false)
    expect(
      view.find('[data-id="i18n-languages-delete-reason-es"]').exists(),
    ).toBe(false)
    expect(
      view.get('[data-id="i18n-languages-delete-es"]').attributes('disabled'),
    ).toBeUndefined()
    expect(document.querySelector('[data-id="modal"]')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    expect(
      dialog
        ?.querySelector('[data-id="hilos-action-reason-body"]')
        ?.textContent?.trim(),
    ).toBe(LOCALES)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-reason-close"]',
      )!
      .click()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(view.emitted('edit')).toBeUndefined()
    expect(view.emitted('delete')).toBeUndefined()
    await view.setProps({ row: { ...row, ...named } })
    await view.get('[data-id="hilos-action-reason"]').trigger('click')
    await flushPromises()
    expect(
      document
        .querySelector('[data-id="hilos-action-reason-body"]')
        ?.textContent?.trim(),
    ).toBe(NAMES)
  })

  it('lets a view-mode reader open the explanation without dispatching an edit or delete', async () => {
    const view = mount(HilosI18nLanguageRowActions, {
      props: { row },
      attachTo: document.body,
      global: { provide: { [hilosAdminViewModeKey as symbol]: ref(true) } },
    })
    const opener = view.get('[data-id="hilos-action-reason"]')
    expect((opener.element as HTMLButtonElement).disabled).toBe(false)
    await opener.trigger('click')
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(view.emitted('edit')).toBeUndefined()
    expect(view.emitted('delete')).toBeUndefined()
    expect(
      (
        view.get('[data-id="i18n-languages-delete-es"]')
          .element as HTMLButtonElement
      ).disabled,
    ).toBe(true)
  })
})
