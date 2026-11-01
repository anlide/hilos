// HilosMaintenanceWatchdogAlert — the current server alarm, in one reserved line
// above the state card of the maintenance Overview (HIL-1682). Angular counterpart
// of the Vue component (HIL-1680). The verdict is the server's: the row draws the
// core projection it is handed and never judges age or phase itself. The slot is
// the one polite live region and stays put; while clear, an invisible, inert twin
// of the very same row holds its room, so neither an alarm nor the all-clear moves
// what is read below (styling-rules.md, "The room a live message takes"). The line
// is truncated, and the whole of it — the full text and every diagnostic fact —
// is read in the ordinary modal behind the permanent Details button. Reading the
// details sends nothing and closing them does not dismiss the alarm: only the
// watchdog clears it, and the all-clear takes the open details with it. Internal
// to the Overview; not exported. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_MAINTENANCE_WATCHDOG_COPY,
  type HilosMaintenanceWatchdogAlert as WatchdogAlert,
} from '@hilos/core/admin/maintenance'

import { HilosModal } from '@hilos/angular/base'

/** The reserved alarm row of the maintenance Overview and its read-only details. */
@Component({
  selector: 'hilos-maintenance-watchdog-alert',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModal],
  template: `
    <div
      data-id="hilos-maintenance-watchdog-slot"
      role="status"
      aria-live="polite"
    >
      @if (alert(); as current) {
        <div
          [class]="rowClass + ' alert-' + current.tone"
          data-id="hilos-maintenance-watchdog-alert"
        >
          <i
            class="bi bi-exclamation-triangle flex-shrink-0"
            aria-hidden="true"
          ></i>
          <span class="flex-grow-1 text-truncate"
            ><strong>{{ current.title }}</strong> — {{ current.summary }}</span
          >
          <button
            type="button"
            [class]="detailsClass"
            aria-label="Show details"
            title="Show details"
            data-id="hilos-maintenance-watchdog-details"
            (click)="detailOpen.set(true)"
          >
            {{ copy.details }}
          </button>
        </div>
      } @else {
        <div
          [class]="rowClass + ' alert-warning invisible'"
          aria-hidden="true"
          inert
          data-id="hilos-maintenance-watchdog-idle"
        >
          <i
            class="bi bi-exclamation-triangle flex-shrink-0"
            aria-hidden="true"
          ></i>
          <span class="flex-grow-1 text-truncate">&nbsp;</span>
          <!-- A span, not a button: the twin holds room, it does not take focus. -->
          <span [class]="detailsClass">{{ copy.details }}</span>
        </div>
      }
    </div>

    <hilos-modal
      [open]="detailOpen()"
      (openChange)="detailOpen.set($event)"
      [title]="copy.details"
      initialFocus="dialog"
    >
      @if (alert(); as current) {
        <div data-id="hilos-maintenance-watchdog-full">
          <h3 class="h6 text-break">{{ current.title }}</h3>
          <p class="text-break">{{ current.summary }}</p>
          <dl class="row mb-0">
            @for (fact of current.facts; track fact.label) {
              <dt class="col-sm-4">{{ fact.label }}</dt>
              <dd class="col-sm-8 text-break">{{ fact.value }}</dd>
            }
          </dl>
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          data-id="hilos-maintenance-watchdog-close"
          (click)="requestClose()"
        >
          {{ copy.close }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosMaintenanceWatchdogAlert {
  /** The core projection of the current server verdict, or null while clear. */
  readonly alert = input.required<WatchdogAlert | null>()

  protected readonly copy = HILOS_MAINTENANCE_WATCHDOG_COPY

  /**
   * The row and its idle twin, to the character — only the tone and `invisible`
   * differ, and neither changes the height.
   */
  protected readonly rowClass =
    'alert small py-1 px-2 my-2 d-flex align-items-center gap-2'

  /** The permanent reading control and its non-interactive twin. */
  protected readonly detailsClass =
    'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

  protected readonly detailOpen = signal(false)

  constructor() {
    // The all-clear takes the open details with it; a new alarm never opens them.
    effect(() => {
      if (this.alert() === null) {
        this.detailOpen.set(false)
      }
    })
  }
}
