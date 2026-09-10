// HilosDropdown — a tier-1 single-select dropdown on Bootstrap's `.dropdown`
// markup with its own open/close logic (the SDK ships Bootstrap CSS, not its JS,
// so toggling and outside-click are owned here, like HilosModal). It is
// projection-first: `#toggle` (context: label / selected / open) replaces the
// button face and `#option` (context: option / selected / select) replaces each
// item, so a project customizes the look by filling those templates, never by
// re-implementing the behavior. The selection is the two-way `value` binding.
// Outside-click and Escape close it, arrow keys rove the options, and the
// listbox/option ARIA roles ship by default (a11y is v1, styling-rules.md).
// Exported as part of the public SDK surface (index.ts) and kept intentionally:
// no in-repo consumer mounts one today, but it stays a tier-1 building block for
// any catalog/option select — live API, not dead code.
// The primitive ships in all three view layers at parity; what differs is only
// the form the look is substituted through — projected templates here, slots in
// Vue, render props in React (multiframework-core.md).
// Bootstrap classes only — no CSS of its own.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  TemplateRef,
  computed,
  contentChild,
  inject,
  input,
  model,
  signal,
  viewChild,
} from '@angular/core'

import type { HilosDropdownOption } from './hilosDropdownOption.js'

// Distinct ids so two dropdowns on one page never share an `aria-controls`
// target.
let dropdownMenuSeq = 0

/** The context a HilosDropdown `#toggle` template receives. */
export interface DropdownToggleContext<V extends string | number> {
  /** The label the default face would show (the template's implicit `let-label`). */
  $implicit: string
  /** The selected option, or null when nothing is chosen. */
  selected: HilosDropdownOption<V> | null
  /** Whether the menu is open. */
  open: boolean
}

/** The context a HilosDropdown `#option` template receives. */
export interface DropdownOptionContext<V extends string | number> {
  /** The option this row draws (the template's implicit `let-option`). */
  $implicit: HilosDropdownOption<V>
  /** Whether this option is the selected one. */
  selected: boolean
  /** Choose this option; a disabled one stays unchosen. */
  select: () => void
}

/** The single-select dropdown: a toggle button over a listbox of options. */
@Component({
  selector: 'hilos-dropdown',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  template: `
    <div class="dropdown" data-id="hilos-dropdown">
      <button
        #toggleButton
        type="button"
        class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center justify-content-between w-100"
        [disabled]="disabled()"
        [attr.aria-expanded]="open()"
        [attr.aria-controls]="menuId"
        aria-haspopup="listbox"
        data-id="hilos-dropdown-toggle"
        (click)="onToggleClick()"
        (keydown.arrowdown)="onToggleArrow($event, 'first')"
        (keydown.arrowup)="onToggleArrow($event, 'last')"
        (keydown.escape)="onEscape($event)"
      >
        @if (toggle(); as face) {
          <ng-container
            [ngTemplateOutlet]="face"
            [ngTemplateOutletContext]="{
              $implicit: label(),
              selected: selected(),
              open: open(),
            }"
          />
        } @else {
          <span class="text-truncate">{{ label() }}</span>
        }
      </button>
      <ul
        #menu
        [id]="menuId"
        class="dropdown-menu w-100"
        [class.show]="open()"
        role="listbox"
        [attr.aria-label]="menuAriaLabel()"
        data-id="hilos-dropdown-menu"
        (keydown.arrowdown)="onMenuArrow($event, 1)"
        (keydown.arrowup)="onMenuArrow($event, -1)"
        (keydown.home)="onMenuEdge($event, 'first')"
        (keydown.end)="onMenuEdge($event, 'last')"
        (keydown.escape)="onEscape($event)"
      >
        @for (item of options(); track item.value) {
          <li>
            @if (option(); as row) {
              <ng-container
                [ngTemplateOutlet]="row"
                [ngTemplateOutletContext]="optionContext(item)"
              />
            } @else {
              <button
                type="button"
                class="dropdown-item d-flex align-items-center justify-content-between gap-2"
                [class.active]="item.value === value()"
                [disabled]="item.disabled"
                role="option"
                [attr.aria-selected]="item.value === value()"
                [attr.data-id]="'hilos-dropdown-option-' + item.value"
                (click)="select(item)"
              >
                <span class="text-truncate">{{ item.label }}</span>
                @if (item.value === value()) {
                  <i class="bi bi-check2 flex-shrink-0" aria-hidden="true"></i>
                }
              </button>
            }
          </li>
        }
        @if (options().length === 0) {
          <li>
            <span
              class="dropdown-item disabled text-body-secondary"
              data-id="hilos-dropdown-empty"
              >{{ emptyText() }}</span
            >
          </li>
        }
      </ul>
    </div>
  `,
})
export class HilosDropdown<V extends string | number> {
  /** The selectable options, in display order. */
  readonly options = input.required<HilosDropdownOption<V>[]>()
  /** The selected option's value; null when nothing is chosen. */
  readonly value = model<V | null>(null)
  /** Toggle label shown when no option is selected. */
  readonly placeholder = input('Select…')
  /** Disable the whole control. */
  readonly disabled = input(false)
  /** Accessible name for the options menu. */
  readonly menuAriaLabel = input('Options')
  /** Message shown inside the menu when there are no options. */
  readonly emptyText = input('No options')

  protected readonly toggle =
    contentChild<TemplateRef<DropdownToggleContext<V>>>('toggle')
  protected readonly option =
    contentChild<TemplateRef<DropdownOptionContext<V>>>('option')

  protected readonly open = signal(false)
  protected readonly menuId = `hilos-dropdown-menu-${dropdownMenuSeq++}`

  protected readonly selected = computed(
    () => this.options().find((each) => each.value === this.value()) ?? null,
  )
  protected readonly label = computed(
    () => this.selected()?.label ?? this.placeholder(),
  )

  private readonly toggleButton =
    viewChild<ElementRef<HTMLButtonElement>>('toggleButton')
  private readonly menu = viewChild<ElementRef<HTMLUListElement>>('menu')
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef)

  // Close on an outside click while open (the SDK ships Bootstrap CSS, not its
  // JS, so the dropdown owns its own dismissal like HilosModal).
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (
      this.open() &&
      !this.host.nativeElement.contains(event.target as Node)
    ) {
      this.open.set(false)
    }
  }

  /**
   * The context one projected option row is drawn with.
   *
   * It is built here rather than inline in the template because `select` is a
   * closure over the option, and a template expression cannot write one.
   *
   * @param item The option the row draws.
   * @returns The context the `#option` template receives.
   */
  protected optionContext(
    item: HilosDropdownOption<V>,
  ): DropdownOptionContext<V> {
    return {
      $implicit: item,
      selected: item.value === this.value(),
      select: () => this.select(item),
    }
  }

  protected onToggleClick(): void {
    if (this.disabled()) {
      return
    }
    this.open.set(!this.open())
  }

  protected onToggleArrow(event: Event, which: 'first' | 'last'): void {
    event.preventDefault()
    if (this.disabled()) {
      return
    }
    this.open.set(true)
    // The menu is already in the DOM; focus after the open paints, the way the
    // bell does — Angular has no nextTick.
    requestAnimationFrame(() => this.focusOption(which))
  }

  protected onEscape(event: Event): void {
    event.preventDefault()
    this.close(true)
  }

  protected onMenuArrow(event: Event, delta: 1 | -1): void {
    event.preventDefault()
    const buttons = this.optionButtons()
    if (buttons.length === 0) {
      return
    }
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
    buttons[next]?.focus()
  }

  protected onMenuEdge(event: Event, which: 'first' | 'last'): void {
    event.preventDefault()
    this.focusOption(which)
  }

  protected select(chosen: HilosDropdownOption<V>): void {
    if (chosen.disabled) {
      return
    }
    this.value.set(chosen.value)
    this.close(true)
  }

  private close(returnFocus = false): void {
    if (!this.open()) {
      return
    }
    this.open.set(false)
    if (returnFocus) {
      this.toggleButton()?.nativeElement.focus()
    }
  }

  private optionButtons(): HTMLButtonElement[] {
    const menu = this.menu()
    return menu
      ? Array.from(
          menu.nativeElement.querySelectorAll<HTMLButtonElement>(
            '.dropdown-item:not(:disabled)',
          ),
        )
      : []
  }

  private focusOption(which: 'first' | 'last'): void {
    const buttons = this.optionButtons()
    const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
    target?.focus()
  }
}
