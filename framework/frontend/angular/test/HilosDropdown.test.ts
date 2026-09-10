// The Angular port of the tier-1 dropdown, under the same nine case names the
// Vue reference and the React port run (HIL-934). Every case mounts a host
// rather than the component itself: the selection is a two-way binding and the
// look is filled by projection, and neither can be handed to a component created
// directly.
//
// The wiring these cases run on — the DOM environment, the TestBed platform, and
// the module reset between cases — lives in the package's vitest.setup.ts and was
// laid down by HIL-848; this file configures none of it.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosDropdown } from '../src/HilosDropdown.js'
import type { HilosDropdownOption } from '../src/hilosDropdownOption.js'

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

/** A host holding the two-way selection the dropdown writes back into. */
@Component({
  selector: 'test-dropdown-host',
  imports: [HilosDropdown],
  template: `
    <hilos-dropdown
      [options]="options"
      [(value)]="value"
      [emptyText]="emptyText"
    />
  `,
})
class DropdownHost {
  options: HilosDropdownOption<string>[] = OPTIONS
  value: string | null = null
  emptyText = 'No options'
}

/** A host that fills both projected templates, for the substitution case. */
@Component({
  selector: 'test-dropdown-face-host',
  imports: [HilosDropdown],
  template: `
    <hilos-dropdown [options]="options" [(value)]="value">
      <ng-template #toggle let-label>
        <span data-id="test-toggle">{{ label }}</span>
      </ng-template>
      <ng-template #option let-option let-select="select">
        <button
          type="button"
          [attr.data-id]="'test-option-' + option.value"
          (click)="select()"
        >
          {{ option.label }}
        </button>
      </ng-template>
    </hilos-dropdown>
  `,
})
class DropdownFaceHost {
  options: HilosDropdownOption<string>[] = OPTIONS
  value: string | null = null
}

function byId(fixture: ComponentFixture<unknown>, id: string): HTMLElement {
  return fixture.nativeElement.querySelector(`[data-id="${id}"]`) as HTMLElement
}

/**
 * The option buttons of the mounted dropdown, in display order.
 *
 * The element is named too, because the empty row wears `.dropdown-item` as
 * well and it is a span rather than an option.
 *
 * @param fixture The mounted host.
 * @returns The buttons, including the ones that are disabled.
 */
function optionButtons(
  fixture: ComponentFixture<unknown>,
): HTMLButtonElement[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>(
      'button.dropdown-item',
    ),
  )
}

/**
 * Let the frame the dropdown focuses an option in go by.
 *
 * Focus after opening is set in requestAnimationFrame, so a case that presses a
 * key and then reads document.activeElement has to wait for one.
 *
 * @param fixture The mounted host, checked again once the frame has passed.
 * @returns A promise resolved after the next animation frame.
 */
async function nextFrame(fixture: ComponentFixture<unknown>): Promise<void> {
  await new Promise((resolve) => requestAnimationFrame(() => resolve(null)))
  fixture.detectChanges()
}

/**
 * Mount the plain host with the options a case needs.
 *
 * @param options The options the dropdown draws.
 * @param emptyText The empty-menu message, when the case sets it.
 * @returns The mounted fixture, already checked once.
 */
function mountHost(
  options: HilosDropdownOption<string>[],
  emptyText?: string,
): ComponentFixture<DropdownHost> {
  const fixture = TestBed.createComponent(DropdownHost)
  fixture.componentInstance.options = options
  if (emptyText !== undefined) {
    fixture.componentInstance.emptyText = emptyText
  }
  fixture.detectChanges()

  return fixture
}

describe('HilosDropdown', () => {
  it('links the toggle to the menu and exposes the listbox ARIA', () => {
    const fixture = mountHost(OPTIONS)

    const toggle = byId(fixture, 'hilos-dropdown-toggle')
    const menu = byId(fixture, 'hilos-dropdown-menu')

    // aria-controls on the toggle resolves to the listbox's id.
    const menuId = menu.getAttribute('id')
    expect(menuId).toBeTruthy()
    expect(toggle.getAttribute('aria-controls')).toBe(menuId)

    expect(toggle.getAttribute('aria-haspopup')).toBe('listbox')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(menu.getAttribute('role')).toBe('listbox')
  })

  it('opens on the toggle and closes on the next click', () => {
    const fixture = mountHost(OPTIONS)
    const toggle = byId(fixture, 'hilos-dropdown-toggle')

    toggle.click()
    fixture.detectChanges()
    expect(toggle.getAttribute('aria-expanded')).toBe('true')

    toggle.click()
    fixture.detectChanges()
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
  })

  it('closes on Escape and returns focus to the toggle', async () => {
    const fixture = mountHost(OPTIONS)
    const toggle = byId(fixture, 'hilos-dropdown-toggle')
    const menu = byId(fixture, 'hilos-dropdown-menu')

    toggle.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
    )
    fixture.detectChanges()
    await nextFrame(fixture)
    expect(document.activeElement).toBe(optionButtons(fixture)[0])

    menu.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    fixture.detectChanges()
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle)
  })

  it('closes on a click outside the dropdown', () => {
    const fixture = mountHost(OPTIONS)
    const toggle = byId(fixture, 'hilos-dropdown-toggle')
    const menu = byId(fixture, 'hilos-dropdown-menu')

    toggle.click()
    fixture.detectChanges()

    // The listener sits on the document, so the case dispatches a real event
    // that bubbles up to it.
    menu.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    fixture.detectChanges()
    // A click that lands inside the dropdown is not an outside click.
    expect(toggle.getAttribute('aria-expanded')).toBe('true')

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    fixture.detectChanges()
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
  })

  it('roves the options with the arrow keys and Home/End', async () => {
    const fixture = mountHost(ROVING_OPTIONS)
    const toggle = byId(fixture, 'hilos-dropdown-toggle')
    const menu = byId(fixture, 'hilos-dropdown-menu')

    toggle.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
    )
    fixture.detectChanges()
    await nextFrame(fixture)

    const buttons = optionButtons(fixture)
    expect(document.activeElement).toBe(buttons[0])

    const press = (key: string): void => {
      menu.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
      fixture.detectChanges()
    }

    press('ArrowDown')
    expect(document.activeElement).toBe(buttons[1])

    press('ArrowUp')
    expect(document.activeElement).toBe(buttons[0])

    // The ring: up from the first option lands on the last.
    press('ArrowUp')
    expect(document.activeElement).toBe(buttons[2])

    press('Home')
    expect(document.activeElement).toBe(buttons[0])

    press('End')
    expect(document.activeElement).toBe(buttons[2])
  })

  it('emits the chosen value, marks it selected, and closes', () => {
    const fixture = mountHost(OPTIONS)
    const toggle = byId(fixture, 'hilos-dropdown-toggle')

    toggle.click()
    fixture.detectChanges()
    byId(fixture, 'hilos-dropdown-option-b').click()
    fixture.detectChanges()

    // The two-way binding writes the choice back into the host.
    expect(fixture.componentInstance.value).toBe('b')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle)

    expect(
      byId(fixture, 'hilos-dropdown-option-b').getAttribute('aria-selected'),
    ).toBe('true')
    expect(
      byId(fixture, 'hilos-dropdown-option-a').getAttribute('aria-selected'),
    ).toBe('false')
  })

  it('keeps a disabled option out of the selection and out of the roving', async () => {
    const fixture = mountHost(OPTIONS_WITH_DISABLED)
    const toggle = byId(fixture, 'hilos-dropdown-toggle')
    const menu = byId(fixture, 'hilos-dropdown-menu')

    toggle.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
    )
    fixture.detectChanges()
    await nextFrame(fixture)

    const buttons = optionButtons(fixture)
    expect(document.activeElement).toBe(buttons[0])

    // Beta sits between the two, and the roving steps straight over it.
    menu.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
    )
    fixture.detectChanges()
    expect(document.activeElement).toBe(buttons[2])

    byId(fixture, 'hilos-dropdown-option-b').click()
    fixture.detectChanges()
    expect(fixture.componentInstance.value).toBeNull()
  })

  it('shows the empty text when there are no options', () => {
    const fixture = mountHost([], 'Nothing to pick')

    expect(byId(fixture, 'hilos-dropdown-empty').textContent).toBe(
      'Nothing to pick',
    )
    expect(optionButtons(fixture)).toHaveLength(0)
  })

  it('replaces the toggle face and the option row through the projected templates', () => {
    const fixture = TestBed.createComponent(DropdownFaceHost)
    fixture.detectChanges()

    expect(byId(fixture, 'test-toggle').textContent?.trim()).toBe('Select…')

    byId(fixture, 'test-option-a').click()
    fixture.detectChanges()
    expect(fixture.componentInstance.value).toBe('a')
  })
})
