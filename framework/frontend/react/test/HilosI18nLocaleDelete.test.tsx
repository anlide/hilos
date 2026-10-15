import { useState } from 'react'
import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  createHilosI18nLocaleDelete,
  type HilosI18nLocaleDeleteTarget,
  hilosToasts,
} from '@hilos/core'
import { HilosI18nLocaleDelete } from '../src/admin/i18n/details/HilosI18nLocaleDelete.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { harness, el, click, settle } from './hilosI18nHarness.js'

const target: HilosI18nLocaleDeleteTarget = {
  languageId: 19,
  countryId: 101,
  localeId: 19101,
  languageCode: 'uk',
  countryCode: 'gb',
  localeCode: 'uk-GB',
  nativeName: 'Українська',
  countryLabel: 'United Kingdom',
}
function setup(viewer = false, countryless = false) {
  const h = harness(),
    selected = countryless
      ? {
          ...target,
          countryId: null,
          countryCode: null,
          localeId: 19000,
          localeCode: 'uk',
          countryLabel: null,
        }
      : target
  let setSource: (row: HilosI18nLocaleDeleteTarget | null) => void = () => {},
    setViewer: (viewer: boolean) => void = () => {}
  function Test() {
    const [open, setOpen] = useState(true),
      [source, update] = useState<HilosI18nLocaleDeleteTarget | null>(selected),
      [mode, updateViewer] = useState(viewer)
    setSource = update
    setViewer = updateViewer
    return (
      <HilosAdminViewModeContext.Provider value={mode}>
        <HilosI18nLocaleDelete
          open={open}
          onClose={() => setOpen(false)}
          target={selected}
          source={source}
          deleteLocale={createHilosI18nLocaleDelete(h.context).deleteLocale}
        />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Test />)
  return {
    ...h,
    view,
    source: (row: HilosI18nLocaleDeleteTarget | null) =>
      act(() => setSource(row)),
    viewer: (value: boolean) => act(() => setViewer(value)),
    selected,
  }
}

describe('React locale deletion', () => {
  it.each([false, true])(
    'keeps frozen labels and target through its own echo (countryless %s)',
    async (countryless) => {
      const h = setup(false, countryless)
      expect(document.activeElement?.getAttribute('role')).toBe('dialog')
      h.source({
        ...h.selected,
        nativeName: 'New label',
        countryLabel: 'New country',
      })
      expect(
        el('i18n-locale-delete-window')
          .closest('[role="dialog"]')
          ?.getAttribute('aria-label'),
      ).toContain('Українська')
      click('i18n-locale-delete-confirm')
      click('i18n-locale-delete-confirm')
      expect(h.calls).toHaveLength(1)
      expect(h.calls[0]).toMatchObject({
        action: 'hilos_i18n_locale_delete',
        payload: {
          languageId: 19,
          countryId: countryless ? null : 101,
          localeId: countryless ? 19000 : 19101,
        },
      })
      h.source(null)
      click('modal-close')
      click('modal')
      fireEvent.keyDown(el('modal'), { key: 'Escape' })
      expect(el<HTMLButtonElement>('i18n-locale-delete-cancel').disabled).toBe(
        true,
      )
      expect(el('i18n-locale-delete-notice')).toBeNull()
      await settle(() => h.succeed(0, 'Server deleted locale'))
      expect(el('i18n-locale-delete-window')).toBeNull()
      expect(
        hilosToasts.toasts
          .get()
          .some((row) => row.message === 'Server deleted locale'),
      ).toBe(true)
    },
  )
  it.each(['enabled', 'country-default', 'empty-name-correction'] as const)(
    'keeps the server %s refusal and details without cascading',
    async (reason) => {
      const h = setup(),
        room = el('i18n-locale-delete-error')
      expect(room.querySelector('.invisible')).not.toBeNull()
      expect(el('i18n-locale-delete-window').textContent).toContain(
        'name overrides for this locale first; deletion never removes them.',
      )
      click('i18n-locale-delete-confirm')
      await settle(() => h.refuse(0, `Cannot delete: ${reason}`))
      expect(el('i18n-locale-delete-error')).toBe(room)
      expect(room.textContent).toContain(`Cannot delete: ${reason}`)
      expect(el<HTMLButtonElement>('i18n-locale-delete-confirm').disabled).toBe(
        false,
      )
      click('hilos-action-error-details')
      expect(document.body.textContent).toContain('The dependency appeared')
    },
  )
  it.each(['row', 'language', 'country', 'locale'] as const)(
    'keeps an idle dialog and refuses a missing %s coordinate',
    (gone) => {
      const h = setup()
      h.source(
        gone === 'row'
          ? null
          : {
              ...target,
              ...(gone === 'language'
                ? { languageId: 13 }
                : gone === 'country'
                  ? { countryId: 102 }
                  : { localeId: 19102 }),
            },
      )
      expect(el('i18n-locale-delete-notice').textContent).toContain(
        'Deleted elsewhere',
      )
      click('i18n-locale-delete-confirm')
      expect(h.calls).toEqual([])
      click('i18n-locale-delete-cancel')
      expect(el('modal')).toBeNull()
    },
  )
  it('retains refusal when its source vanished and follows live viewer mode', async () => {
    const h = setup(true)
    click('i18n-locale-delete-confirm')
    expect(h.calls).toEqual([])
    h.viewer(false)
    click('i18n-locale-delete-confirm')
    h.source(null)
    await settle(() => h.refuse(0, 'Locale no longer exists'))
    expect(el('i18n-locale-delete-error').textContent).toContain(
      'Locale no longer exists',
    )
    expect(el<HTMLButtonElement>('i18n-locale-delete-confirm').disabled).toBe(
      true,
    )
    expect(el<HTMLButtonElement>('i18n-locale-delete-cancel').disabled).toBe(
      false,
    )
  })
  it('ignores late success after teardown so a new dialog stays open', async () => {
    const h = setup()
    click('i18n-locale-delete-confirm')
    h.view.unmount()
    setup()
    await settle(() => h.succeed())
    expect(el('i18n-locale-delete-window')).not.toBeNull()
  })
})
