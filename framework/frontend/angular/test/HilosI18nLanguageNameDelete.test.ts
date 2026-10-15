// The Angular delete confirmation of a language name: the base, whose cascade it
// says, and one correction, whose base it shows as the inheritance; the value it
// removes fixed at opening, an empty one said as a saved value; its own echo
// held until the ack, an external disappearance said and blocking the confirm,
// a refusal that stays, a busy action that holds every close, and a viewer of
// the admin view mode who opens and reads it but cannot confirm. The dispatch is
// the core's and is tested there.
import { signal } from '@angular/core'
import { HilosPages } from '@hilos/core'
import type {
  HilosI18nLanguageNameDeleteTarget,
  HilosI18nNameRow,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguageNameDelete } from '../src/admin/i18n/details/HilosI18nLanguageNameDelete.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { englishName, i18nBench, mountOver } from './helpers/hilosI18n.js'

const coordinates = {
  languageCode: 'uk',
  languageNativeName: 'Українська',
  inLanguageCode: 'en',
  inLanguageNativeName: 'English',
}
const base: HilosI18nLanguageNameDeleteTarget = {
  ...coordinates,
  kind: 'base',
  expectedName: 'Ukrainian',
}
const emptyCorrection: HilosI18nLanguageNameDeleteTarget = {
  ...coordinates,
  kind: 'override',
  localeCode: 'en-US',
  expectedName: '',
}

function setup(
  target: HilosI18nLanguageNameDeleteTarget = base,
  viewer = false,
) {
  const bench = i18nBench(HilosPages.I18N_LANGUAGE_NAMES, [])
  const view = mountOver(
    HilosI18nLanguageNameDelete,
    bench,
    { open: false, context: bench.context, target, source: englishName },
    [{ provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(viewer).asReadonly() }],
  )
  let closed = 0
  view.fixture.componentInstance.closed.subscribe(() => closed++)
  const set = (name: string, value: unknown): void =>
    view.act(() => view.fixture.componentRef.setInput(name, value))
  set('open', true)
  const confirm = () =>
    view.el<HTMLButtonElement>('i18n-language-name-delete-confirm')!

  return { ...bench, ...view, set, confirm, closed: () => closed }
}

describe('HilosI18nLanguageNameDelete', () => {
  it('says the base cascade and deletes through its own echo, closing only on the ack', async () => {
    const h = setup()
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    const window = h.el('i18n-language-name-delete-window')!
    expect(window.textContent).toContain('English (en)')
    expect(window.textContent).toContain('Ukrainian')
    expect(window.textContent).toContain(
      'All locale corrections of this base name will also be deleted.',
    )
    expect(h.confirm().textContent?.trim()).toBe('Delete')
    h.click('i18n-language-name-delete-confirm')
    h.click('i18n-language-name-delete-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_name_delete',
      payload: {
        languageCode: 'uk',
        inLanguageCode: 'en',
        expectedName: 'Ukrainian',
      },
    })
    expect(
      h.el<HTMLButtonElement>('i18n-language-name-delete-cancel')?.disabled,
    ).toBe(true)
    expect(h.el<HTMLButtonElement>('modal-close')?.disabled).toBe(true)
    h.escape()
    h.set('source', { ...englishName, name: null, corrections: [] })
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-language-name-delete-window')?.textContent).not.toContain(
      'The name no longer exists.',
    )
    expect(h.closed()).toBe(0)
    await h.settle(() => h.succeed())
    expect(h.closed()).toBe(1)
  })

  it('says a saved empty correction and the base it inherits, and removes only that locale', async () => {
    const h = setup(emptyCorrection)
    const window = h.el('i18n-language-name-delete-window')!
    expect(window.textContent).toContain('English (en) · en-US')
    expect(window.textContent).toContain('Empty value')
    expect(window.querySelector('.visually-hidden')?.textContent).toContain(
      'A saved empty string, not an absent name.',
    )
    expect(window.textContent).toContain('Base name to inherit:')
    expect(window.textContent).toContain('Ukrainian')
    expect(h.confirm().textContent?.trim()).toBe('Remove')
    h.click('i18n-language-name-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_name_override_delete',
      payload: {
        languageCode: 'uk',
        inLanguageCode: 'en',
        localeCode: 'en-US',
        expectedName: '',
      },
    })
    await h.settle(() => h.refuse(0, 'The name changed in the meantime.'))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-language-name-delete-error')?.textContent).toContain(
      'The name changed in the meantime.',
    )
    expect(h.closed()).toBe(0)
  })

  it('keeps the value fixed at opening while it follows the live row', () => {
    const h = setup()
    h.set('target', { ...base, expectedName: 'Newer' })
    h.set('source', { ...englishName, name: 'Newer' })
    expect(h.el('i18n-language-name-delete-window')?.textContent).toContain(
      'Ukrainian',
    )
    h.click('i18n-language-name-delete-confirm')
    expect(h.calls[0]?.payload).toMatchObject({ expectedName: 'Ukrainian' })
  })

  it.each([
    ['base', base, null],
    ['row', base, { ...englishName, code: 'es' }],
    [
      'correction',
      emptyCorrection,
      {
        ...englishName,
        corrections: englishName.corrections.map((item) => ({
          ...item,
          name: null,
        })),
      },
    ],
  ] as const)(
    'stays open over a %s gone elsewhere, saying so and blocking the confirm',
    (_, target, source: HilosI18nNameRow | null) => {
      const h = setup(target)
      h.set('source', source)
      expect(h.el('modal')).not.toBeNull()
      expect(h.el('i18n-language-name-delete-notice')?.textContent).toContain(
        'The name no longer exists.',
      )
      expect(h.confirm().disabled).toBe(true)
      h.confirm().click()
      expect(h.calls).toHaveLength(0)
      h.click('i18n-language-name-delete-cancel')
      expect(h.closed()).toBe(1)
    },
  )

  it('lets a viewer read the window and cancel while the confirm sends nothing', () => {
    const h = setup(base, true)
    expect(h.el('i18n-language-name-delete-window')).not.toBeNull()
    expect(h.confirm().disabled).toBe(true)
    expect(h.confirm().getAttribute('aria-describedby')).toBeTruthy()
    h.confirm().click()
    expect(h.calls).toHaveLength(0)
    h.click('i18n-language-name-delete-cancel')
    expect(h.closed()).toBe(1)
  })

  it('says nothing to the page when its ack lands after the view is gone', async () => {
    const h = setup()
    h.click('i18n-language-name-delete-confirm')
    h.fixture.destroy()
    h.succeed()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(h.closed()).toBe(0)
  })
})
