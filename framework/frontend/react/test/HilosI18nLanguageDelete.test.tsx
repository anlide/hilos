import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  type HilosI18nLanguageRow,
} from '@hilos/core'
import { HilosI18nLanguageDelete } from '../src/admin/i18n/details/HilosI18nLanguageDelete.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { useSignal } from '../src/useSignal.js'
import { harness, language, el, click, settle } from './hilosI18nHarness.js'

type DeleteAction =
  | typeof HILOS_I18N_LANGUAGE_DELETE_ACTION
  | typeof HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION
function setup(
  actionName: DeleteAction = HILOS_I18N_LANGUAGE_DELETE_ACTION,
  viewer = false,
) {
  const h = harness()
  const source = createSignal<HilosI18nLanguageRow | null>(language),
    open = createSignal(true),
    locked = createSignal(viewer)
  const deleted = vi.fn()
  function Surface() {
    const value = useSignal(source),
      opened = useSignal(open),
      viewOnly = useSignal(locked)
    return (
      <HilosAdminViewModeContext.Provider value={viewOnly}>
        <HilosI18nLanguageDelete
          open={opened}
          onClose={() => open.set(false)}
          onDeleted={deleted}
          context={h.context}
          source={value}
          actionName={actionName}
        />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Surface />)
  return { ...h, source, open, locked, deleted, view }
}

describe('React language deletion', () => {
  it.each([
    HILOS_I18N_LANGUAGE_DELETE_ACTION,
    HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  ] as const)(
    'fixes target and keeps %s pending through its own deletion echo',
    async (actionName) => {
      const h = setup(actionName)
      expect(document.activeElement?.getAttribute('role')).toBe('dialog')
      act(() => h.source.set({ ...language, nativeName: 'Changed elsewhere' }))
      expect(el('modal').textContent).toContain('Delete language · Українська')
      click('i18n-language-delete-confirm')
      click('i18n-language-delete-confirm')
      expect(h.calls).toHaveLength(1)
      expect(h.calls[0]).toMatchObject({
        action: actionName,
        payload: { languageCode: 'uk' },
      })
      expect(
        el<HTMLButtonElement>('i18n-language-delete-cancel').disabled,
      ).toBe(true)
      click('modal-close')
      fireEvent.keyDown(el('modal'), { key: 'Escape' })
      act(() => h.source.set(null))
      expect(el('modal')).not.toBeNull()
      expect(
        el('i18n-language-delete-notice')?.textContent ?? '',
      ).not.toContain('Deleted elsewhere')
      expect(h.deleted).not.toHaveBeenCalled()
      await settle(() => h.succeed())
      expect(el('modal')).toBeNull()
      expect(h.deleted).toHaveBeenCalledOnce()
    },
  )
  it('lets the server judge a newly appeared dependency and preserves the error room', async () => {
    const h = setup()
    const room = el('i18n-language-delete-error')
    act(() =>
      h.source.set({ ...language, canDelete: false, deleteReason: 'locales' }),
    )
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      false,
    )
    click('i18n-language-delete-confirm')
    await settle(() => h.refuse(0, "Language 'uk' has locales"))
    expect(el('i18n-language-delete-error')).toBe(room)
    expect(room.textContent).toContain("Language 'uk' has locales")
    expect(el('modal')).not.toBeNull()
    expect(h.deleted).not.toHaveBeenCalled()
  })
  it('keeps an idle vanished source visible and sends nothing', () => {
    const h = setup()
    act(() => h.source.set(null))
    expect(el('i18n-language-delete-notice').textContent).toContain(
      'Deleted elsewhere.',
    )
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      true,
    )
    click('i18n-language-delete-confirm')
    expect(h.calls).toEqual([])
    click('i18n-language-delete-cancel')
    expect(el('modal')).toBeNull()
  })
  it('keeps a refusal after the pending source vanished', async () => {
    const h = setup()
    click('i18n-language-delete-confirm')
    act(() => h.source.set(null))
    await settle(() => h.refuse())
    expect(el('i18n-language-delete-error').textContent).toContain(
      'Server refused',
    )
    expect(el('i18n-language-delete-notice').textContent).toContain(
      'Deleted elsewhere.',
    )
  })
  it('locks viewer confirmation, follows a live lock and retains Cancel', () => {
    const h = setup(undefined, true)
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      true,
    )
    click('i18n-language-delete-confirm')
    expect(h.calls).toEqual([])
    act(() => h.locked.set(false))
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      false,
    )
    act(() => h.locked.set(true))
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      true,
    )
    click('i18n-language-delete-cancel')
    expect(el('modal')).toBeNull()
  })
  it('ignores late success after unmount', async () => {
    const h = setup()
    click('i18n-language-delete-confirm')
    h.view.unmount()
    await settle(() => h.succeed())
    expect(h.deleted).not.toHaveBeenCalled()
  })
})
