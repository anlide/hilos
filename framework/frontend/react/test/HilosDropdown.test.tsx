import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi, onTestFinished } from 'vitest'

import { HilosDropdown } from '../src/HilosDropdown.js'
import type { HilosDropdownOption } from '../src/hilosDropdown.js'

const OPTIONS: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
]

// Three options, because the roving cases have to tell "the next one" apart
// from "the last one", and with two of them those are the same node.
const ROVING_OPTIONS: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
  { value: 'c', label: 'Gamma' },
]

const OPTIONS_WITH_DISABLED: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta', disabled: true },
  { value: 'c', label: 'Gamma' },
]

afterEach(() => cleanup())

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/**
 * The option buttons of the rendered dropdown, in display order.
 *
 * The element is named too, because the empty row wears `.dropdown-item` as
 * well and it is a span rather than an option.
 *
 * @returns The buttons, including the ones that are disabled.
 */
function optionButtons(): HTMLButtonElement[] {
  return Array.from(
    document.querySelectorAll<HTMLButtonElement>('button.dropdown-item'),
  )
}

/**
 * Let the frame the dropdown focuses an option in go by.
 *
 * Focus after opening is set in requestAnimationFrame, so a case that presses a
 * key and then reads document.activeElement has to wait for one.
 *
 * @returns A promise resolved after the next animation frame.
 */
async function nextFrame(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => requestAnimationFrame(() => resolve(null)))
  })
}

describe('HilosDropdown', () => {
  it('links the toggle to the menu and exposes the listbox ARIA', () => {
    render(<HilosDropdown value={null} options={OPTIONS} onChange={vi.fn()} />)

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    const menu = byId('hilos-dropdown-menu') as HTMLElement

    // aria-controls on the toggle resolves to the listbox's id.
    const menuId = menu.getAttribute('id')
    expect(menuId).toBeTruthy()
    expect(toggle.getAttribute('aria-controls')).toBe(menuId)

    expect(toggle.getAttribute('aria-haspopup')).toBe('listbox')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(menu.getAttribute('role')).toBe('listbox')

    // The listbox owns options, not listitems: every wrapping <li> is presentational.
    const items = Array.from(menu.querySelectorAll(':scope > li'))
    expect(items).toHaveLength(OPTIONS.length)
    for (const item of items) {
      expect(item.getAttribute('role')).toBe('presentation')
    }
  })

  it('opens on the toggle and closes on the next click', () => {
    render(<HilosDropdown value={null} options={OPTIONS} onChange={vi.fn()} />)

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement

    fireEvent.click(toggle)
    expect(toggle.getAttribute('aria-expanded')).toBe('true')

    fireEvent.click(toggle)
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
  })

  it('closes on Escape and returns focus to the toggle', async () => {
    render(<HilosDropdown value={null} options={OPTIONS} onChange={vi.fn()} />)

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    const menu = byId('hilos-dropdown-menu') as HTMLElement

    fireEvent.keyDown(toggle, { key: 'ArrowDown' })
    await nextFrame()
    expect(document.activeElement).toBe(optionButtons()[0])

    fireEvent.keyDown(menu, { key: 'Escape' })
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle)
  })

  it('closes on a click outside the dropdown', async () => {
    render(<HilosDropdown value={null} options={OPTIONS} onChange={vi.fn()} />)

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    const menu = byId('hilos-dropdown-menu') as HTMLElement

    fireEvent.click(toggle)
    // The listener sits on the document, so the case dispatches a real event
    // rather than a synthetic one through the React tree.
    await act(async () => {
      menu.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })
    // A click that lands inside the dropdown is not an outside click.
    expect(toggle.getAttribute('aria-expanded')).toBe('true')

    await act(async () => {
      document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
  })

  it('roves the options with the arrow keys and Home/End', async () => {
    render(
      <HilosDropdown
        value={null}
        options={ROVING_OPTIONS}
        onChange={vi.fn()}
      />,
    )

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    const menu = byId('hilos-dropdown-menu') as HTMLElement

    fireEvent.keyDown(toggle, { key: 'ArrowDown' })
    await nextFrame()
    const buttons = optionButtons()
    expect(document.activeElement).toBe(buttons[0])

    fireEvent.keyDown(menu, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[1])

    fireEvent.keyDown(menu, { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[0])

    // The ring: up from the first option lands on the last.
    fireEvent.keyDown(menu, { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[2])

    fireEvent.keyDown(menu, { key: 'Home' })
    expect(document.activeElement).toBe(buttons[0])

    fireEvent.keyDown(menu, { key: 'End' })
    expect(document.activeElement).toBe(buttons[2])
  })

  it('roves only the options on display, around the ring and on Home/End', async () => {
    render(
      <HilosDropdown
        value={null}
        options={[
          { value: 'a', label: 'Alpha' },
          { value: 'b', label: 'Beta' },
          { value: 'c', label: 'Gamma' },
          { value: 'd', label: 'Delta' },
          { value: 'e', label: 'Epsilon' },
        ]}
        onChange={vi.fn()}
      />,
    )

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    const menu = byId('hilos-dropdown-menu') as HTMLElement
    const buttons = optionButtons()
    // A width hides an option by a class of its own; the walk must not stop on it.
    // The sheet stands in for Bootstrap's, which the test document does not load.
    const sheet = document.createElement('style')
    sheet.textContent = '.d-none { display: none !important; }'
    document.head.append(sheet)
    onTestFinished(() => sheet.remove())
    for (const hidden of [buttons[0], buttons[2], buttons[4]]) {
      hidden?.classList.add('d-none')
    }

    fireEvent.keyDown(toggle, { key: 'ArrowDown' })
    await nextFrame()
    expect(document.activeElement).toBe(buttons[1])

    fireEvent.keyDown(menu, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[3])

    // The ring closes over the hidden last and first options alike.
    fireEvent.keyDown(menu, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[1])

    fireEvent.keyDown(menu, { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[3])

    fireEvent.keyDown(menu, { key: 'Home' })
    expect(document.activeElement).toBe(buttons[1])

    fireEvent.keyDown(menu, { key: 'End' })
    expect(document.activeElement).toBe(buttons[3])
  })

  it('emits the chosen value, marks it selected, and closes', () => {
    const onChange = vi.fn()
    const { rerender } = render(
      <HilosDropdown value={null} options={OPTIONS} onChange={onChange} />,
    )

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    fireEvent.click(toggle)
    fireEvent.click(byId('hilos-dropdown-option-b') as HTMLButtonElement)

    expect(onChange.mock.calls).toEqual([['b']])
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle)

    // The selection is controlled; the chosen option is the selected one once
    // the caller writes it back.
    rerender(<HilosDropdown value="b" options={OPTIONS} onChange={onChange} />)
    expect(byId('hilos-dropdown-option-b')?.getAttribute('aria-selected')).toBe(
      'true',
    )
    expect(byId('hilos-dropdown-option-a')?.getAttribute('aria-selected')).toBe(
      'false',
    )
  })

  it('keeps a disabled option out of the selection and out of the roving', async () => {
    const onChange = vi.fn()
    render(
      <HilosDropdown
        value={null}
        options={OPTIONS_WITH_DISABLED}
        onChange={onChange}
      />,
    )

    const toggle = byId('hilos-dropdown-toggle') as HTMLButtonElement
    const menu = byId('hilos-dropdown-menu') as HTMLElement

    fireEvent.keyDown(toggle, { key: 'ArrowDown' })
    await nextFrame()
    const buttons = optionButtons()
    expect(document.activeElement).toBe(buttons[0])

    // Beta sits between the two, and the roving steps straight over it.
    fireEvent.keyDown(menu, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[2])

    fireEvent.click(byId('hilos-dropdown-option-b') as HTMLButtonElement)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('shows the empty text when there are no options', () => {
    render(
      <HilosDropdown
        value={null}
        options={[]}
        onChange={vi.fn()}
        emptyText="Nothing to pick"
      />,
    )

    const empty = byId('hilos-dropdown-empty')
    expect(empty?.textContent).toBe('Nothing to pick')
    expect(empty?.getAttribute('role')).toBe('option')
    expect(empty?.getAttribute('aria-disabled')).toBe('true')
    expect(empty?.parentElement?.getAttribute('role')).toBe('presentation')
    expect(optionButtons()).toHaveLength(0)
  })

  it('replaces the toggle face and the option row through the render props', () => {
    const onChange = vi.fn()
    render(
      <HilosDropdown
        value={null}
        options={OPTIONS}
        onChange={onChange}
        toggle={({ label }) => <span data-id="test-toggle">{label}</span>}
        option={({ option, select }) => (
          <button
            type="button"
            data-id={`test-option-${option.value}`}
            onClick={select}
          >
            {option.label}
          </button>
        )}
      />,
    )

    expect(byId('test-toggle')?.textContent).toBe('Select…')

    fireEvent.click(byId('test-option-a') as HTMLButtonElement)
    expect(onChange.mock.calls).toEqual([['a']])
  })
})
