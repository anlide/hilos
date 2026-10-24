// @vitest-environment happy-dom
import type { Expect, Page } from '@playwright/test'
import { afterEach, describe, expect, it } from 'vitest'
import { expectCountryDeleteDescription } from '../i18n.js'

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
