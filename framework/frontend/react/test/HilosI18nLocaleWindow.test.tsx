import { useState } from 'react'
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  type HilosI18nLanguageCard,
  type HilosI18nLocaleRow,
  hilosI18nLocaleWindowMode,
} from '@hilos/core'
import { HilosI18nLocaleWindow } from '../src/admin/i18n/details/HilosI18nLocaleWindow.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import {
  harness,
  languageCard,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'
import {
  formats,
  templates,
  localeRow,
  absentLocale,
  countryless,
  focusSource,
} from './hilosI18nDetailsHarness.js'

function setup(row = localeRow, viewer = false, initialCard = languageCard) {
  const h = harness(),
    focus = focusSource<HilosI18nLocaleRow>(row)
  let updateCard: (card: HilosI18nLanguageCard | null) => void = () => {},
    updateViewer: (viewer: boolean) => void = () => {},
    updateOpen: (opened: boolean) => void = () => {}
  const opening = { rowKey: row.rowKey, mode: hilosI18nLocaleWindowMode(row) }
  function Test() {
    const [card, setCard] = useState<HilosI18nLanguageCard | null>(initialCard),
      [mode, setViewer] = useState(viewer),
      [open, setOpen] = useState(true)
    updateCard = setCard
    updateViewer = setViewer
    updateOpen = setOpen
    return (
      <HilosAdminViewModeContext.Provider value={mode}>
        <HilosI18nLocaleWindow
          context={h.context}
          controller={focus.controller}
          card={card}
          templates={templates}
          opened={open ? opening : null}
          onClose={() => setOpen(false)}
        />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Test />)
  return {
    ...h,
    ...focus,
    view,
    card: (card: HilosI18nLanguageCard | null) => act(() => updateCard(card)),
    viewer: (viewer: boolean) => act(() => updateViewer(viewer)),
    reopen: () => {
      act(() => updateOpen(false))
      act(() => updateOpen(true))
    },
  }
}
function enter(): void {
  fireEvent.submit(document.querySelector('form')!)
}
function busyClose(): void {
  click('modal-close')
  fireEvent.keyDown(el('modal'), { key: 'Escape' })
  click('modal')
}

// What a footer button tells a sighted person and a screen reader now.
function buttonState(id: string) {
  const button = el<HTMLButtonElement>(id)

  return {
    nativeDisabled: button.disabled,
    disabledLook: button.classList.contains('disabled'),
    spinner:
      button.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: button.getAttribute('aria-busy'),
    ariaDisabled: button.getAttribute('aria-disabled'),
  }
}

const RESTING = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const OFF = { ...RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const LOCKED = { ...RESTING, ariaBusy: 'true', ariaDisabled: 'true' }
const SPINNING = { ...LOCKED, disabledLook: true, spinner: true }

// Advance the fake clock and let the view render what the timers decided.
function wait(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

describe('React locale window forms', () => {
  it('keeps the draft when a new locale takes the same parent pair', () => {
    const h = setup()
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    act(() => h.focused.set({ ...localeRow, localeId: 999 }))
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
    expect(el('i18n-locale-submit').textContent).toBe('Deleted')
    expect(h.calls).toHaveLength(0)
  })

  it('prefills all seven closed selects from the focused catalog and sends fixed coordinates', async () => {
    const h = setup(absentLocale)
    const selects = document.querySelectorAll('select')
    expect(selects).toHaveLength(7)
    for (const [field, value] of Object.entries(formats))
      expect(el<HTMLSelectElement>(`i18n-locale-field-${field}`).value).toBe(
        value,
      )
    expect(el('i18n-locale-code').textContent).toBe('uk-GB')
    expect(document.querySelector('input')).toBeNull()
    expect(el('i18n-locale-plate').textContent).toContain('added switched off')
    enter()
    enter()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_add',
      payload: { languageId: 19, countryId: 101, formats },
    })
    busyClose()
    expect(el('i18n-locale-window')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('i18n-locale-window')).toBeNull()
    expect(h.focused.get()).toBeUndefined()
  })
  it('requires seven choices for an unknown pair and offers named units', () => {
    const h = setup({
      ...absentLocale,
      countryName: null,
      catalogFormats: null,
    })
    expect(el('i18n-locale-title').textContent).toContain('in GB')
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
    enter()
    expect(h.calls).toEqual([])
    for (const [field, value] of Object.entries(formats)) {
      expect(el<HTMLSelectElement>(`i18n-locale-field-${field}`).value).toBe('')
      expect(el(`i18n-locale-field-${field}`).textContent).toContain('Choose…')
      change(`i18n-locale-field-${field}`, value)
    }
    expect(el('i18n-locale-field-measurement').textContent).toContain(
      'MetricImperial',
    )
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(false)
  })
  it('keeps the Add title, merges a new locale and saves a resolved draft by live id', async () => {
    const h = setup(absentLocale)
    const title = el('i18n-locale-title').textContent
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, date: 'DD.MM.YYYY', measurement: 'imperial' },
      }),
    )
    expect(el('i18n-locale-title').textContent).toContain(title!)
    expect(el('conflict-badge')).not.toBeNull()
    expect(el('i18n-locale-notice').textContent).toContain('DD.MM.YYYY')
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    expect(el<HTMLSelectElement>('i18n-locale-field-measurement').value).toBe(
      'imperial',
    )
    expect(el('conflict-merge')).toBeNull()
    enter()
    expect(h.calls).toEqual([])
    click('conflict-accept-mine')
    expect(el('i18n-locale-submit').textContent).toBe('Save')
    click('i18n-locale-submit')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_update',
      payload: {
        languageId: 19,
        countryId: 101,
        localeId: 19101,
        formats: { ...formats, date: 'YYYY-MM-DD', measurement: 'imperial' },
      },
    })
    expect(el('modal')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it.each(['untouched', 'matching', 'theirs'] as const)(
    'accepts %s incoming without leaving a save',
    (kind) => {
      const h = setup(absentLocale)
      if (kind !== 'untouched') change('i18n-locale-field-date', 'YYYY-MM-DD')
      const incoming = kind === 'matching' ? 'YYYY-MM-DD' : 'DD.MM.YYYY'
      act(() =>
        h.focused.set({
          ...localeRow,
          formats: { ...formats, date: incoming },
        }),
      )
      if (kind === 'theirs') click('conflict-accept-theirs')
      expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
        incoming,
      )
      expect(el('conflict-badge')).toBeNull()
      expect(el('i18n-locale-notice').textContent).toContain('Updated just now')
      expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
      expect(h.calls).toEqual([])
    },
  )
  it.each([false, true])(
    'keeps an occupied Add draft readable while enabled and preserves viewer guards (%s)',
    (viewer) => {
      const h = setup(absentLocale, viewer)
      change('i18n-locale-field-date', 'YYYY-MM-DD')
      act(() =>
        h.focused.set({
          ...localeRow,
          enabled: true,
          formats: { ...formats, date: 'DD.MM.YYYY' },
        }),
      )
      expect(el('i18n-locale-notice').textContent).toContain(
        'Switched on elsewhere just now.',
      )
      expect(el('i18n-locale-plate').textContent).toContain('switched on')
      expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
        'YYYY-MM-DD',
      )
      click('conflict-accept-mine')
      click('i18n-locale-submit')
      enter()
      expect(h.calls).toEqual([])
      act(() =>
        h.focused.set({
          ...localeRow,
          formats: { ...formats, date: 'DD.MM.YYYY' },
        }),
      )
      expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(viewer)
      if (viewer) {
        enter()
        expect(h.calls).toEqual([])
      }
      h.viewer(false)
      expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(false)
    },
  )
  it('merges incoming fields, offers both conflict choices and preserves refusal through editing', async () => {
    const h = setup()
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
    await settle(enter)
    expect(h.calls).toEqual([])
    expect(el('modal')).toBeNull()
    h.reopen()
    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, measurement: 'imperial' },
      }),
    )
    expect(el<HTMLSelectElement>('i18n-locale-field-measurement').value).toBe(
      'imperial',
    )
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, date: 'DD.MM.YYYY', measurement: 'imperial' },
      }),
    )
    expect(el('i18n-locale-notice').textContent).toContain('DD.MM.YYYY')
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
    expect(el('conflict-merge')).toBeNull()
    click('conflict-accept-mine')
    enter()
    enter()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_update',
      payload: {
        languageId: 19,
        countryId: 101,
        formats: { ...formats, date: 'YYYY-MM-DD', measurement: 'imperial' },
        localeId: 19101,
      },
    })
    await settle(() => h.refuse())
    const room = el('i18n-locale-error')
    expect(room.textContent).toContain('Server refused')
    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, date: 'MM/DD/YYYY', measurement: 'imperial' },
      }),
    )
    click('conflict-accept-theirs')
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'MM/DD/YYYY',
    )
    expect(el('i18n-locale-error')).toBe(room)
    expect(room.textContent).toContain('Server refused')
  })
  it('retains an unsaved draft after external switch-on or deletion', () => {
    const h = setup()
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    act(() => h.focused.set({ ...localeRow, enabled: true }))
    expect(el('i18n-locale-notice').textContent).toContain(
      'Switched on elsewhere',
    )
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    expect(el<HTMLSelectElement>('i18n-locale-field-date').disabled).toBe(true)
    act(() => h.focused.set(absentLocale))
    expect(el('i18n-locale-submit').textContent).toBe('Deleted')
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    enter()
    expect(h.calls).toEqual([])
  })
  it('keeps view read-only after external switch-off and updates its formats live', () => {
    const h = setup({ ...localeRow, enabled: true })
    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, date: 'YYYY-MM-DD' },
      }),
    )
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
    expect(el<HTMLSelectElement>('i18n-locale-field-date').disabled).toBe(true)
    expect(el('i18n-locale-submit')).toBeNull()
    expect(el('i18n-locale-notice').textContent).toContain(
      'Switched off elsewhere',
    )
    click('i18n-locale-close')
    expect(el('modal')).toBeNull()
  })
  it.each(['add', 'edit'] as const)(
    'allows a viewer to read %s and blocks Save and Enter',
    (mode) => {
      const h = setup(mode === 'add' ? absentLocale : localeRow, true)
      if (mode === 'edit') change('i18n-locale-field-date', 'YYYY-MM-DD')
      enter()
      click('i18n-locale-submit')
      expect(el<HTMLButtonElement>('i18n-locale-submit').disabled).toBe(true)
      expect(h.calls).toEqual([])
    },
  )
  it('uses fresh focused mode and releases focus and sessions on unmount', () => {
    const h = harness(),
      focus = focusSource({ ...localeRow, enabled: true })
    const view = h.mount(
      <HilosI18nLocaleWindow
        context={h.context}
        controller={focus.controller}
        card={languageCard}
        templates={templates}
        opened={{ rowKey: localeRow.rowKey, mode: 'edit' }}
        onClose={() => {}}
      />,
    )
    expect(el('i18n-locale-submit')).toBeNull()
    expect(el('i18n-locale-switch-off')).not.toBeNull()
    view.unmount()
    expect(focus.focused.get()).toBeUndefined()
  })
})

describe('React locale confirmations', () => {
  it.each([false, true])(
    'blocks switch-on of an unsaved or conflicted draft (%s)',
    (conflict) => {
      const h = setup()
      change('i18n-locale-field-date', 'YYYY-MM-DD')
      if (conflict)
        act(() =>
          h.focused.set({
            ...localeRow,
            formats: { ...formats, date: 'DD.MM.YYYY' },
          }),
        )
      click('i18n-locale-switch-on')
      expect(el('i18n-locale-switch-on-unsaved').textContent).toContain(
        'not saved',
      )
      expect(
        el<HTMLButtonElement>('i18n-locale-switch-on-confirm').disabled,
      ).toBe(true)
      click('i18n-locale-switch-on-confirm')
      expect(h.calls).toEqual([])
      busyClose()
      expect(el('modal-confirm-discard')).not.toBeNull()
      click('modal-confirm-cancel')
      click('i18n-locale-switch-on-back')
      expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
        'YYYY-MM-DD',
      )
      expect(el('conflict-badge') !== null).toBe(conflict)
    },
  )
  it.each(['on', 'off'] as const)(
    'holds step room and a single dialog while confirming switch %s',
    (direction) => {
      setup({ ...localeRow, enabled: direction === 'off' })
      const room = el('i18n-locale-error'),
        notice = el('i18n-locale-notice-slot')
      click(`i18n-locale-switch-${direction}`)
      expect(document.querySelectorAll('[data-id="modal"]')).toHaveLength(1)
      expect(document.activeElement?.getAttribute('role')).toBe('dialog')
      expect(el('i18n-locale-error')).toBe(room)
      expect(el('i18n-locale-notice-slot')).toBe(notice)
      expect(el('i18n-locale-field-date')).toBeNull()
      for (const twin of document.querySelectorAll('.hilos-stack.invisible')) {
        expect(twin.hasAttribute('inert')).toBe(true)
        expect(twin.getAttribute('aria-hidden')).toBe('true')
        expect(
          twin.querySelector(
            '[data-id], [data-autofocus], form, input, select, textarea, button',
          ),
        ).toBeNull()
      }
      click(`i18n-locale-switch-${direction}-back`)
      expect(el<HTMLSelectElement>('i18n-locale-field-date').disabled).toBe(
        direction === 'off',
      )
    },
  )
  it.each([
    ['on', 'gb'],
    ['on', null],
    ['off', 'gb'],
    ['off', null],
  ] as const)(
    'sends switch %s for country %s once and awaits ack through its own echo',
    async (direction, country) => {
      const row = {
        ...(country === null ? countryless : localeRow),
        enabled: direction === 'off',
      }
      const h = setup(row)
      click(`i18n-locale-switch-${direction}`)
      click(`i18n-locale-switch-${direction}-confirm`)
      click(`i18n-locale-switch-${direction}-confirm`)
      expect(h.calls).toHaveLength(1)
      expect(h.calls[0]).toMatchObject({
        action: `hilos_i18n_locale_switch_${direction}`,
        payload: {
          languageId: 19,
          countryId: country === null ? null : 101,
          localeId: country === null ? 19000 : 19101,
        },
      })
      act(() => h.focused.set({ ...row, enabled: direction === 'on' }))
      expect(el('i18n-locale-notice-slot').textContent).not.toContain(
        'elsewhere',
      )
      busyClose()
      expect(
        el<HTMLButtonElement>(`i18n-locale-switch-${direction}-back`).disabled,
      ).toBe(true)
      await settle(() => h.succeed())
      expect(el('i18n-locale-window')).toBeNull()
      expect(h.focused.get()).toBeUndefined()
    },
  )
  it('explains the protected countryless default and refuses confirmation', () => {
    const h = setup({ ...countryless, enabled: true }, false, {
      ...languageCard,
      summary: { ...languageCard.summary, isDefault: true },
    })
    expect(el<HTMLButtonElement>('i18n-locale-switch-off').disabled).toBe(false)
    click('i18n-locale-switch-off')
    expect(el('i18n-locale-switch-off-reason').textContent).toContain(
      'cannot be switched off',
    )
    expect(
      el<HTMLButtonElement>('i18n-locale-switch-off-confirm').disabled,
    ).toBe(true)
    click('i18n-locale-switch-off-confirm')
    expect(h.calls).toEqual([])
  })
  it.each(['language', 'country'] as const)(
    'warns before switching off the %s fallback but permits the saved operation',
    (kind) => {
      const h = setup(
        kind === 'language'
          ? { ...countryless, enabled: true }
          : { ...localeRow, enabled: true, isCountryDefault: true },
        false,
        { ...languageCard, enabled: true },
      )
      click('i18n-locale-switch-off')
      expect(el('i18n-locale-switch-off-warning').textContent).toContain(
        kind === 'language' ? 'default language' : 'chosen locale stays',
      )
      expect(
        el<HTMLButtonElement>('i18n-locale-switch-off-confirm').disabled,
      ).toBe(false)
      expect(h.calls).toEqual([])
    },
  )
  it.each(['on', 'off'] as const)(
    'keeps switch-%s refusal, permits retry and resets the next opening',
    async (direction) => {
      const h = setup({ ...localeRow, enabled: direction === 'off' })
      click(`i18n-locale-switch-${direction}`)
      click(`i18n-locale-switch-${direction}-confirm`)
      await settle(() => h.refuse(0, 'Server refused switch'))
      expect(el('i18n-locale-error').textContent).toContain(
        'Server refused switch',
      )
      expect(el(`i18n-locale-switch-${direction}-body`)).not.toBeNull()
      click(`i18n-locale-switch-${direction}-back`)
      click(`i18n-locale-switch-${direction}`)
      expect(el('i18n-locale-error').textContent).not.toContain(
        'Server refused switch',
      )
      click(`i18n-locale-switch-${direction}-confirm`)
      await settle(() => h.refuse(1))
      h.reopen()
      expect(el(`i18n-locale-switch-${direction}-body`)).toBeNull()
      expect(el('i18n-locale-error').textContent).not.toContain(
        'Server refused',
      )
    },
  )
  it.each(['row', 'formats', 'locale', 'card'] as const)(
    'keeps the switch step on deletion of %s',
    (gone) => {
      const h = setup({ ...localeRow, enabled: true })
      click('i18n-locale-switch-off')
      if (gone === 'card') h.card(null)
      else
        act(() =>
          h.focused.set(
            gone === 'row'
              ? undefined
              : {
                  ...localeRow,
                  enabled: true,
                  ...(gone === 'locale'
                    ? { localeCode: null }
                    : { formats: null }),
                },
          ),
        )
      expect(el('i18n-locale-notice').textContent).toContain(
        'Deleted elsewhere',
      )
      expect(
        el<HTMLButtonElement>('i18n-locale-switch-off-confirm').disabled,
      ).toBe(true)
      click('i18n-locale-switch-off-back')
      expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
        formats.date,
      )
      expect(h.calls).toEqual([])
    },
  )
  it.each(['on', 'off'] as const)(
    'leaves the switch-%s opener readable for a viewer and follows external switching',
    (direction) => {
      const h = setup({ ...localeRow, enabled: direction === 'off' }, true)
      click(`i18n-locale-switch-${direction}`)
      expect(
        el<HTMLButtonElement>(`i18n-locale-switch-${direction}-confirm`)
          .disabled,
      ).toBe(true)
      click(`i18n-locale-switch-${direction}-confirm`)
      expect(h.calls).toEqual([])
      h.viewer(false)
      act(() => h.focused.set({ ...localeRow, enabled: direction === 'on' }))
      expect(el('i18n-locale-notice').textContent).toContain(
        direction === 'on' ? 'Switched on elsewhere' : 'Switched off elsewhere',
      )
      expect(
        el<HTMLButtonElement>(`i18n-locale-switch-${direction}-confirm`)
          .disabled,
      ).toBe(true)
    },
  )
  it('merges incoming formats during the confirmation step without an unsaved warning', () => {
    const h = setup()
    click('i18n-locale-switch-on')
    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, date: 'YYYY-MM-DD' },
      }),
    )
    expect(el('i18n-locale-notice').textContent).toContain('Updated just now')
    expect(el('i18n-locale-switch-on-unsaved')).toBeNull()
    click('i18n-locale-switch-on-back')
    expect(el<HTMLSelectElement>('i18n-locale-field-date').value).toBe(
      'YYYY-MM-DD',
    )
  })
})

// The real locale factories, LoadingButton and ActionLifecycle together: Add
// looks by addAvailable and Save by saveAvailable, so their own send only locks
// them and the look comes with the spinner at 300 ms, while the guarded canAdd
// and canSave keep a second press or Enter from sending. The pressed switch
// confirm spins, its Back is off natively; a pair taken or gone, an unfit form,
// a conflict and the admin view mode turn the button off natively.
describe('React locale window in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('locks Add at once and greys it only with its spinner at 300 ms', async () => {
    const h = setup(absentLocale)
    expect(buttonState('i18n-locale-submit')).toEqual(RESTING)

    click('i18n-locale-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]?.action).toBe('hilos_i18n_locale_add')
    expect(buttonState('i18n-locale-submit')).toEqual(LOCKED)
    expect(el<HTMLButtonElement>('i18n-locale-cancel').disabled).toBe(true)
    click('i18n-locale-submit')
    enter()
    expect(h.calls).toHaveLength(1)

    wait(299)
    expect(buttonState('i18n-locale-submit')).toEqual(LOCKED)
    wait(1)
    expect(buttonState('i18n-locale-submit')).toEqual(SPINNING)
    enter()
    expect(h.calls).toHaveLength(1)

    await settle(() => h.refuse())
    expect(buttonState('i18n-locale-submit')).toEqual(RESTING)
    click('i18n-locale-submit')
    expect(h.calls).toHaveLength(2)
  })
  it('turns Add off at once for a pair taken elsewhere', () => {
    const h = setup(absentLocale)
    act(() => h.focused.set(localeRow))
    expect(buttonState('i18n-locale-submit')).toEqual(OFF)

    enter()
    wait(300)
    expect(buttonState('i18n-locale-submit')).toEqual(OFF)
    expect(h.calls).toEqual([])
  })
  it('turns Add off natively under its lock when the pair goes in flight', async () => {
    const h = setup(absentLocale)
    click('i18n-locale-submit')
    expect(buttonState('i18n-locale-submit')).toEqual(LOCKED)

    act(() => h.focused.set(undefined))
    expect(buttonState('i18n-locale-submit')).toEqual({
      ...LOCKED,
      nativeDisabled: true,
    })
    expect(el('i18n-locale-submit').textContent).toBe('Deleted')

    await settle(() => h.refuse())
    expect(buttonState('i18n-locale-submit')).toEqual(OFF)
  })
  it.each([false, true])(
    'turns Add off at once for an unchosen unknown pair or a viewer (viewer %s)',
    (viewer) => {
      const h = setup(
        viewer
          ? absentLocale
          : { ...absentLocale, countryName: null, catalogFormats: null },
        viewer,
      )
      expect(buttonState('i18n-locale-submit')).toEqual(OFF)

      enter()
      wait(300)
      expect(buttonState('i18n-locale-submit')).toEqual(OFF)
      expect(h.calls).toEqual([])
    },
  )
  it('locks the edit Save at once, spins it at 300 ms and turns it off for a conflict in flight', async () => {
    const h = setup()
    change('i18n-locale-field-date', 'YYYY-MM-DD')
    expect(buttonState('i18n-locale-submit')).toEqual(RESTING)

    click('i18n-locale-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]?.action).toBe('hilos_i18n_locale_update')
    expect(buttonState('i18n-locale-submit')).toEqual(LOCKED)
    expect(el<HTMLButtonElement>('i18n-locale-switch-on').disabled).toBe(true)
    click('i18n-locale-submit')
    enter()
    expect(h.calls).toHaveLength(1)

    wait(299)
    expect(buttonState('i18n-locale-submit')).toEqual(LOCKED)
    wait(1)
    expect(buttonState('i18n-locale-submit')).toEqual(SPINNING)

    act(() =>
      h.focused.set({
        ...localeRow,
        formats: { ...formats, date: 'DD.MM.YYYY' },
      }),
    )
    expect(buttonState('i18n-locale-submit')).toEqual({
      ...SPINNING,
      nativeDisabled: true,
    })

    await settle(() => h.refuse())
    expect(buttonState('i18n-locale-submit')).toEqual(OFF)
    expect(h.calls).toHaveLength(1)
  })
  it.each(['on', 'off'] as const)(
    'locks the switch-%s confirm at once with Back off natively and spins it at 300 ms',
    async (direction) => {
      const h = setup({ ...localeRow, enabled: direction === 'off' })
      const confirm = `i18n-locale-switch-${direction}-confirm`
      click(`i18n-locale-switch-${direction}`)
      expect(buttonState(confirm)).toEqual(RESTING)

      click(confirm)
      expect(h.calls).toHaveLength(1)
      expect(buttonState(confirm)).toEqual(LOCKED)
      expect(
        el<HTMLButtonElement>(`i18n-locale-switch-${direction}-back`).disabled,
      ).toBe(true)
      click(confirm)
      expect(h.calls).toHaveLength(1)

      wait(299)
      expect(buttonState(confirm)).toEqual(LOCKED)
      wait(1)
      expect(buttonState(confirm)).toEqual(SPINNING)

      await settle(() => h.refuse())
      expect(buttonState(confirm)).toEqual(RESTING)
      click(confirm)
      expect(h.calls).toHaveLength(2)
    },
  )
})
