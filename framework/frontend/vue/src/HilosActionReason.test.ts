// @vitest-environment jsdom
// Match HilosModal's environment so native disabled and focus return are real.
import {
  type Hideable,
  HIDDEN_VALUE,
  registerFocusReturnFallback,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosActionReason from './HilosActionReason.vue'

const reason = 'Cannot delete: the language has locales.'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

/** A required element of the real teleported explanation. */
function element(id: string): HTMLElement {
  const node = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  expect(node).not.toBeNull()
  return node!
}

/** Mount the primitive in the document so its native opener can take focus. */
function mountReason(source: Hideable<string | null> = reason) {
  return mount(HilosActionReason, {
    props: { reason: source },
    attachTo: document.body,
  })
}

/** Focus and activate the real native button, then await modal placement. */
async function openReason(): Promise<void> {
  const opener = element('hilos-action-reason')
  opener.focus()
  opener.click()
  await flushPromises()
}

describe('HilosActionReason', () => {
  it('renders a named native opener and no wrapper, with the reason as its title', () => {
    const view = mountReason()
    const opener = view.get('[data-id="hilos-action-reason"]')
    expect(opener.element).toBeInstanceOf(HTMLButtonElement)
    expect(opener.element.parentElement?.hasAttribute('data-v-app')).toBe(true)
    expect(opener.attributes('type')).toBe('button')
    expect(opener.classes()).toEqual(['btn', 'btn-sm', 'btn-outline-secondary'])
    expect(opener.attributes('aria-label')).toBe(
      'Why this action is unavailable',
    )
    expect(opener.attributes('title')).toBe(reason)
    expect(opener.get('i').classes()).toContain('bi-question-circle')
    expect(opener.get('i').attributes('aria-hidden')).toBe('true')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('renders no opener or modal for an initially null reason', () => {
    const view = mountReason(null)
    expect(view.find('button').exists()).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('uses a custom label and title, and puts focus on the named dialog', async () => {
    const view = mountReason()
    await view.setProps({ label: 'Explain deletion', title: 'Cannot delete' })
    expect(element('hilos-action-reason').getAttribute('aria-label')).toBe(
      'Explain deletion',
    )
    await openReason()
    const dialog = element('modal')
    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-label')).toBe('Cannot delete')
    expect(document.activeElement).toBe(dialog)
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    expect(dialog.querySelectorAll('button')).toHaveLength(2)
    expect(element('hilos-action-reason-close').textContent?.trim()).toBe(
      'Close',
    )
    expect(dialog.querySelector('input, select, textarea')).toBeNull()
  })

  it.each(['Close', 'Escape', 'backdrop', 'header'] as const)(
    'closes through %s and returns focus to the opener',
    async (gesture) => {
      mountReason()
      const opener = element('hilos-action-reason')
      await openReason()
      expect(element('modal').getAttribute('aria-label')).toBe(
        'Action unavailable',
      )
      if (gesture === 'Close') element('hilos-action-reason-close').click()
      else if (gesture === 'header') element('modal-close').click()
      else if (gesture === 'backdrop') element('modal').click()
      else
        element('modal').dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(document.body.classList.contains('modal-open')).toBe(false)
      expect(document.activeElement).toBe(opener)
    },
  )

  it('cycles Tab inside the real modal without activating anything on Enter', async () => {
    mountReason()
    await openReason()
    const dialog = element('modal')
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    )
    expect(document.activeElement).toBe(dialog)
    const close = element('hilos-action-reason-close')
    close.focus()
    close.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
    )
    expect(document.activeElement).toBe(element('modal-close'))
    element('modal-close').dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: true,
        bubbles: true,
      }),
    )
    expect(document.activeElement).toBe(close)
  })

  it('keeps the read reason through changes and null, then reads the latest on reopening', async () => {
    const view = mountReason()
    await openReason()
    const dialog = element('modal')
    await view.setProps({ reason: 'Cannot delete: the language has names.' })
    expect(element('hilos-action-reason').getAttribute('title')).toBe(
      'Cannot delete: the language has names.',
    )
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    await view.setProps({ reason: null })
    expect(view.find('button').exists()).toBe(false)
    expect(element('modal')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    element('hilos-action-reason-close').click()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    await view.setProps({
      reason: 'Cannot delete: this is the default language.',
    })
    await openReason()
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(
      'Cannot delete: this is the default language.',
    )
  })

  it('opens a hidden reason on the standard mark, with no title and no leaked words', async () => {
    const view = mountReason(HIDDEN_VALUE)
    const opener = element('hilos-action-reason')
    expect(opener.hasAttribute('title')).toBe(false)
    expect(opener.getAttribute('aria-label')).toBe(
      'Why this action is unavailable',
    )
    await openReason()
    const body = element('hilos-action-reason-body')
    expect(body.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(body.textContent?.trim()).toBe('Hidden')
    expect(document.body.innerHTML).not.toContain('_hidden')
    expect(document.body.innerHTML).not.toContain('[object')
    expect(element('modal').getAttribute('aria-label')).toBe(
      'Action unavailable',
    )
    element('hilos-action-reason-close').click()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.activeElement).toBe(opener)
    view.unmount()
  })

  it('keeps a hidden snapshot when the reason turns open or null, and reads the latest on reopening', async () => {
    const view = mountReason(HIDDEN_VALUE)
    await openReason()
    await view.setProps({ reason })
    expect(element('hilos-action-reason').getAttribute('title')).toBe(reason)
    expect(
      element('hilos-action-reason-body').querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    await view.setProps({ reason: null })
    expect(view.find('button').exists()).toBe(false)
    expect(
      element('hilos-action-reason-body').querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    element('hilos-action-reason-close').click()
    await flushPromises()
    await view.setProps({ reason })
    await openReason()
    const body = element('hilos-action-reason-body')
    expect(body.querySelector('[data-id="hilos-hidden"]')).toBeNull()
    expect(body.textContent?.trim()).toBe(reason)
    element('hilos-action-reason-close').click()
    await flushPromises()
    await view.setProps({ reason: HIDDEN_VALUE })
    expect(element('hilos-action-reason').hasAttribute('title')).toBe(false)
  })

  it('prints an open reason as text, not markup', async () => {
    mountReason('<b data-id="injected">no</b>')
    await openReason()
    expect(document.querySelector('[data-id="injected"]')).toBeNull()
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(
      '<b data-id="injected">no</b>',
    )
  })

  it.each(['removed', 'disabled'] as const)(
    'uses the nearest registered fallback when the opener is %s',
    async (state) => {
      const outer = document.createElement('section')
      const inner = document.createElement('div')
      outer.tabIndex = inner.tabIndex = -1
      outer.append(inner)
      document.body.append(outer)
      const releaseOuter = registerFocusReturnFallback(outer, () => outer)
      const releaseInner = registerFocusReturnFallback(inner, () => inner)
      const view = mount(HilosActionReason, {
        props: { reason },
        attachTo: inner,
      })
      try {
        await openReason()
        if (state === 'removed') await view.setProps({ reason: null })
        else
          (element('hilos-action-reason') as HTMLButtonElement).disabled = true
        element('hilos-action-reason-close').click()
        await flushPromises()
        expect(document.activeElement).toBe(inner)
        expect(document.activeElement?.isConnected).toBe(true)
      } finally {
        releaseInner()
        releaseOuter()
      }
    },
  )

  it('releases the real modal and scroll lock when unmounted while open', async () => {
    const view = mountReason()
    await openReason()
    expect(document.body.classList.contains('modal-open')).toBe(true)
    view.unmount()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement?.isConnected).toBe(true)
  })
})
