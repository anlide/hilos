import { mount, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  HilosPages,
  ScopeManager,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
} from '@hilos/core/base'
import {
  BUILT_IN_LANGUAGES_DATA,
  HILOS_I18N_LANGUAGE_ADD_ACTION,
  createHilosI18nBuiltInLanguages,
  createHilosI18nLanguageAdd,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { hilosAdminViewModeKey } from '../../../hilosAdminViewMode.js'
import HilosI18nLanguageAdd from './HilosI18nLanguageAdd.vue'

interface Dispatch {
  action: string
  payload: unknown
  settle: (result: ActionResult) => void
  refuse: (failure: ActionError) => void
}

const mounted: VueWrapper[] = []

afterEach(() => {
  for (const view of mounted) view.unmount()
  mounted.length = 0
})

function harness(viewMode = false) {
  const dispatched: Dispatch[] = []
  const actions = {
    dispatch(action: string, payload: unknown): ActionHandle {
      let settle: Dispatch['settle'] = () => {}
      let refuse: Dispatch['refuse'] = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      dispatched.push({ action, payload, settle, refuse })
      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  } as ActionLifecycle
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.I18N_LANGUAGES)
  page.data.set(BUILT_IN_LANGUAGES_DATA, [
    { code: 'fr', nativeName: 'Français', rtl: false },
    { code: 'ar', nativeName: 'العربية', rtl: true },
  ])
  const context: HilosI18nLanguageContext = {
    actions,
    scopes,
    connection: {} as HilosConnection,
  }
  const add = createHilosI18nLanguageAdd(
    context,
    createHilosI18nBuiltInLanguages(context),
  )
  const view = mount(HilosI18nLanguageAdd, {
    props: { add, context },
    attachTo: document.body,
    global: { provide: { [hilosAdminViewModeKey as symbol]: ref(viewMode) } },
  })
  mounted.push(view)
  return { add, dispatched }
}

function control(id: string): HTMLButtonElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function field(id: string): HTMLInputElement {
  const target = document.querySelector<HTMLInputElement>(`[data-id="${id}"]`)
  if (target === null) throw new Error(`Missing field ${id}`)
  return target
}

async function input(id: string, value: string): Promise<void> {
  const target = field(id)
  target.value = value
  target.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

async function open(add: ReturnType<typeof createHilosI18nLanguageAdd>) {
  add.open()
  await settled()
}

async function custom(): Promise<void> {
  await input('i18n-language-add-code', ' CY ')
  await input('i18n-language-add-native-name', ' Cymraeg ')
}

describe('HilosI18nLanguageAdd', () => {
  it('focuses the code and shows known and own plates only for complete codes', async () => {
    const h = harness()
    await open(h.add)
    expect(document.activeElement).toBe(field('i18n-language-add-code'))
    expect(
      document.querySelector('[data-id="i18n-language-add-plate"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="i18n-language-add-error"]'),
    ).not.toBeNull()
    for (const id of [
      'i18n-language-add-code',
      'i18n-language-add-native-name',
    ]) {
      const target = field(id)
      expect(document.querySelector(`label[for="${target.id}"]`)).not.toBeNull()
      expect(target.getAttribute('aria-describedby')).toBeTruthy()
    }
    await input('i18n-language-add-code', ' F ')
    expect(
      document.querySelector('[data-id="i18n-language-add-plate"]'),
    ).toBeNull()
    await input('i18n-language-add-code', ' FR ')
    expect(field('i18n-language-add-code').value).toBe('fr')
    expect(field('i18n-language-add-native-name').value).toBe('Français')
    expect(field('i18n-language-add-rtl').checked).toBe(false)
    expect(
      document
        .querySelector('[data-id="i18n-language-add-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('known')
    expect(document.body.textContent).toContain(
      'The system knows this language.',
    )
    expect(document.body.textContent).toContain(
      'Filled in from the built-in catalog — you can change it.',
    )
    expect(control('i18n-language-add-submit')?.disabled).toBe(false)
    await input('i18n-language-add-code', 'cy')
    expect(
      document
        .querySelector('[data-id="i18n-language-add-plate"]')
        ?.getAttribute('data-kind'),
    ).toBe('own')
    expect(document.body.textContent).toContain(
      'This language is not in the built-in catalog.',
    )
    expect(field('i18n-language-add-native-name').value).toBe('')
    expect(control('i18n-language-add-submit')?.disabled).toBe(true)
  })

  it('keeps edits until the code changes and then refills name and direction', async () => {
    const h = harness()
    await open(h.add)
    await input('i18n-language-add-code', 'ar')
    expect(field('i18n-language-add-rtl').checked).toBe(true)
    await input('i18n-language-add-native-name', 'Edited')
    const rtl = field('i18n-language-add-rtl')
    rtl.checked = false
    rtl.dispatchEvent(new Event('change', { bubbles: true }))
    await nextTick()
    expect(h.add.form.get()).toEqual({
      code: 'ar',
      nativeName: 'Edited',
      rtl: false,
    })
    await input('i18n-language-add-code', 'fr')
    expect(field('i18n-language-add-native-name').value).toBe('Français')
    expect(field('i18n-language-add-rtl').checked).toBe(false)
  })

  it('confirms an own code without sending and closes only on server success', async () => {
    const h = harness()
    await open(h.add)
    await custom()
    control('i18n-language-add-submit')?.click()
    await settled()
    expect(h.dispatched).toHaveLength(0)
    expect(control('i18n-language-add-submit')).toBeNull()
    expect(
      document.querySelector('[data-id="i18n-language-confirm-code"]')
        ?.textContent,
    ).toContain('cy')
    expect(document.body.textContent).toContain(
      'The system does not know this language',
    )
    expect(document.body.textContent).toContain(
      'The code cy is not in the built-in catalog of 2 languages.',
    )
    expect(document.body.textContent).toContain('writes left to right')
    expect(
      document.querySelector(
        '[data-id="i18n-language-confirm"] li:nth-child(2)',
      )?.textContent,
    ).toContain(
      'the built-in catalog has no country names in it — you will type them in yourself;',
    )
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal"]'),
    )
    control('i18n-language-confirm-submit')?.click()
    expect(h.dispatched).toHaveLength(1)
    expect(h.dispatched[0]).toMatchObject({
      action: HILOS_I18N_LANGUAGE_ADD_ACTION,
      payload: { languageCode: 'cy', nativeName: 'Cymraeg', rtl: false },
    })
    await nextTick()
    expect(control('i18n-language-confirm-cancel')?.disabled).toBe(true)
    h.dispatched[0]?.settle({
      message: 'Custom language cy added and switched off for now.',
    })
    await settled()
    expect(control('i18n-language-confirm-submit')).toBeNull()
  })

  it.each(['fr', 'cy'])(
    'holds %s during a send and keeps the refusal in its own modal',
    async (code) => {
      const h = harness()
      await open(h.add)
      await input('i18n-language-add-code', code)
      if (code === 'cy') {
        await input('i18n-language-add-native-name', 'Cymraeg')
        control('i18n-language-add-submit')?.click()
        await settled()
      }
      const prefix =
        code === 'fr' ? 'i18n-language-add' : 'i18n-language-confirm'
      control(`${prefix}-submit`)?.click()
      await nextTick()
      expect(h.dispatched).toHaveLength(1)
      expect(control(`${prefix}-cancel`)?.disabled).toBe(true)
      control(`${prefix}-submit`)?.click()
      control(`${prefix}-cancel`)?.click()
      control('modal-close')?.click()
      const modal = document.querySelector('[data-id="modal"]')
      modal?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      modal?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      await nextTick()
      expect(control(`${prefix}-submit`)).not.toBeNull()
      expect(h.dispatched).toHaveLength(1)
      const reason = `Language '${code}' already exists.`
      h.dispatched[0]?.refuse(
        new ActionError(HILOS_I18N_LANGUAGE_ADD_ACTION, 'fail', reason),
      )
      await settled()
      expect(
        document.querySelector(`[data-id="${prefix}-error"]`)?.textContent,
      ).toContain(reason)
      expect(control(`${prefix}-submit`)?.disabled).toBe(false)
      control(`${prefix}-submit`)?.click()
      expect(h.dispatched).toHaveLength(2)
      h.dispatched[1]?.settle({})
      await settled()
      expect(control(`${prefix}-submit`)).toBeNull()
      await open(h.add)
      expect(
        document.querySelector('[data-id="i18n-language-add-error"]')
          ?.textContent,
      ).not.toContain(reason)
    },
  )

  it('cancels confirmation and opens the next form with an empty draft', async () => {
    const h = harness()
    await open(h.add)
    await custom()
    control('i18n-language-add-submit')?.click()
    await settled()
    control('i18n-language-confirm-cancel')?.click()
    await settled()
    expect(control('i18n-language-confirm-submit')).toBeNull()
    await open(h.add)
    expect(field('i18n-language-add-code').value).toBe('')
    expect(field('i18n-language-add-native-name').value).toBe('')
    expect(field('i18n-language-add-rtl').checked).toBe(false)
  })

  it('lets a viewer fill fields and cancel, with Add disabled even on form submit', async () => {
    const h = harness(true)
    await open(h.add)
    await custom()
    expect(field('i18n-language-add-code').disabled).toBe(false)
    expect(field('i18n-language-add-native-name').disabled).toBe(false)
    expect(field('i18n-language-add-rtl').disabled).toBe(false)
    expect(control('i18n-language-add-submit')?.disabled).toBe(true)
    document
      .getElementById('i18n-language-add-form')
      ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await settled()
    expect(control('i18n-language-confirm-submit')).toBeNull()
    expect(h.dispatched).toHaveLength(0)
    expect(control('i18n-language-add-cancel')?.disabled).toBe(false)
    control('i18n-language-add-cancel')?.click()
    await settled()
    expect(control('i18n-language-add-submit')).toBeNull()
  })
})
