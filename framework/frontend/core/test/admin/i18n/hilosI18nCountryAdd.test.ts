import { describe, expect, it } from 'vitest'
import {
  HILOS_I18N_COUNTRY_ADD_ACTION,
  createHilosI18nCountryAdd,
  hilosI18nCountryCode,
  hilosI18nCurrencyCode,
} from '../../../src/admin/i18n/hilosI18nCountryAdd.js'
import { type HilosI18nCountryContext } from '../../../src/admin/i18n/hilosI18nCountry.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { createSignal } from '../../../src/state/signal.js'

function harness(codes: readonly string[] | null = ['us', 'gb']) {
  const catalog = createSignal(codes)
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
  const add = createHilosI18nCountryAdd(
    { actions } as HilosI18nCountryContext,
    catalog,
  )
  return { add, catalog, calls }
}

function fill(add: ReturnType<typeof createHilosI18nCountryAdd>): void {
  add.setCode(' GG ')
  add.setCurrencySymbol(' £ ')
  add.setCurrencyCode(' gbp ')
}

describe('createHilosI18nCountryAdd', () => {
  it('normalizes code inputs and distinguishes known, own and incomplete codes', () => {
    const { add, catalog } = harness()
    add.open()
    expect(hilosI18nCountryCode(' US ')).toBe('us')
    expect(hilosI18nCurrencyCode(' usd ')).toBe('USD')
    expect(add.kind.get()).toBeNull()
    add.setCode(' U ')
    expect(add.kind.get()).toBeNull()
    add.setCode(' US ')
    expect(add.form.get().code).toBe('us')
    expect(add.kind.get()).toBe('known')
    add.setCode(' GG ')
    expect(add.kind.get()).toBe('own')
    catalog.set(null)
    expect(add.kind.get()).toBeNull()
    expect(add.catalogSize.get()).toBeNull()
  })

  it('requires every field, a catalog, and at most eight symbol code points', () => {
    const { add, catalog } = harness()
    add.open()
    expect(add.canSubmit.get()).toBe(false)
    add.setCode('gg')
    expect(add.canSubmit.get()).toBe(false)
    add.setCurrencySymbol('   ')
    add.setCurrencyCode('gbp')
    expect(add.canSubmit.get()).toBe(false)
    add.setCurrencySymbol('😀😀😀😀😀😀😀😀')
    expect(add.canSubmit.get()).toBe(true)
    add.setCurrencySymbol('😀😀😀😀😀😀😀😀😀')
    expect(add.canSubmit.get()).toBe(false)
    add.setCurrencySymbol('£')
    add.setCurrencyCode('GB')
    expect(add.canSubmit.get()).toBe(false)
    add.setCurrencyCode('GBP')
    expect(add.canSubmit.get()).toBe(true)
    catalog.set(null)
    expect(add.canSubmit.get()).toBe(false)
  })

  it('stays fit to submit through its own send, which it does not send again', async () => {
    const { add, catalog, calls } = harness()
    expect(add.submitAvailable.get()).toBe(false)
    add.open()
    add.setCode('US')
    add.setCurrencySymbol('$')
    add.setCurrencyCode('usd')
    expect(add.submitAvailable.get()).toBe(true)
    let settle: ((value: boolean) => void) | undefined
    const run = () =>
      new Promise<boolean>((resolve) => {
        settle = resolve
      })
    const pending = add.submit(run)
    expect(add.saving.get()).toBe(true)
    expect(add.canSubmit.get()).toBe(false)
    expect(add.submitAvailable.get()).toBe(true)
    await add.submit(run)
    expect(calls).toHaveLength(1)
    catalog.set(null)
    expect(add.submitAvailable.get()).toBe(false)
    catalog.set(['us', 'gb'])
    expect(add.submitAvailable.get()).toBe(true)
    settle?.(false)
    await pending
    expect(add.saving.get()).toBe(false)
    expect(add.canSubmit.get()).toBe(true)
    add.close()
    expect(add.submitAvailable.get()).toBe(false)
  })

  it('asks before dispatching an own code and sends trimmed values on confirmation', async () => {
    const { add, calls } = harness()
    add.open()
    fill(add)
    const run = async (): Promise<boolean> => true
    await add.submit(run)
    expect(add.step.get()).toBe('confirm')
    expect(calls).toHaveLength(0)
    await add.confirm(run)
    expect(calls).toEqual([
      {
        action: HILOS_I18N_COUNTRY_ADD_ACTION,
        payload: {
          countryCode: 'gg',
          currencySymbol: '£',
          currencyCode: 'GBP',
        },
      },
    ])
    expect(add.step.get()).toBe('closed')
  })

  it('dispatches a known code immediately and keeps the form on refusal', async () => {
    const { add, calls } = harness()
    add.open()
    add.setCode('US')
    add.setCurrencySymbol('$')
    add.setCurrencyCode('usd')
    await add.submit(async () => false)
    expect(calls).toHaveLength(1)
    expect(add.step.get()).toBe('form')
  })

  it('keeps confirmation on refusal, locks closing during a send, and resets on open', async () => {
    const { add } = harness()
    add.open()
    fill(add)
    await add.submit(async () => true)
    let settle: ((value: boolean) => void) | undefined
    const pending = add.confirm(
      () =>
        new Promise<boolean>((resolve) => {
          settle = resolve
        }),
    )
    expect(add.saving.get()).toBe(true)
    expect(add.canSubmit.get()).toBe(false)
    add.close()
    expect(add.step.get()).toBe('confirm')
    settle?.(false)
    await pending
    expect(add.step.get()).toBe('confirm')
    add.close()
    add.open()
    expect(add.step.get()).toBe('form')
    expect(add.form.get()).toEqual({
      code: '',
      currencySymbol: '',
      currencyCode: '',
    })
  })
})
