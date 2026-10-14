import { useState } from 'react'
import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  type HilosI18nLanguageNameDeleteTarget,
  type HilosI18nNameRow,
  hilosToasts,
} from '@hilos/core'
import { HilosI18nLanguageNameDelete } from '../src/admin/i18n/details/HilosI18nLanguageNameDelete.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { harness, el, click, settle } from './hilosI18nHarness.js'
import { nameRow } from './hilosI18nDetailsHarness.js'

const base: HilosI18nLanguageNameDeleteTarget = {
  kind: 'base',
  languageCode: 'uk',
  languageNativeName: 'Українська',
  inLanguageCode: 'en',
  inLanguageNativeName: 'English',
  expectedName: 'Ukrainian',
}
function setup(
  kind: 'base' | 'override' = 'base',
  empty = false,
  viewer = false,
) {
  const h = harness()
  const initial: HilosI18nLanguageNameDeleteTarget =
    kind === 'base'
      ? { ...base, expectedName: empty ? '' : base.expectedName }
      : {
          ...base,
          kind,
          localeCode: empty ? 'en-US' : 'en-GB',
          expectedName: empty ? '' : 'Correction',
        }
  let setSource: (row: HilosI18nNameRow | null) => void = () => {},
    setViewer: (viewer: boolean) => void = () => {},
    setTarget: (target: HilosI18nLanguageNameDeleteTarget) => void = () => {}
  function Test() {
    const [open, setOpen] = useState(true),
      [source, update] = useState<HilosI18nNameRow | null>(
        empty ? { ...nameRow, name: '' } : nameRow,
      ),
      [mode, updateViewer] = useState(viewer),
      [target, updateTarget] = useState(initial)
    setSource = update
    setViewer = updateViewer
    setTarget = updateTarget
    return (
      <HilosAdminViewModeContext.Provider value={mode}>
        <HilosI18nLanguageNameDelete
          open={open}
          onClose={() => setOpen(false)}
          context={h.context}
          source={source}
          target={target}
        />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Test />)
  return {
    ...h,
    view,
    source: (row: HilosI18nNameRow | null) => act(() => setSource(row)),
    viewer: (value: boolean) => act(() => setViewer(value)),
    target: (value: HilosI18nLanguageNameDeleteTarget) =>
      act(() => setTarget(value)),
    initial,
  }
}

describe('React language name deletion', () => {
  it.each(['base', 'override'] as const)(
    'freezes the %s text, shows its scope and waits for ack past its own echo',
    async (kind) => {
      const h = setup(kind)
      expect(document.activeElement?.getAttribute('role')).toBe('dialog')
      expect(el('i18n-language-name-delete-window').textContent).toContain(
        kind === 'base'
          ? 'All locale corrections'
          : 'Only this locale correction',
      )
      h.source({ ...nameRow, name: 'New base' })
      h.target({ ...h.initial, expectedName: 'Wrong new target' })
      click('i18n-language-name-delete-confirm')
      click('i18n-language-name-delete-confirm')
      expect(h.calls).toHaveLength(1)
      expect(h.calls[0]).toMatchObject({
        action:
          kind === 'base'
            ? 'hilos_i18n_language_name_delete'
            : 'hilos_i18n_language_name_override_delete',
        payload: {
          languageCode: 'uk',
          inLanguageCode: 'en',
          expectedName: h.initial.expectedName,
          ...(kind === 'override' ? { localeCode: 'en-GB' } : {}),
        },
      })
      h.source(null)
      click('modal-close')
      fireEvent.keyDown(el('modal'), { key: 'Escape' })
      click('modal')
      expect(
        el<HTMLButtonElement>('i18n-language-name-delete-cancel').disabled,
      ).toBe(true)
      expect(el('i18n-language-name-delete-window')).not.toBeNull()
      await settle(() => h.succeed(0, 'Server named this deletion'))
      expect(el('modal')).toBeNull()
      expect(
        hilosToasts.toasts
          .get()
          .some((row) => row.message === 'Server named this deletion'),
      ).toBe(true)
    },
  )
  it.each(['base', 'override'] as const)(
    'dispatches an empty %s value literally',
    async (kind) => {
      const h = setup(kind, true)
      expect(document.body.textContent).toContain(
        'A saved empty string, not an absent name.',
      )
      click('i18n-language-name-delete-confirm')
      expect(h.calls[0]?.payload).toMatchObject({ expectedName: '' })
      await settle(() => h.succeed())
    },
  )
  it.each(['base', 'override', 'row', 'coordinate'] as const)(
    'holds the confirmation when its %s disappears',
    (gone) => {
      const h = setup('override')
      h.source(
        gone === 'row'
          ? null
          : gone === 'base'
            ? { ...nameRow, name: null }
            : gone === 'coordinate'
              ? { ...nameRow, code: 'fr' }
              : { ...nameRow, corrections: [] },
      )
      expect(el('i18n-language-name-delete-notice').textContent).toContain(
        'no longer exists',
      )
      expect(
        el<HTMLButtonElement>('i18n-language-name-delete-confirm').disabled,
      ).toBe(true)
      click('i18n-language-name-delete-confirm')
      expect(h.calls).toEqual([])
      click('i18n-language-name-delete-cancel')
      expect(el('modal')).toBeNull()
    },
  )
  it('keeps a changed-name refusal and details in the same reserved row', async () => {
    const h = setup(),
      room = el('i18n-language-name-delete-error')
    click('i18n-language-name-delete-confirm')
    await settle(() => h.refuse(0, 'The name changed. Confirm again.'))
    expect(el('i18n-language-name-delete-error')).toBe(room)
    expect(room.textContent).toContain('The name changed')
    click('hilos-action-error-details')
    expect(document.body.textContent).toContain('The dependency appeared')
    expect(el('i18n-language-name-delete-window')).not.toBeNull()
  })
  it('responds to live viewer mode and ignores success after unmount', async () => {
    const h = setup('base', false, true)
    click('i18n-language-name-delete-confirm')
    expect(h.calls).toEqual([])
    h.viewer(false)
    click('i18n-language-name-delete-confirm')
    h.view.unmount()
    const next = setup()
    await settle(() => h.succeed())
    expect(el('i18n-language-name-delete-window')).not.toBeNull()
    next.viewer(true)
    expect(
      el<HTMLButtonElement>('i18n-language-name-delete-confirm').disabled,
    ).toBe(true)
  })
})
