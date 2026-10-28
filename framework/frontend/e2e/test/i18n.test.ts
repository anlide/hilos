// @vitest-environment happy-dom
import type { Expect, Locator, Page } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cancelI18nName, expectCountryDeleteDescription } from '../i18n.js'

const once = {
  poll: (read: () => Promise<unknown>) => ({
    toEqual: async (expected: unknown) =>
      expect(await read()).toEqual(expected),
  }),
} as unknown as Expect

afterEach(() => document.body.replaceChildren())

/** Only layout visibility is supplied; the helper reads real DOM relationships. */
function browser(markup: string): Page {
  document.body.innerHTML = markup
  for (const node of document.querySelectorAll('span')) {
    Object.defineProperty(node, 'checkVisibility', {
      value: () => !node.closest('[hidden]'),
    })
  }
  return {
    getByTestId: (id: string) => ({
      filter: () => ({
        evaluate: async (
          read: (control: Element, code: string) => unknown,
          code: string,
        ) => {
          const controls = [...document.querySelectorAll('[data-id]')].filter(
            (node) =>
              node.getAttribute('data-id') === id && !node.closest('[hidden]'),
          )
          expect(controls).toHaveLength(1)
          return read(controls[0]!, code)
        },
      }),
    }),
  } as unknown as Page
}

const button =
  '<button data-id="i18n-countries-delete-qz" aria-describedby="current"></button>'
const reason =
  '<span data-id="i18n-countries-delete-reason-qz" id="current">Blocked</span>'

describe('canceling an i18n editor', () => {
  it.each(['clean', 'dirty'] as const)(
    'settles a %s editor whose next render happens after Cancel returns',
    async (draft) => {
      document.body.innerHTML =
        '<div data-id="i18n-country-name-window">' +
        '<button data-id="i18n-country-name-cancel">Cancel</button></div>'
      const nodes = (id: string) =>
        [...document.querySelectorAll<HTMLElement>('[data-id]')].filter(
          (node) => node.dataset.id === id,
        )
      let canceled!: () => void
      const cancelReturned = new Promise<void>((resolve) => {
        canceled = resolve
      })
      let confirmed!: () => void
      const discardClicked = new Promise<void>((resolve) => {
        confirmed = resolve
      })
      const cancel = vi.fn(canceled)
      const discard = vi.fn(confirmed)
      nodes('i18n-country-name-cancel')[0]!.onclick = cancel
      let observedPendingDiscard = false
      const page = {
        getByTestId: (id: string) => ({
          count: async () => nodes(id).length,
          isVisible: async () => {
            const visible = nodes(id).length > 0
            if (id === 'modal-confirm-discard' && !visible)
              observedPendingDiscard = true
            return visible
          },
          scrollIntoViewIfNeeded: async () => {},
          focus: async () => nodes(id)[0]!.focus(),
          click: async () => nodes(id)[0]!.click(),
        }),
      } as unknown as Page
      const check = Object.assign(
        (value: Locator | (() => Promise<void>)) => ({
          toPass: () => (value as () => Promise<void>)(),
          toBeVisible: async () =>
            expect(await (value as Locator).isVisible()).toBe(true),
          toBeEnabled: async () => {},
          toHaveCount: (count: number) =>
            expect.poll(() => (value as Locator).count()).toBe(count),
        }),
        { poll: expect.poll },
      ) as unknown as Expect

      let settled = false
      const closing = cancelI18nName(check, page, 'country').then(() => {
        settled = true
      })
      await cancelReturned
      await expect.poll(() => observedPendingDiscard).toBe(true)
      // The UI has acknowledged Cancel, but its next render is still held.
      expect(nodes('modal-confirm-discard')).toHaveLength(0)
      expect(nodes('i18n-country-name-window')).toHaveLength(1)
      expect(settled).toBe(false)
      if (draft === 'dirty') {
        const prompt = document.createElement('button')
        prompt.dataset.id = 'modal-confirm-discard'
        prompt.onclick = discard
        document.body.append(prompt)
        await Promise.race([discardClicked, closing])
        // Confirmation also returns before the editor's closing render.
        expect(settled).toBe(false)
        expect(nodes('i18n-country-name-window')).toHaveLength(1)
        prompt.remove()
      }
      nodes('i18n-country-name-window')[0]!.remove()
      await closing

      expect(settled).toBe(true)
      expect(cancel).toHaveBeenCalledTimes(1)
      expect(discard).toHaveBeenCalledTimes(draft === 'dirty' ? 1 : 0)
    },
  )
})

describe('country deletion description oracle', () => {
  it('checks the visible projection beside a separately identified hidden copy', async () => {
    const page = browser(
      `<div>${button}${reason}</div>` +
        '<div hidden><button data-id="i18n-countries-delete-qz" aria-describedby="card"></button>' +
        '<span data-id="i18n-countries-delete-reason-qz" id="card">Blocked</span></div>',
    )
    await expectCountryDeleteDescription(once, page, 'qz')
  })

  it.each([
    ['a stale id', `<div>${button.replace('current', 'old')}${reason}</div>`],
    [
      'a missing id',
      `<div>${button}${reason.replace(' id="current"', '')}</div>`,
    ],
    [
      'an empty id and reference',
      `<div>${button.replace('current', '')}${reason.replace('current', '')}</div>`,
    ],
    [
      'another country with the same reason text',
      `<div>${button}${reason.replace('-qz', '-us')}</div>`,
    ],
    [
      'another existing target with the same reason text',
      `<div>${button.replace('current', 'other')}${reason}</div><span id="other">Blocked</span>`,
    ],
    [
      'a duplicate id',
      `<div>${button}${reason}</div><span id="current">Blocked</span>`,
    ],
    ['a hidden reason', `<div>${button}<div hidden>${reason}</div></div>`],
    [
      'an aria-hidden reason',
      `<div>${button}<div aria-hidden="true">${reason}</div></div>`,
    ],
    ['an inert reason', `<div>${button}<div inert>${reason}</div></div>`],
    [
      'the hidden projection instead of the visible reason',
      `<div>${button.replace('current', 'card')}${reason}</div>` +
        '<div hidden><span data-id="i18n-countries-delete-reason-qz" id="card">Blocked</span></div>',
    ],
  ])('rejects %s', async (_name, markup) => {
    const page = browser(markup)
    await expect(
      expectCountryDeleteDescription(once, page, 'qz'),
    ).rejects.toThrow()
  })
})
