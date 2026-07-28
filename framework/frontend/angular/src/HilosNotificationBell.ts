// HilosNotificationBell — the tier-1 notification-center surface (HIL-195): a
// bell button carrying the unread badge and a disclosure dropdown of the recent
// notifications, each with its own mark-read control plus a mark-all and a "see
// all" placeholder. It renders the framework-owned notification store
// (hilosNotifications, fed by the connection through bindNotificationsScope once
// `notifications: true` is passed to bootHilos) and marks read by firing the
// `notification_mark_read` / `notification_mark_all_read` actions — the store
// never updates optimistically; it turns read when the server fans the READ
// signal back to every one of the recipient's tabs (core-and-connection.md, the
// shared-state signal round-trip). A connection with no signed-in user never
// joins the group, so the bell simply shows an empty, zero-badge menu there.
// Open/close, outside-click, Escape, and arrow-roving mirror the Vue bell; the
// panel is a disclosure (button + aria-expanded), not a listbox, because each row
// hosts an action rather than a single-select option. Unread is never signalled
// by color alone — an unread row is bold and carries a visually-hidden "Unread"
// marker (styling-rules.md, accessibility.md). Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  computed,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  hilosNotifications,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
  type HilosNotification,
  type HilosNotificationStore,
} from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'

// Distinct ids so two bells on one page never share an `aria-controls` target.
let bellMenuSeq = 0

/**
 * The notification bell: an unread badge over a bell button that opens the
 * recent-notifications dropdown.
 */
@Component({
  selector: 'hilos-notification-bell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="dropdown" data-id="hilos-notification-bell">
      <button
        type="button"
        class="btn btn-link nav-link position-relative d-inline-flex align-items-center p-0 fs-5"
        [attr.aria-expanded]="open()"
        [attr.aria-controls]="menuId"
        aria-haspopup="true"
        aria-label="Notifications"
        data-id="hilos-notification-toggle"
        (click)="onToggleClick()"
        (keydown.arrowdown)="onToggleArrow($event, 'first')"
        (keydown.arrowup)="onToggleArrow($event, 'last')"
        (keydown.escape)="close(true)"
      >
        <i class="bi bi-bell" aria-hidden="true"></i>
        @if (unreadCount() > 0) {
          <span
            class="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle"
            data-id="hilos-notification-badge"
            >{{ badgeLabel()
            }}<span class="visually-hidden"> unread notifications</span></span
          >
        }
      </button>
      @if (open()) {
        <div
          [id]="menuId"
          class="dropdown-menu dropdown-menu-end show p-0"
          data-id="hilos-notification-menu"
          (keydown.arrowdown)="onMenuArrow($event, 1)"
          (keydown.arrowup)="onMenuArrow($event, -1)"
          (keydown.home)="onMenuHome($event, 'first')"
          (keydown.end)="onMenuHome($event, 'last')"
          (keydown.escape)="close(true)"
        >
          <div
            class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom"
          >
            <span class="fw-semibold">Notifications</span>
            <button
              type="button"
              class="btn btn-link btn-sm p-0"
              [disabled]="unreadCount() === 0"
              data-id="hilos-notification-mark-all"
              (click)="markAllRead()"
            >
              Mark all read
            </button>
          </div>
          <ul class="list-unstyled mb-0" style="max-width: 22rem">
            @if (notifications().length === 0) {
              <li>
                <p
                  class="dropdown-item-text text-body-secondary small mb-0 px-3 py-3"
                  data-id="hilos-notification-empty"
                >
                  No notifications
                </p>
              </li>
            }
            @for (row of notifications(); track row.id) {
              <li
                class="border-bottom"
                [attr.data-id]="'hilos-notification-item-' + row.id"
              >
                <div class="d-flex align-items-start gap-2 px-3 py-2">
                  <div class="flex-grow-1">
                    <div [class.fw-bold]="row.readAt == null">
                      {{ row.title }}
                      @if (row.readAt == null) {
                        <span class="visually-hidden"> (Unread)</span>
                      }
                    </div>
                    @if (row.body) {
                      <div class="small text-body-secondary">{{ row.body }}</div>
                    }
                    <div class="small text-body-tertiary">
                      {{ formatTime(row) }}
                    </div>
                  </div>
                  @if (row.readAt == null) {
                    <button
                      type="button"
                      class="btn btn-link btn-sm p-0 flex-shrink-0"
                      [attr.aria-label]="
                        'Mark &quot;' + row.title + '&quot; read'
                      "
                      [attr.data-id]="'hilos-notification-mark-read-' + row.id"
                      (click)="markRead(row)"
                    >
                      <i class="bi bi-check2" aria-hidden="true"></i>
                    </button>
                  }
                </div>
              </li>
            }
          </ul>
          <div class="border-top px-3 py-2 text-center">
            <button
              type="button"
              class="btn btn-link btn-sm p-0"
              disabled
              data-id="hilos-notification-see-all"
            >
              See all
            </button>
          </div>
        </div>
      }
    </div>
  `,
})
export class HilosNotificationBell {
  /** The connection the mark-read actions are sent over. */
  readonly connection = input.required<HilosConnection>()
  /**
   * The store the bell renders; defaults to the shared framework store. Read once
   * at construction (like the Vue/React bells capture their prop), so an override
   * is for a test or second window, not a runtime rebind.
   */
  readonly store = input<HilosNotificationStore>(hilosNotifications)

  private readonly boundStore = this.store()
  protected readonly notifications = hilosSignal(this.boundStore.notifications)
  protected readonly unreadCount = hilosSignal(this.boundStore.unreadCount)
  // The badge caps its label so a runaway count never breaks the bell's layout.
  protected readonly badgeLabel = computed(() =>
    this.unreadCount() > 99 ? '99+' : String(this.unreadCount()),
  )

  protected readonly open = signal(false)
  protected readonly menuId = `hilos-notification-menu-${bellMenuSeq++}`

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef)

  // Close on an outside click while open (the SDK ships Bootstrap CSS, not its
  // JS, so the dropdown owns its own dismissal like HilosDropdown / HilosModal).
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (this.open() && !this.host.nativeElement.contains(event.target as Node)) {
      this.open.set(false)
    }
  }

  protected onToggleClick(): void {
    this.open.set(!this.open())
  }

  protected onToggleArrow(event: Event, which: 'first' | 'last'): void {
    event.preventDefault()
    this.open.set(true)
    // The panel renders on the next tick; focus after it exists.
    requestAnimationFrame(() => this.focusItem(which))
  }

  protected close(returnFocus = false): void {
    this.open.set(false)
    if (returnFocus) {
      this.toggleButton()?.focus()
    }
  }

  protected onMenuArrow(event: Event, delta: 1 | -1): void {
    event.preventDefault()
    const buttons = this.itemButtons()
    if (buttons.length === 0) {
      return
    }
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
    buttons[next]?.focus()
  }

  protected onMenuHome(event: Event, which: 'first' | 'last'): void {
    event.preventDefault()
    this.focusItem(which)
  }

  // Mark-read is fire-and-forget: the store turns read only when the server fans
  // the READ signal back (no optimistic path), so a failed send simply leaves the
  // row unread rather than desyncing the badge.
  protected markRead(row: HilosNotification): void {
    this.connection().sendAction(NOTIFICATION_ACTION_MARK_READ, { id: row.id })
  }

  protected markAllRead(): void {
    this.connection().sendAction(NOTIFICATION_ACTION_MARK_ALL_READ, {})
  }

  // A notification's timestamp, formatted for the reader's locale; the wire value
  // is an ISO string, absent only on a malformed row.
  protected formatTime(row: HilosNotification): string {
    return row.createdAt ? new Date(row.createdAt).toLocaleString() : ''
  }

  // The per-row mark-read buttons the arrow keys rove over; the header/footer
  // controls (mark-all, see-all) stay Tab-reachable but out of the roving ring.
  private itemButtons(): HTMLButtonElement[] {
    return Array.from(
      this.host.nativeElement.querySelectorAll<HTMLButtonElement>(
        'ul button:not(:disabled)',
      ),
    )
  }

  private focusItem(which: 'first' | 'last'): void {
    const buttons = this.itemButtons()
    const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
    target?.focus()
  }

  private toggleButton(): HTMLButtonElement | null {
    return this.host.nativeElement.querySelector<HTMLButtonElement>(
      '[data-id="hilos-notification-toggle"]',
    )
  }
}
