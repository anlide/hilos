// HilosDropdown — a tier-1 single-select dropdown on Bootstrap's `.dropdown`
// markup with its own open/close logic (the SDK ships Bootstrap CSS, not its JS,
// so toggling and outside-click are owned here, like HilosModal). It is
// render-prop-first: `toggle` (context: label / selected / open) replaces the
// button face and `option` (context: option / selected / select) replaces each
// item, so a project customizes the look by filling those, never by
// re-implementing the behavior. The selection is a controlled pair — `value` in,
// `onChange` out. Outside-click and Escape close it, arrow keys rove the
// options, and the listbox/option ARIA roles ship by default (a11y is v1,
// styling-rules.md). Exported as part of the public SDK surface (index.ts) and
// kept intentionally: no in-repo consumer mounts one today, but it stays a
// tier-1 building block for any catalog/option select — live API, not dead code.
// The primitive ships in all three view layers at parity; what differs is only
// the form the look is substituted through — render props here, slots in Vue,
// projected templates in Angular (multiframework-core.md).
// Bootstrap classes only — no CSS of its own.
import { useEffect, useId, useRef, useState, type ReactNode } from 'react'

import type { HilosDropdownOption } from './hilosDropdown.js'

/** Props for {@link HilosDropdown}. */
export interface HilosDropdownProps<V extends string | number> {
  /** The selected option's value; null when nothing is chosen. */
  value: V | null
  /** The selectable options, in display order. */
  options: HilosDropdownOption<V>[]
  /** Called with the chosen option's value; only a selection fires it. */
  onChange: (value: V) => void
  /** Toggle label shown when no option is selected. */
  placeholder?: string
  /** Disable the whole control. */
  disabled?: boolean
  /** Accessible name for the options menu. */
  menuAriaLabel?: string
  /** Message shown inside the menu when there are no options. */
  emptyText?: string
  /** Replace the toggle's face; the context mirrors Vue's `#toggle` slot scope. */
  toggle?: (context: {
    label: string
    selected: HilosDropdownOption<V> | null
    open: boolean
  }) => ReactNode
  /** Replace one option's row; the context mirrors Vue's `#option` slot scope. */
  option?: (context: {
    option: HilosDropdownOption<V>
    selected: boolean
    select: () => void
  }) => ReactNode
}

/**
 * The single-select dropdown: a toggle button over a listbox of options.
 *
 * @param props The controlled selection, the options, and the label / empty /
 *   substitution config.
 */
export function HilosDropdown<V extends string | number>({
  value,
  options,
  onChange,
  placeholder = 'Select…',
  disabled = false,
  menuAriaLabel = 'Options',
  emptyText = 'No options',
  toggle,
  option,
}: HilosDropdownProps<V>) {
  const [open, setOpen] = useState(false)
  const menuId = useId()
  const root = useRef<HTMLDivElement>(null)
  const menu = useRef<HTMLUListElement>(null)
  const toggleButton = useRef<HTMLButtonElement>(null)

  const selected = options.find((each) => each.value === value) ?? null
  const label = selected?.label ?? placeholder

  // Close on an outside click while open (the SDK ships Bootstrap CSS, not its
  // JS, so the dropdown owns its own dismissal like HilosModal).
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

  function optionButtons(): HTMLButtonElement[] {
    return menu.current
      ? Array.from(
          menu.current.querySelectorAll<HTMLButtonElement>(
            '.dropdown-item:not(:disabled)',
          ),
        )
      : []
  }

  function focusOption(which: 'first' | 'last'): void {
    const buttons = optionButtons()
    const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
    target?.focus()
  }

  function openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
    if (disabled) {
      return
    }
    setOpen(true)
    if (focus !== 'none') {
      // The menu is already in the DOM; focus after the open paints, the way the
      // bell does — React has no nextTick.
      requestAnimationFrame(() => focusOption(focus))
    }
  }

  function close(returnFocus = false): void {
    if (!open) {
      return
    }
    setOpen(false)
    if (returnFocus) {
      toggleButton.current?.focus()
    }
  }

  function toggleMenu(): void {
    if (disabled) {
      return
    }
    if (open) {
      close()
    } else {
      openMenu()
    }
  }

  function select(chosen: HilosDropdownOption<V>): void {
    if (chosen.disabled) {
      return
    }
    onChange(chosen.value)
    close(true)
  }

  function moveFocus(delta: 1 | -1): void {
    const buttons = optionButtons()
    if (buttons.length === 0) {
      return
    }
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
    buttons[next]?.focus()
  }

  function onToggleKeydown(event: React.KeyboardEvent): void {
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

  function onMenuKeydown(event: React.KeyboardEvent): void {
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
        focusOption('first')
        break
      case 'End':
        event.preventDefault()
        focusOption('last')
        break
      case 'Escape':
        event.preventDefault()
        close(true)
        break
    }
  }

  return (
    <div ref={root} className="dropdown" data-id="hilos-dropdown">
      <button
        ref={toggleButton}
        type="button"
        className="btn btn-outline-secondary dropdown-toggle d-flex align-items-center justify-content-between w-100"
        disabled={disabled}
        aria-expanded={open}
        aria-controls={menuId}
        aria-haspopup="listbox"
        data-id="hilos-dropdown-toggle"
        onClick={toggleMenu}
        onKeyDown={onToggleKeydown}
      >
        {toggle ? (
          toggle({ label, selected, open })
        ) : (
          <span className="text-truncate">{label}</span>
        )}
      </button>
      <ul
        id={menuId}
        ref={menu}
        className={`dropdown-menu w-100${open ? ' show' : ''}`}
        role="listbox"
        aria-label={menuAriaLabel}
        data-id="hilos-dropdown-menu"
        onKeyDown={onMenuKeydown}
      >
        {options.map((item) => (
          <li key={String(item.value)}>
            {option ? (
              option({
                option: item,
                selected: item.value === value,
                select: () => select(item),
              })
            ) : (
              <button
                type="button"
                className={`dropdown-item d-flex align-items-center justify-content-between gap-2${
                  item.value === value ? ' active' : ''
                }`}
                disabled={item.disabled}
                role="option"
                aria-selected={item.value === value}
                data-id={`hilos-dropdown-option-${item.value}`}
                onClick={() => select(item)}
              >
                <span className="text-truncate">{item.label}</span>
                {item.value === value && (
                  <i
                    className="bi bi-check2 flex-shrink-0"
                    aria-hidden="true"
                  />
                )}
              </button>
            )}
          </li>
        ))}
        {options.length === 0 && (
          <li>
            <span
              className="dropdown-item disabled text-body-secondary"
              data-id="hilos-dropdown-empty"
            >
              {emptyText}
            </span>
          </li>
        )}
      </ul>
    </div>
  )
}
