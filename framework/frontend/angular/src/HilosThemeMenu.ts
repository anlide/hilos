// HilosThemeMenu — the shell's theme icon (HIL-1441, the Angular port of
// HIL-1433). It reads hilosThemeChoice and draws nothing when that signal is
// null (switching is off). The icon is the current position — sun, moon-stars
// or circle-half — named "Theme: Light" and so on; the menu is Light · Dark ·
// System, then Use site default. The current position is checked, with a
// "default" badge when there is no personal pick. Open/close, outside-click,
// Escape and arrow-roving mirror HilosNotificationBell, and like it the menu is
// rendered only while open. The positions are menuitemradio and the reset is
// menuitem, not a listbox: HilosDropdown is a full-width select with a caret,
// and this control is a bare icon. A guest's choice is applied and remembered
// immediately; an account choice waits for the server's handshake. Both close
// and return focus. The shell mounts it; a project passes nothing. Bootstrap
// classes only, no CSS of its own.
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  HostListener,
  Injector,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core'
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
} from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'
import { createHilosTrackedAction } from './hilosTrackedAction.js'

// Distinct ids so two menus on one page never share an `aria-controls` target.
let themeMenuSeq = 0

/**
 * Whether the theme can be chosen at this moment, read from the live signals
 * rather than from the last change detection: a click can reach a row the
 * template has not yet removed.
 */
function switchable(): boolean {
  return hilosThemeChoice.get() !== null && hilosImpersonation.get() === null
}

/**
 * The header's theme icon and its menu of three positions and the reset.
 */
@Component({
  selector: 'hilos-theme-menu',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // With nothing drawn the host still stands among the header's icons, and a
  // flex gap would hold room for it; hidden, it is no flex item at all.
  host: { '[class.d-none]': 'choice() === null' },
  template: `
    @if (view(); as current) {
      <div class="dropdown">
        <button
          #themeToggle
          type="button"
          class="btn btn-link nav-link d-inline-flex align-items-center p-0 fs-5 position-relative"
          [disabled]="busy() || impersonation() !== null"
          [attr.aria-describedby]="impersonation() ? stripTextId : null"
          [attr.aria-busy]="busy() || null"
          [attr.aria-expanded]="open()"
          [attr.aria-controls]="menuId"
          aria-haspopup="menu"
          [attr.aria-label]="current.label"
          [title]="current.label"
          data-id="nav-theme"
          [attr.data-position]="current.position"
          (click)="toggle()"
          (keydown.arrowdown)="onToggleArrow($event, 'first')"
          (keydown.arrowup)="onToggleArrow($event, 'last')"
          (keydown.escape)="onEscape($event)"
        >
          <i
            class="bi"
            [class]="current.icon"
            [class.invisible]="loading()"
            aria-hidden="true"
          ></i>
          @if (loading()) {
            <span
              class="position-absolute top-50 start-50 translate-middle spinner-border spinner-border-sm"
              role="status"
              data-id="nav-theme-loading"
              ><span class="visually-hidden">Loading…</span></span
            >
          }
        </button>
        @if (open()) {
          <div
            #menu
            [id]="menuId"
            class="dropdown-menu dropdown-menu-end show"
            role="menu"
            [attr.aria-label]="copy.menu"
            data-id="nav-theme-menu"
            (keydown)="onMenuKeydown($event)"
          >
            @for (row of positions; track row.value) {
              <button
                type="button"
                class="dropdown-item d-flex align-items-center gap-2"
                [class.active]="row.value === current.position"
                role="menuitemradio"
                [attr.aria-checked]="row.value === current.position"
                [disabled]="busy()"
                [attr.data-id]="'nav-theme-option-' + row.value"
                (click)="choose(row.value)"
              >
                <i class="bi" [class]="row.icon" aria-hidden="true"></i>
                <span class="flex-grow-1">{{ row.label }}</span>
                @if (row.value === current.position && current.isDefault) {
                  <span
                    class="badge rounded-pill bg-body-tertiary text-body-emphasis border"
                    >{{ copy.default }}</span
                  >
                }
                @if (row.value === current.position) {
                  <i class="bi bi-check2" aria-hidden="true"></i>
                }
              </button>
            }
            <hr class="dropdown-divider" role="separator" />
            <button
              type="button"
              class="dropdown-item"
              role="menuitem"
              [disabled]="busy()"
              data-id="nav-theme-default"
              (click)="choose(null)"
            >
              {{ copy.siteDefault }}
            </button>
          </div>
        }
      </div>
    }
  `,
})
export class HilosThemeMenu {
  protected readonly choice = hilosSignal(hilosThemeChoice)
  private readonly signedIn = hilosSignal(hilosSignedIn)
  protected readonly impersonation = hilosSignal(hilosImpersonation)
  protected readonly view = computed(() => {
    const current = this.choice()
    if (current === null) {
      return null
    }

    return {
      position: current.position,
      isDefault: current.isDefault,
      icon:
        THEME_POSITIONS.find((row) => row.value === current.position)?.icon ??
        '',
      label: formatHilosThemeLabel(current.position),
    }
  })
  private readonly action = createHilosTrackedAction()
  protected readonly busy = this.action.busy
  protected readonly loading = this.action.loading
  protected readonly positions = THEME_POSITIONS
  protected readonly copy = THEME_COPY
  protected readonly stripTextId = HILOS_IMPERSONATION_STRIP_TEXT_ID

  protected readonly open = signal(false)
  protected readonly menuId = `hilos-theme-menu-${themeMenuSeq++}`

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef)
  private readonly injector = inject(Injector)
  private readonly toggleButton =
    viewChild<ElementRef<HTMLButtonElement>>('themeToggle')
  private readonly menu = viewChild<ElementRef<HTMLElement>>('menu')
  private destroyed = false

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true
    })
    // The control leaves the header when switching turns off; an open menu
    // leaves with it and must not still be open if the icon comes back.
    effect(() => {
      if (this.choice() === null) {
        untracked(() => this.open.set(false))
      }
    })
    // A sign-in, a sign-out or a takeover changes who the menu writes for.
    effect(() => {
      this.signedIn()
      this.impersonation()
      untracked(() => this.close(true))
    })
  }

  // Close on an outside click while open (the SDK ships Bootstrap CSS, not its
  // JS, so the dropdown owns its own dismissal like HilosNotificationBell).
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (
      this.open() &&
      !this.host.nativeElement.contains(event.target as Node)
    ) {
      this.open.set(false)
    }
  }

  protected toggle(): void {
    if (this.open()) {
      this.close()
    } else {
      this.openMenu()
    }
  }

  protected onToggleArrow(event: Event, which: 'first' | 'last'): void {
    event.preventDefault()
    this.openMenu(which)
  }

  protected onEscape(event: Event): void {
    event.preventDefault()
    this.close(true)
  }

  protected onMenuKeydown(event: KeyboardEvent): void {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        this.moveFocus(1)
        break
      case 'ArrowUp':
        event.preventDefault()
        this.moveFocus(-1)
        break
      case 'Home':
        event.preventDefault()
        this.focusItem('first')
        break
      case 'End':
        event.preventDefault()
        this.focusItem('last')
        break
      case 'Escape':
        this.onEscape(event)
        break
    }
  }

  protected choose(pick: ThemePick): void {
    this.close(true)
    if (this.action.busy() || !switchable()) {
      return
    }
    if (hilosSignedIn.get()) {
      void this.send(pick)
    } else {
      setHilosThemePick(pick)
    }
  }

  private openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
    if (this.action.busy() || !switchable()) {
      return
    }
    this.open.set(true)
    if (focus !== 'none') {
      // The menu renders on the next pass; focus once it exists.
      afterNextRender(() => this.focusItem(focus), { injector: this.injector })
    }
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

  // Native disabled takes focus away while the write is in flight. Return it
  // once the re-enabled button is on screen, while the control is still there
  // to take it.
  private async send(pick: ThemePick): Promise<void> {
    await this.action.run(sendHilosThemePick(pick))
    if (this.destroyed) {
      return
    }
    afterNextRender(
      () => {
        const button = this.toggleButton()?.nativeElement
        if (
          hilosSignedIn.get() &&
          switchable() &&
          button?.isConnected &&
          !button.disabled
        ) {
          button.focus()
        }
      },
      { injector: this.injector },
    )
  }

  private itemButtons(): HTMLButtonElement[] {
    const menu = this.menu()?.nativeElement

    return menu
      ? Array.from(
          menu.querySelectorAll<HTMLButtonElement>(
            '[role="menuitemradio"], [role="menuitem"]',
          ),
        )
      : []
  }

  private focusItem(which: 'first' | 'last'): void {
    const buttons = this.itemButtons()
    const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
    target?.focus()
  }

  private moveFocus(delta: 1 | -1): void {
    const buttons = this.itemButtons()
    if (buttons.length === 0) {
      return
    }
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
    buttons[next]?.focus()
  }
}
