import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type TableViewportController,
} from '@hilos/core/base'
import {
  createHilosI18nCountryNameWindow,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
  type HilosI18nNameRow,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nCountryNameWindow from './HilosI18nCountryNameWindow.vue'

const original: HilosI18nNameRow = {
  inLanguageId: 47,
  nameId: 301,
  rowKey: '47',
  code: 'qw',
  nativeName: 'Writing',
  name: 'Original',
  corrections: [
    {
      localeId: 501,
      nameId: 401,
      countryId: 101,
      localeCode: 'qw-GB',
      countryCode: 'gb',
      countryName: 'UK',
      name: 'Correction',
    },
  ],
}
const originalCard: HilosI18nCountryCard = {
  countryId: 141,
  code: 'qx',
  currencySymbol: '¤',
  currencyCode: 'QXX',
  enabled: false,
  summary: {
    name: 'Subject',
    isOwn: true,
    defaultLocaleId: null,
    defaultLocaleCode: null,
    defaultLocale: 'missing',
    localeCount: 0,
    nameCount: 1,
    locales: [],
    catalogDefaultLocaleCode: null,
    canDelete: false,
    deleteReason: 'names',
  },
}
interface Dispatch {
  name: string
  payload: unknown
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}
const mounted: VueWrapper[] = []
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
})

function harness(
  name: string | null = 'Original',
  localeCode: string | null = null,
  viewer = false,
) {
  const row = {
    ...original,
    nameId: localeCode === null && name === null ? null : 301,
    name: localeCode === null ? name : '',
    corrections: [
      { ...original.corrections[0]!, nameId: name === null ? null : 401, name },
    ],
  }
  const focused = createSignal<HilosI18nNameRow | undefined>(undefined)
  const controller = {
    focusRow: () => {
      focused.set(row)
      return row
    },
    focusedRow: focused,
    releaseFocus: () => focused.set(undefined),
  } as unknown as TableViewportController<HilosI18nNameRow>
  const dispatched: Dispatch[] = []
  const context = {
    actions: {
      dispatch(name: string, payload: unknown): ActionHandle {
        let settle: Dispatch['settle'] = () => {}
        let refuse: Dispatch['refuse'] = () => {}
        const done = new Promise<ActionResult>((resolve, reject) => {
          settle = resolve
          refuse = reject
        })
        dispatched.push({ name, payload, settle, refuse })
        return {
          requestId: String(dispatched.length),
          loading: createSignal(false),
          done,
        }
      },
    } as ActionLifecycle,
  } as HilosI18nCountryContext
  const card = createSignal<HilosI18nCountryCard | null>(originalCard)
  const country = createSignal('qx')
  const window = createHilosI18nCountryNameWindow(
    controller,
    card,
    country,
    context,
  )
  const view = mount(HilosI18nCountryNameWindow, {
    props: { window },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewer) } },
  })
  mounted.push(view)
  window.open('47', localeCode === null ? null : 501)
  return { view, window, focused, dispatched, card, country }
}
function el(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}
function input(): HTMLInputElement {
  return el('i18n-country-name-input') as HTMLInputElement
}
function button(id: 'submit' | 'cancel'): HTMLButtonElement {
  return el(`i18n-country-name-${id}`) as HTMLButtonElement
}
async function fill(value: string): Promise<void> {
  input().value = value
  input().dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}
async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}
function enter(): void {
  input().form!.dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}

describe('country name modal', () => {
  it.each([null, 'qw-GB'])(
    'keeps the draft and blocks a same-text replacement at %s',
    async (localeCode) => {
      const h = harness('Original', localeCode)
      await settled()
      await fill('Keep my draft')
      h.focused.set({
        ...original,
        ...(localeCode === null
          ? { nameId: 999 }
          : {
              corrections: [
                { ...original.corrections[0]!, nameId: 999, name: 'Original' },
              ],
            }),
      })
      await settled()
      expect(input().value).toBe('Keep my draft')
      expect(button('submit').disabled).toBe(true)
      expect(button('submit').textContent).toContain('Deleted')
      await h.window.save(() => Promise.resolve(true))
      expect(h.dispatched).toEqual([])
    },
  )

  it.each([
    [null, null, 'Add name', 'value'],
    ['', null, 'Edit name', 'value'],
    [null, 'qw-GB', 'Add locale correction', 'correction'],
    ['', 'qw-GB', 'Edit locale correction', 'correction'],
  ] as const)(
    'renders %s at %s with its empty-value hint and accessible dialog',
    async (name, localeCode, title, kind) => {
      const h = harness(name, localeCode)
      await settled()
      expect(el('i18n-country-name-title')?.textContent).toContain(
        `${title} · Subject`,
      )
      expect(el('i18n-country-name-window')?.textContent).toContain(
        'Writing (qw)',
      )
      expect(input().value).toBe('')
      expect(input().required).toBe(false)
      expect(input().maxLength).toBe(255)
      expect(
        document.querySelector(`label[for="${input().id}"]`)?.textContent,
      ).toBe('Name')
      const hint = document.getElementById(
        input().getAttribute('aria-describedby')!,
      )
      expect(hint?.textContent).toBe(
        kind === 'value'
          ? 'Empty text is saved as your own value. Delete the name to remove it.'
          : 'Empty text is saved as your own correction. Delete the correction to inherit the base name.',
      )
      const dialog = document.querySelector('[role="dialog"]')!
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(dialog.getAttribute('aria-label')).toBe(`${title} · Subject`)
      expect(document.activeElement).toBe(input())
      expect(button('submit').disabled).toBe(name !== null)
      if (localeCode !== null)
        expect(el('i18n-country-name-window')?.textContent).toContain(
          localeCode,
        )
      h.card.set({
        ...originalCard,
        summary: { ...originalCard.summary, name: 'Changed' },
      })
      await settled()
      expect(el('i18n-country-name-title')?.textContent).toContain('Subject')
    },
  )

  it.each([null, 'qw-GB'])(
    'submits empty add at %s, keeps a duplicate refusal and succeeds with literal text',
    async (localeCode) => {
      const h = harness(null, localeCode)
      await settled()
      enter()
      expect(h.dispatched[0]).toMatchObject({
        name: 'hilos_i18n_country_name_add',
        payload: {
          countryId: 141,
          inLanguageId: 47,
          baseNameId: localeCode === null ? null : 301,
          localeId: localeCode === null ? null : 501,
          name: '',
        },
      })
      h.focused.set(original)
      h.dispatched[0]!.refuse(
        new ActionError(
          'hilos_i18n_country_name_add',
          'fail',
          'A name already exists for this language or locale.',
        ),
      )
      await settled()
      expect(h.window.mode.get()).toBe('add')
      expect(input().value).toBe('')
      expect(el('i18n-country-name-error')?.textContent).toContain(
        'A name already exists',
      )
      await fill('  My value  ')
      button('submit').click()
      expect(h.dispatched[1]).toMatchObject({
        payload: {
          countryId: 141,
          inLanguageId: 47,
          baseNameId: localeCode === null ? null : 301,
          localeId: localeCode === null ? null : 501,
          name: '  My value  ',
        },
      })
      h.dispatched[1]!.settle({ message: 'Country name added.' })
      await settled()
      expect(h.window.opened.get()).toBe(false)
      expect(el('i18n-country-name-window')).toBeNull()
    },
  )

  it.each([null, 'qw-GB'])(
    'uses the same edit guard for Enter and Save at %s',
    async (localeCode) => {
      const h = harness('', localeCode)
      await settled()
      enter()
      expect(h.dispatched).toHaveLength(0)
      expect(h.window.opened.get()).toBe(true)
      await fill('   ')
      enter()
      expect(h.dispatched[0]).toMatchObject({
        name: 'hilos_i18n_country_name_update',
        payload: {
          countryId: 141,
          inLanguageId: 47,
          nameId: localeCode === null ? 301 : 401,
          localeId: localeCode === null ? null : 501,
          name: '   ',
          expectedName: '',
        },
      })
      await settled()
      enter()
      button('submit').click()
      expect(h.dispatched).toHaveLength(1)
      expect(input().disabled).toBe(true)
      expect(button('cancel').disabled).toBe(true)
      input().dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      el('modal-close')?.click()
      document.querySelector<HTMLElement>('.modal')?.click()
      await settled()
      expect(h.window.opened.get()).toBe(true)
      expect(el('modal-confirm-discard')).toBeNull()
      h.dispatched[0]!.settle({})
      await settled()
      expect(h.window.opened.get()).toBe(false)
    },
  )

  it('keeps a tracked failure with details through dirty changes and clears it at a new opening', async () => {
    const h = harness()
    await settled()
    await fill('My draft')
    button('submit').click()
    h.dispatched[0]!.refuse(
      new ActionError(
        'hilos_i18n_country_name_update',
        'fail',
        'Could not save.',
        'DatabaseException',
        'Write was refused.',
      ),
    )
    await settled()
    expect(input().value).toBe('My draft')
    expect(el('i18n-country-name-error')?.textContent).toContain(
      'Could not save.',
    )
    expect(
      el('i18n-country-name-error')?.querySelector('button'),
    ).not.toBeNull()
    el('i18n-country-name-error')?.querySelector('button')?.click()
    await settled()
    expect(document.body.textContent).toContain('Write was refused.')
    // The details dialog is separate; close it before continuing the edit.
    const dialogs = document.querySelectorAll<HTMLElement>('[role="dialog"]')
    dialogs[dialogs.length - 1]
      ?.querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await settled()
    await fill('Changed draft')
    expect(el('i18n-country-name-error')?.textContent).toContain(
      'Could not save.',
    )
    h.window.close()
    h.window.open('47')
    await settled()
    expect(el('i18n-country-name-error')?.textContent).not.toContain(
      'Could not save.',
    )
  })

  it('draws the session conflict choices and sends the accepted incoming value as expectedName', async () => {
    const h = harness()
    await settled()
    await fill('Mine')
    h.focused.set({ ...original, name: 'Theirs' })
    await settled()
    expect(el('i18n-country-name-notice')?.textContent).toContain(
      'Name changed elsewhere to "Theirs".',
    )
    expect(button('submit').disabled).toBe(true)
    enter()
    expect(h.dispatched).toHaveLength(0)
    expect(el('conflict-merge')).toBeNull()
    el('conflict-accept-mine')?.click()
    await settled()
    expect(input().value).toBe('Mine')
    button('submit').click()
    expect(h.dispatched[0]).toMatchObject({
      payload: { name: 'Mine', expectedName: 'Theirs' },
    })
    h.dispatched[0]!.refuse(
      new ActionError('hilos_i18n_country_name_update', 'fail', 'Try again.'),
    )
    await settled()
    h.focused.set({ ...original, name: 'Newer' })
    await settled()
    el('conflict-accept-theirs')?.click()
    await settled()
    expect(input().value).toBe('Newer')
    expect(button('submit').disabled).toBe(true)
    expect(el('i18n-country-name-error')?.textContent).toContain('Try again.')
  })

  it.each([null, 'Original'])(
    'keeps the %s draft copyable after its subject disappears',
    async (name) => {
      const h = harness(name)
      await settled()
      await fill('Copy me')
      h.card.set(null)
      await settled()
      expect(button('submit').textContent).toBe('Deleted')
      expect(button('submit').disabled).toBe(true)
      expect(input().disabled).toBe(false)
      expect(input().value).toBe('Copy me')
      expect(el('i18n-country-name-notice')?.textContent).toContain(
        'Deleted elsewhere',
      )
      enter()
      expect(h.dispatched).toHaveLength(0)
    },
  )

  it.each([null, 'Original'])(
    'lets a viewer change the %s draft and cancel but blocks Enter and Save',
    async (name) => {
      const h = harness(name, null, true)
      await settled()
      await fill('Viewer draft')
      expect(input().disabled).toBe(false)
      expect(button('submit').disabled).toBe(true)
      expect(button('submit').getAttribute('aria-describedby')).toBeTruthy()
      enter()
      button('submit').click()
      expect(h.dispatched).toHaveLength(0)
      expect(button('cancel').disabled).toBe(false)
      button('cancel').click()
      await settled()
      expect(el('modal-confirm-discard')).not.toBeNull()
      el('modal-confirm-discard')?.click()
      await settled()
      expect(h.window.opened.get()).toBe(false)
    },
  )

  it.each([null, 'Original'])(
    'ignores a late failure from the previous %s opening after a route change',
    async (name) => {
      const h = harness(name)
      await settled()
      await fill('Old draft')
      button('submit').click()
      h.country.set('qz')
      h.card.set({ ...originalCard, countryId: 150, code: 'qz' })
      await settled()
      h.window.open('47')
      await settled()
      await fill('New draft')
      button('submit').click()
      expect(h.dispatched).toHaveLength(2)
      h.dispatched[0]!.refuse(new ActionError('old', 'fail', 'Old failure'))
      await settled()
      expect(h.window.opened.get()).toBe(true)
      expect(input().value).toBe('New draft')
      expect(button('cancel').disabled).toBe(true)
      expect(el('i18n-country-name-error')?.textContent).not.toContain(
        'Old failure',
      )
      h.dispatched[1]!.settle({})
      await settled()
      expect(h.window.opened.get()).toBe(false)
    },
  )

  it('confirms dirty Escape and returns focus to the opening control', async () => {
    const trigger = document.createElement('button')
    document.body.append(trigger)
    trigger.focus()
    const h = harness()
    await settled()
    await fill('Dirty')
    input().dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settled()
    expect(h.window.opened.get()).toBe(true)
    expect(el('modal-confirm-discard')).not.toBeNull()
    el('modal-confirm-discard')?.click()
    await settled()
    expect(h.window.opened.get()).toBe(false)
    expect(document.activeElement).toBe(trigger)
    trigger.remove()
  })
})

describe('country name tracked refusal states', () => {
  it.each(['timeout', 'disconnected'] as const)(
    'keeps the draft after %s and clears the old error when retrying',
    async (outcome) => {
      const h = harness()
      await settled()
      expect(el('hilos-action-error-idle')).not.toBeNull()
      expect(el('i18n-country-name-notice-slot')).not.toBeNull()
      await fill('Retained draft')
      button('submit').click()
      h.dispatched[0]!.refuse(new ActionError('write', outcome, 'No reply'))
      await settled()
      expect(h.window.opened.get()).toBe(true)
      expect(input().value).toBe('Retained draft')
      expect(button('submit').disabled).toBe(false)
      expect(el('hilos-action-error')).not.toBeNull()
      button('submit').click()
      await settled()
      expect(el('hilos-action-error')).toBeNull()
      expect(h.dispatched).toHaveLength(2)
      h.dispatched[1]!.settle({})
      await settled()
      expect(h.window.opened.get()).toBe(false)
    },
  )
})
