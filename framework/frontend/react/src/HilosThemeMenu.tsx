// HilosThemeMenu — the shell's theme icon (HIL-1440, the React port of HIL-1433).
// It reads hilosThemeChoice and draws nothing when that signal is null (switching
// is off). The icon is the current position — sun, moon-stars or circle-half —
// named "Theme: Light" and so on; the menu is Light · Dark · System, then Use site
// default. The current position is checked, with a "default" badge when there is
// no personal pick. Open/close, outside-click, Escape and arrow-roving mirror
// HilosNotificationBell, and like it the menu is rendered only while open. The
// positions are menuitemradio and the reset is menuitem, not a listbox:
// HilosDropdown is a full-width select with a caret, and this control is a bare
// icon. A guest's choice is applied and remembered immediately; an account choice
// waits for the server's handshake. Both close and return focus. The shell mounts
// it; a project passes nothing. Bootstrap classes only, no CSS of its own.
import {
  formatHilosThemeLabel,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosImpersonation,
  hilosSignedIn,
  hilosThemeChoice,
  sendHilosThemePick,
  setHilosThemePick,
  THEME_COPY,
  THEME_POSITIONS,
  type ThemePick,
} from '@hilos/core/base'
import { useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'

import { useSignal } from './useSignal.js'
import { useTrackedAction } from './useTrackedAction.js'

/**
 * Whether the theme can be chosen at this moment, read from the live signals
 * rather than from the last render: a click can reach a row React has not yet
 * removed.
 */
function switchable(): boolean {
  return hilosThemeChoice.get() !== null && hilosImpersonation.get() === null
}

/**
 * The header's theme icon and its menu of three positions and the reset.
 */
export function HilosThemeMenu() {
  const choice = useSignal(hilosThemeChoice)
  const signedIn = useSignal(hilosSignedIn)
  const impersonation = useSignal(hilosImpersonation)
  const action = useTrackedAction()
  const busy = action.busy

  const [open, setOpen] = useState(false)
  const menuId = useId()
  const root = useRef<HTMLDivElement>(null)
  const menu = useRef<HTMLDivElement>(null)
  const toggleButton = useRef<HTMLButtonElement>(null)
  const focusFrame = useRef<number | null>(null)
  // React state lags a render: a second click in the same tick would reach
  // sendHilosThemePick before `busy` turns true, so the send guards itself.
  const sending = useRef(false)
  const wasBusy = useRef(false)

  function cancelFocusFrame(): void {
    if (focusFrame.current !== null) {
      cancelAnimationFrame(focusFrame.current)
      focusFrame.current = null
    }
  }

  function itemButtons(): HTMLButtonElement[] {
    return menu.current
      ? Array.from(
          menu.current.querySelectorAll<HTMLButtonElement>(
            '[role="menuitemradio"], [role="menuitem"]',
          ),
        )
      : []
  }

  function focusItem(which: 'first' | 'last'): void {
    const buttons = itemButtons()
    const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
    target?.focus()
  }

  function openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
    if (sending.current || !switchable()) {
      return
    }
    setOpen(true)
    if (focus !== 'none') {
      // The menu mounts on this tick; focus after paint.
      cancelFocusFrame()
      focusFrame.current = requestAnimationFrame(() => {
        focusFrame.current = null
        focusItem(focus)
      })
    }
  }

  function close(returnFocus = false): void {
    if (!open) {
      return
    }
    cancelFocusFrame()
    setOpen(false)
    if (returnFocus) {
      toggleButton.current?.focus()
    }
  }

  function moveFocus(delta: 1 | -1): void {
    const buttons = itemButtons()
    if (buttons.length === 0) {
      return
    }
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
    buttons[next]?.focus()
  }

  function onToggleKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      openMenu('first')
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      openMenu('last')
    } else if (event.key === 'Escape') {
      event.preventDefault()
      close(true)
    }
  }

  function onMenuKeydown(event: KeyboardEvent): void {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        moveFocus(1)
        break
      case 'ArrowUp':
        event.preventDefault()
        moveFocus(-1)
        break
      case 'Home':
        event.preventDefault()
        focusItem('first')
        break
      case 'End':
        event.preventDefault()
        focusItem('last')
        break
      case 'Escape':
        event.preventDefault()
        close(true)
        break
    }
  }

  async function send(pick: ThemePick): Promise<void> {
    sending.current = true
    try {
      await action.run(sendHilosThemePick(pick))
    } finally {
      sending.current = false
    }
  }

  function choose(pick: ThemePick): void {
    close(true)
    if (sending.current || !switchable()) {
      return
    }
    if (hilosSignedIn.get()) {
      void send(pick)
    } else {
      setHilosThemePick(pick)
    }
  }

  // Close on an outside click while open (the SDK ships Bootstrap CSS, not its
  // JS, so the dropdown owns its own dismissal like HilosNotificationBell).
  useEffect(() => {
    if (!open) {
      return
    }
    const onDocumentClick = (event: MouseEvent): void => {
      if (root.current && !root.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('click', onDocumentClick)

    return () => document.removeEventListener('click', onDocumentClick)
  }, [open])

  // The control leaves the header when switching turns off; an open menu leaves
  // with it and must not still be open if the icon comes back.
  useEffect(() => {
    if (choice === null) {
      cancelFocusFrame()
      setOpen(false)
    }
  }, [choice])

  // A sign-in, a sign-out or a takeover changes who the menu writes for.
  useEffect(() => {
    close(true)
  }, [signedIn, impersonation])

  // Native disabled removes focus during the action. Return it only after React
  // has committed the re-enabled button, while the control is still available.
  useEffect(() => {
    const previous = wasBusy.current
    wasBusy.current = busy
    if (busy || !previous || !hilosSignedIn.get() || !switchable()) {
      return
    }
    const button = toggleButton.current
    if (button?.isConnected && !button.disabled) {
      button.focus()
    }
  }, [busy])

  useEffect(() => cancelFocusFrame, [])

  if (choice === null) {
    return null
  }
  const icon =
    THEME_POSITIONS.find((row) => row.value === choice.position)?.icon ?? ''
  const label = formatHilosThemeLabel(choice.position)

  return (
    <div ref={root} className="dropdown">
      <button
        ref={toggleButton}
        type="button"
        className="btn btn-link nav-link d-inline-flex align-items-center p-0 fs-5 position-relative"
        disabled={busy || impersonation !== null}
        aria-describedby={
          impersonation ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined
        }
        aria-busy={busy || undefined}
        aria-expanded={open}
        aria-controls={menuId}
        aria-haspopup="menu"
        aria-label={label}
        title={label}
        data-id="nav-theme"
        data-position={choice.position}
        onClick={() => (open ? close() : openMenu())}
        onKeyDown={onToggleKeydown}
      >
        <i
          className={`bi ${icon}${action.loading ? ' invisible' : ''}`}
          aria-hidden="true"
        />
        {action.loading && (
          <span
            className="position-absolute top-50 start-50 translate-middle spinner-border spinner-border-sm"
            role="status"
            data-id="nav-theme-loading"
          >
            <span className="visually-hidden">Loading…</span>
          </span>
        )}
      </button>
      {open && (
        <div
          id={menuId}
          ref={menu}
          className="dropdown-menu dropdown-menu-end show"
          role="menu"
          aria-label={THEME_COPY.menu}
          data-id="nav-theme-menu"
          onKeyDown={onMenuKeydown}
        >
          {THEME_POSITIONS.map((row) => (
            <button
              key={row.value}
              type="button"
              className={`dropdown-item d-flex align-items-center gap-2${row.value === choice.position ? ' active' : ''}`}
              role="menuitemradio"
              aria-checked={row.value === choice.position}
              disabled={busy}
              data-id={`nav-theme-option-${row.value}`}
              onClick={() => choose(row.value)}
            >
              <i className={`bi ${row.icon}`} aria-hidden="true" />
              <span className="flex-grow-1">{row.label}</span>
              {row.value === choice.position && choice.isDefault && (
                <span className="badge rounded-pill bg-body-tertiary text-body-emphasis border">
                  {THEME_COPY.default}
                </span>
              )}
              {row.value === choice.position && (
                <i className="bi bi-check2" aria-hidden="true" />
              )}
            </button>
          ))}
          <hr className="dropdown-divider" role="separator" />
          <button
            type="button"
            className="dropdown-item"
            role="menuitem"
            disabled={busy}
            data-id="nav-theme-default"
            onClick={() => choose(null)}
          >
            {THEME_COPY.siteDefault}
          </button>
        </div>
      )}
    </div>
  )
}
