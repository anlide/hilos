import { describe, expect, it } from 'vitest'
import { type HilosI18nBuiltInLanguage } from '../../../src/admin/i18n/hilosI18nBuiltInCatalog.js'
import { type HilosI18nLanguageContext } from '../../../src/admin/i18n/hilosI18nLanguage.js'
import {
  HILOS_I18N_LANGUAGE_ADD_ACTION,
  createHilosI18nLanguageAdd,
  hilosI18nLanguageCode,
} from '../../../src/admin/i18n/hilosI18nLanguageAdd.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { createSignal } from '../../../src/state/signal.js'

const languages: readonly HilosI18nBuiltInLanguage[] = [
  { code: 'fr', nativeName: 'Français', rtl: false },
  { code: 'ar', nativeName: 'العربية', rtl: true },
]

function harness() {
  const catalog = createSignal<readonly HilosI18nBuiltInLanguage[] | null>(
    languages,
  )
  const calls: Array<{ action: string; payload: unknown }> = []
  const actions = {
    dispatch(action: string, payload: unknown): ActionHandle {
      calls.push({ action, payload })
      return {
        requestId: String(calls.length),
        loading: createSignal(false),
        done: Promise.resolve({}),
      }
    },
  } as ActionLifecycle
  const add = createHilosI18nLanguageAdd(
    { actions } as HilosI18nLanguageContext,
    catalog,
  )
  return { add, catalog, calls }
}

describe('createHilosI18nLanguageAdd', () => {
  it('normalizes codes and recognizes only complete catalog codes', () => {
    const { add } = harness()
    expect(hilosI18nLanguageCode(' FR ')).toBe('fr')
    expect(add.step.get()).toBe('closed')
    add.open()
    for (const code of ['', 'f', 'fra', 'f1', 'éé']) {
      add.setCode(code)
      expect(add.complete.get()).toBe(false)
      expect(add.known.get()).toBeNull()
    }
    add.setCode(' FR ')
    expect(add.form.get().code).toBe('fr')
    expect(add.complete.get()).toBe(true)
    expect(add.known.get()).toEqual(languages[0])
    add.setCode('cy')
    expect(add.complete.get()).toBe(true)
    expect(add.known.get()).toBeNull()
  })

  it('refills name and direction only when the normalized code changes', () => {
    const { add } = harness()
    add.open()
    add.setCode('fr')
    expect(add.form.get()).toEqual({
      code: 'fr',
      nativeName: 'Français',
      rtl: false,
    })
    add.setNativeName('Edited')
    add.setRtl(true)
    add.setCode(' FR ')
    expect(add.form.get().nativeName).toBe('Edited')
    expect(add.form.get().rtl).toBe(true)
    add.setCode('ar')
    expect(add.form.get()).toEqual({
      code: 'ar',
      nativeName: 'العربية',
      rtl: true,
    })
    add.setCode('cy')
    expect(add.form.get()).toEqual({
      code: 'cy',
      nativeName: '',
      rtl: false,
    })
    add.setNativeName('Cymraeg')
    add.setRtl(true)
    add.setCode('c')
    expect(add.form.get()).toEqual({
      code: 'c',
      nativeName: '',
      rtl: false,
    })
  })

  it('requires a complete code, a nonempty trimmed name and a live catalog', async () => {
    const { add, catalog, calls } = harness()
    add.open()
    expect(add.canSubmit.get()).toBe(false)
    add.setCode('cy')
    add.setNativeName('   ')
    expect(add.canSubmit.get()).toBe(false)
    await add.submit(async () => true)
    expect(add.step.get()).toBe('form')
    expect(calls).toHaveLength(0)
    add.setNativeName(' Cymraeg ')
    expect(add.canSubmit.get()).toBe(true)
    catalog.set(null)
    expect(add.canSubmit.get()).toBe(false)
    await add.submit(async () => true)
    expect(calls).toHaveLength(0)
    catalog.set(languages)
    expect(add.canSubmit.get()).toBe(true)
    add.close()
    expect(add.canSubmit.get()).toBe(false)
  })

  it('dispatches a known language directly with edited and trimmed values', async () => {
    const { add, calls } = harness()
    add.open()
    add.setCode('FR')
    add.setNativeName(' French name ')
    add.setRtl(true)
    await add.submit(async () => true)
    expect(calls).toEqual([
      {
        action: HILOS_I18N_LANGUAGE_ADD_ACTION,
        payload: { languageCode: 'fr', nativeName: 'French name', rtl: true },
      },
    ])
    expect(add.step.get()).toBe('closed')
    expect(add.saving.get()).toBe(false)
  })

  it('holds a custom language for confirmation without dispatching', async () => {
    const { add, calls } = harness()
    add.open()
    add.setCode(' CY ')
    add.setNativeName(' Cymraeg ')
    await add.confirm(async () => true)
    expect(calls).toHaveLength(0)
    await add.submit(async () => true)
    expect(add.step.get()).toBe('confirm')
    expect(add.form.get()).toEqual({
      code: 'cy',
      nativeName: ' Cymraeg ',
      rtl: false,
    })
    expect(calls).toHaveLength(0)
    await add.submit(async () => true)
    expect(calls).toHaveLength(0)
    await add.confirm(async () => true)
    expect(calls).toEqual([
      {
        action: HILOS_I18N_LANGUAGE_ADD_ACTION,
        payload: { languageCode: 'cy', nativeName: 'Cymraeg', rtl: false },
      },
    ])
    expect(add.step.get()).toBe('closed')
  })

  it.each(['fr', 'cy'])(
    'keeps the active step on refusal for %s',
    async (code) => {
      const { add, calls } = harness()
      add.open()
      add.setCode(code)
      add.setNativeName('Name')
      await add.submit(async () => false)
      if (code === 'cy') await add.confirm(async () => false)
      expect(calls).toHaveLength(1)
      expect(add.step.get()).toBe(code === 'fr' ? 'form' : 'confirm')
      expect(add.form.get().nativeName).toBe('Name')
      expect(add.canSubmit.get()).toBe(true)
    },
  )

  it.each(['fr', 'cy'])(
    'locks closing and repeat sends while saving %s, and stays fit to submit',
    async (code) => {
      const { add, catalog, calls } = harness()
      add.open()
      add.setCode(code)
      add.setNativeName('Name')
      if (code === 'cy') await add.submit(async () => true)
      let settle: ((value: boolean) => void) | undefined
      const run = () =>
        new Promise<boolean>((resolve) => {
          settle = resolve
        })
      const pending = code === 'fr' ? add.submit(run) : add.confirm(run)
      expect(add.saving.get()).toBe(true)
      expect(add.canSubmit.get()).toBe(false)
      expect(add.submitAvailable.get()).toBe(true)
      add.close()
      add.open()
      await add.submit(run)
      await add.confirm(run)
      expect(calls).toHaveLength(1)
      expect(add.step.get()).toBe(code === 'fr' ? 'form' : 'confirm')
      catalog.set(null)
      expect(add.submitAvailable.get()).toBe(false)
      catalog.set(languages)
      expect(add.submitAvailable.get()).toBe(true)
      settle?.(true)
      await pending
      expect(add.step.get()).toBe('closed')
      expect(add.saving.get()).toBe(false)
      expect(add.submitAvailable.get()).toBe(false)
    },
  )

  it('discards the draft on cancel and opens an empty form again', async () => {
    const { add } = harness()
    add.open()
    add.setCode('cy')
    add.setNativeName('Cymraeg')
    add.setRtl(true)
    await add.submit(async () => true)
    add.close()
    expect(add.form.get()).toEqual({ code: '', nativeName: '', rtl: false })
    add.open()
    expect(add.step.get()).toBe('form')
    expect(add.form.get()).toEqual({ code: '', nativeName: '', rtl: false })
    add.dispose()
    expect(add.step.get()).toBe('closed')
  })
})
