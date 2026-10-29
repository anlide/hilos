// @vitest-environment jsdom
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { type HilosI18nCountryRow } from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'

import HilosI18nCountryRowActions from './HilosI18nCountryRowActions.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

// The server's sentences; the client copy words the same codes differently,
// so a row that showed the copy would fail these expectations.
const LOCALES = 'This country cannot be deleted because locales refer to it.'
const NAMES = 'This country cannot be deleted because names refer to it.'

const row: HilosI18nCountryRow = {
  rowKey: '141',
  countryId: 141,
  code: 'qx',
  name: 'Qx land',
  currencySymbol: '¤',
  currencyCode: 'XQX',
  defaultLocaleId: null,
  defaultLocaleCode: null,
  enabled: true,
  isOwn: true,
  locales: [],
  catalogDefaultLocaleCode: null,
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

/** The modal standing on the page, or null. */
function modal(): HTMLElement | null {
  return document.querySelector('[data-id="modal"]')
}

describe('HilosI18nCountryRowActions', () => {
  it('gives table and card copies a natively disabled bin with a unique hidden description', () => {
    const view = mount(
      defineComponent({
        setup: () => () =>
          h('div', [
            h(HilosI18nCountryRowActions, { row }),
            h(HilosI18nCountryRowActions, { row }),
          ]),
      }),
    )
    const reasons = view.findAll('[data-id="i18n-countries-delete-reason-qx"]')
    const buttons = view.findAll('[data-id="i18n-countries-delete-qx"]')
    const openers = view.findAll('[data-id="hilos-action-reason"]')
    expect(reasons).toHaveLength(2)
    expect(openers).toHaveLength(2)
    const ids = reasons.map((reason) => reason.attributes('id'))
    expect(new Set(ids).size).toBe(2)
    reasons.forEach((reason, index) => {
      expect(reason.text()).toBe(LOCALES)
      expect(reason.classes()).toEqual(['visually-hidden'])
      expect(reason.attributes('aria-hidden')).toBeUndefined()
      const button = buttons[index]!
      expect(button.attributes('aria-describedby')).toBe(ids[index])
      expect(button.attributes('aria-label')).toBe('Delete country Qx land')
      expect((button.element as HTMLButtonElement).disabled).toBe(true)
      expect(button.attributes('aria-disabled')).toBeUndefined()
      const opener = openers[index]!
      expect((opener.element as HTMLButtonElement).disabled).toBe(false)
      expect(opener.attributes('aria-label')).toBe(
        'Why this action is unavailable',
      )
      expect(opener.attributes('title')).toBe(LOCALES)
    })
    // The ? stands left of the bin, and the reason is no visible line of its own.
    expect(
      [...view.element.children]
        .slice(0, 3)
        .map((child) => child.getAttribute('data-id')),
    ).toEqual([
      'hilos-action-reason',
      'i18n-countries-delete-qx',
      'i18n-countries-delete-reason-qx',
    ])
    view.unmount()
  })

  it('opens a free country’s delete window with no explanation beside it', async () => {
    const view = mount(HilosI18nCountryRowActions, {
      props: { row: { ...row, ...free } },
    })
    const button = view.get('[data-id="i18n-countries-delete-qx"]')
    expect((button.element as HTMLButtonElement).disabled).toBe(false)
    expect(button.attributes('aria-describedby')).toBeUndefined()
    expect(
      view.find('[data-id="i18n-countries-delete-reason-qx"]').exists(),
    ).toBe(false)
    expect(view.find('[data-id="hilos-action-reason"]').exists()).toBe(false)
    await button.trigger('click')
    expect(view.emitted('delete')).toHaveLength(1)
    view.unmount()
  })

  it('asks for nothing when a blocked bin is clicked', async () => {
    const view = mount(HilosI18nCountryRowActions, { props: { row } })
    view
      .get('[data-id="i18n-countries-delete-qx"]')
      .element.dispatchEvent(new MouseEvent('click'))
    await flushPromises()
    expect(view.emitted('delete')).toBeUndefined()
    view.unmount()
  })

  it('opens the server sentence from the keyboard and keeps it when the verdict clears', async () => {
    const view = mount(HilosI18nCountryRowActions, {
      props: { row },
      attachTo: document.body,
    })
    const opener = view.get('[data-id="hilos-action-reason"]')
    const button = opener.element as HTMLButtonElement
    button.focus()
    expect(document.activeElement).toBe(button)
    await opener.trigger('click')
    await flushPromises()
    const dialog = modal()
    expect(dialog).not.toBeNull()
    expect(document.activeElement).toBe(dialog)
    expect(dialog?.getAttribute('role')).toBe('dialog')
    expect(
      dialog
        ?.querySelector('[data-id="hilos-action-reason-body"]')
        ?.textContent?.trim(),
    ).toBe(LOCALES)

    await view.setProps({ row: { ...row, ...named } })
    expect(view.get('[data-id="i18n-countries-delete-reason-qx"]').text()).toBe(
      NAMES,
    )
    await view.setProps({ row: { ...row, ...free } })
    expect(view.find('[data-id="hilos-action-reason"]').exists()).toBe(false)
    expect(
      view.find('[data-id="i18n-countries-delete-reason-qx"]').exists(),
    ).toBe(false)
    expect(
      (
        view.get('[data-id="i18n-countries-delete-qx"]')
          .element as HTMLButtonElement
      ).disabled,
    ).toBe(false)
    // The explanation the reader opened stays with them, words and all.
    expect(modal()).toBe(dialog)
    expect(
      dialog
        ?.querySelector('[data-id="hilos-action-reason-body"]')
        ?.textContent?.trim(),
    ).toBe(LOCALES)
    dialog!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await flushPromises()
    expect(modal()).toBeNull()
    expect(view.emitted('delete')).toBeUndefined()

    await view.setProps({ row: { ...row, ...named } })
    const again = view.get('[data-id="hilos-action-reason"]')
    ;(again.element as HTMLButtonElement).focus()
    await again.trigger('click')
    await flushPromises()
    expect(
      document
        .querySelector('[data-id="hilos-action-reason-body"]')
        ?.textContent?.trim(),
    ).toBe(NAMES)
    modal()!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await flushPromises()
    expect(modal()).toBeNull()
    expect(document.activeElement).toBe(again.element)
  })

  it('lets a view-mode reader open the explanation without asking to delete', async () => {
    const view = mount(HilosI18nCountryRowActions, {
      props: { row },
      attachTo: document.body,
      global: { provide: { [hilosAdminViewModeKey as symbol]: ref(true) } },
    })
    const opener = view.get('[data-id="hilos-action-reason"]')
    expect((opener.element as HTMLButtonElement).disabled).toBe(false)
    await opener.trigger('click')
    await flushPromises()
    expect(modal()).not.toBeNull()
    expect(view.emitted('delete')).toBeUndefined()
    expect(
      (
        view.get('[data-id="i18n-countries-delete-qx"]')
          .element as HTMLButtonElement
      ).disabled,
    ).toBe(true)
  })
})
