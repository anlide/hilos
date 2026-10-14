import { useState } from 'react'
import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
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

describe('React locale window forms', () => {
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
      payload: { languageCode: 'uk', countryCode: 'gb', formats },
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
        languageCode: 'uk',
        countryCode: 'gb',
        formats: { ...formats, date: 'YYYY-MM-DD', measurement: 'imperial' },
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
        payload: { languageCode: 'uk', countryCode: country },
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
