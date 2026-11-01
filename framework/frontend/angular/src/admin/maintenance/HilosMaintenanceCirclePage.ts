// HilosMaintenanceCirclePage — the Maintenance Circle tab inside the admin shell
// (HIL-1682): the live verifier table and its add and remove dialogs, on their
// own address under Maintenance's shared section head. Angular counterpart of
// the Vue Circle page (HIL-1676): the table's window and row focus are bound to
// Circle, the first window arrives with the page's own answer, the remove dialog
// holds its row in focus and reports a removal in another tab, and the online
// marks stay live. The rows, actions and words belong to the core headless; a
// project supplies only HilosMaintenanceContext. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { HilosPages, isHiddenValue, subscribeSignal } from '@hilos/core/base'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'

import {
  HilosActionError,
  HilosAdminPage,
  HilosEditNotice,
  HilosHiddenMark,
  HilosHideable,
  HilosModal,
  HilosTableCell,
  HilosViewportTable,
  LoadingButton,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/**
 * The verifier circle as a standard table, with dialogs that close only on the
 * server's answer.
 */
@Component({
  selector: 'hilos-maintenance-circle-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosAdminPage,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <hilos-viewport-table
        dataId="hilos-maintenance-circle-table"
        [controller]="circle().controller"
      >
        <ng-template [hilosTableCell]="fields.identifier" let-row>
          <span
            [attr.data-id]="'hilos-maintenance-circle-row-' + circleKey(row)"
          >
            <hilos-hideable [value]="row.identifier" />
          </span>
        </ng-template>
        <ng-template [hilosTableCell]="fields.online" let-row>
          <span
            [class]="row.online ? 'text-success' : 'text-body-secondary'"
            [attr.data-id]="'hilos-maintenance-circle-online-' + circleKey(row)"
            >{{ row.online ? circleCopy.online : circleCopy.offline }}</span
          >
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            [title]="circleCopy.removeTitle"
            [attr.aria-label]="circleCopy.removeTitle"
            [attr.data-id]="'hilos-maintenance-circle-remove-' + circleKey(row)"
            (click)="openCircleRemove(row)"
          >
            <i class="bi bi-trash" aria-hidden="true"></i>
          </button>
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="circleAddOpen()"
        (openChange)="circleAddOpen.set($event)"
        [title]="circleCopy.addTitle"
        [busy]="circleAdd.busy()"
      >
        <hilos-action-error
          [action]="circleAdd"
          [detailsTitle]="circleCopy.addRefusalTitle"
        />
        <p class="mb-2 text-body-secondary">{{ circleCopy.addLead }}</p>
        <label class="form-label" for="hilos-maintenance-circle-add-field">{{
          circleCopy.addField
        }}</label>
        <input
          id="hilos-maintenance-circle-add-field"
          type="text"
          class="form-control"
          autocomplete="off"
          [placeholder]="circleCopy.addPlaceholder"
          [disabled]="circleAdd.busy()"
          data-id="hilos-maintenance-circle-add-field"
          data-autofocus
          [value]="circleAddIdentifier()"
          (input)="onCircleAddIdentifier($event)"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="circleAdd.busy()"
            data-id="hilos-maintenance-circle-add-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="circleAdd.busy()"
            [disabled]="circleAddIdentifier().trim() === ''"
            data-id="hilos-maintenance-circle-add-confirm"
            (click)="submitCircleAdd()"
          >
            {{ circleCopy.addConfirm }}
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="circleRemoveOpen()"
        (openChange)="$event ? circleRemoveOpen.set(true) : closeCircleRemove()"
        [title]="circleCopy.removeTitle"
        [busy]="circleRemove.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error
          [action]="circleRemove"
          [detailsTitle]="circleCopy.removeRefusalTitle"
        />
        @if (removeShown(); as member) {
          <p class="mb-0">
            {{ circleCopy.removeAskBefore }}
            @if (isHiddenValue(member.identifier)) {
              <hilos-hidden-mark />
            } @else {
              <code>{{ member.identifier }}</code>
            }
            {{ circleCopy.removeAskAfter }}
          </p>
        }
        <p class="mb-0 mt-2 text-body-secondary">{{ circleCopy.removeNote }}</p>
        <hilos-edit-notice
          [kind]="removeGone() ? 'deleted' : null"
          [text]="circleCopy.removeGone"
          dataId="hilos-maintenance-circle-remove-notice"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="circleRemove.busy()"
            data-id="hilos-maintenance-circle-remove-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="circleRemove.busy()"
            [disabled]="removeGone()"
            data-id="hilos-maintenance-circle-remove-confirm"
            (click)="submitCircleRemove()"
          >
            {{
              removeGone()
                ? circleCopy.removeGoneConfirm
                : circleCopy.removeConfirm
            }}
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosMaintenanceCirclePage {
  /** The project context: scope stores, the connection and the action lifecycle. */
  readonly context = input.required<HilosMaintenanceContext>()

  protected readonly page = HilosPages.MAINTENANCE_CIRCLE
  protected readonly isHiddenValue = isHiddenValue
  protected readonly circleCopy = HILOS_MAINTENANCE_CIRCLE_COPY
  protected readonly fields = {
    identifier: MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
    online: MAINTENANCE_CIRCLE_ONLINE_FIELD,
  } as const

  // The factory's default page is still Overview: Circle names its own address,
  // so the window, its requests and the row focus all go to this page.
  protected readonly circle = computed(() =>
    createHilosMaintenanceCircleTable(
      this.context(),
      { openAdd: () => this.openCircleAdd() },
      HilosPages.MAINTENANCE_CIRCLE,
    ),
  )
  private readonly actions = computed(() =>
    createHilosMaintenanceActions(this.context()),
  )
  // The row the remove dialog holds in focus, as it stands now — undefined once it
  // is gone.
  private readonly liveRow = signal<HilosMaintenanceCircleRow | undefined>(
    undefined,
  )

  // The add dialog. It closes on the server's word only: a refusal keeps it open
  // with what was typed, and the named row arrives over the live table.
  protected readonly circleAddOpen = signal(false)
  protected readonly circleAddIdentifier = signal('')
  protected readonly circleAdd = createHilosTrackedAction()

  // The remove dialog. It closes on the server's word only, and the row leaves over
  // the live table. The dialog holds its row in focus while open.
  protected readonly circleRemoveOpen = signal(false)
  protected readonly circleRemoveRow = signal<HilosMaintenanceCircleRow | null>(
    null,
  )
  protected readonly circleRemove = createHilosTrackedAction()
  private readonly removeLive = computed(() =>
    this.circleRemoveRow() ? this.liveRow() : undefined,
  )
  protected readonly removeShown = computed(
    () => this.removeLive() ?? this.circleRemoveRow(),
  )
  // Gone elsewhere. Our own removal in flight is not that: its echo makes the row
  // a placeholder before the action's ack arrives, and that placeholder is ours.
  protected readonly removeGone = computed(
    () =>
      this.circleRemoveRow() !== null &&
      !this.circleRemove.busy() &&
      this.removeLive() === undefined,
  )

  constructor() {
    // Bind the table to Circle — consuming the window of the page's own answer —
    // together with its focused-row mirror; a context change or leaving the page
    // lets the focused row go, drops every listener of the table and closes the
    // dialogs, which spoke for that table's rows and not for the new one's.
    effect((onCleanup) => {
      const circle = this.circle()
      circle.start()
      this.liveRow.set(circle.controller.focusedRow.get())
      const unsubscribe = subscribeSignal(circle.controller.focusedRow, (row) =>
        this.liveRow.set(row),
      )
      onCleanup(() => {
        unsubscribe()
        circle.controller.releaseFocus()
        circle.dispose()
        this.circleAddOpen.set(false)
        this.circleRemoveOpen.set(false)
        this.circleRemoveRow.set(null)
      })
    })
  }

  protected openCircleAdd(): void {
    this.circleAdd.clearError()
    this.circleAddIdentifier.set('')
    this.circleAddOpen.set(true)
  }

  protected onCircleAddIdentifier(event: Event): void {
    this.circleAddIdentifier.set((event.target as HTMLInputElement).value)
  }

  protected async submitCircleAdd(): Promise<void> {
    if (this.circleAdd.busy() || this.circleAddIdentifier().trim() === '') {
      return
    }
    if (
      await this.circleAdd.run(
        this.actions().sendMaintenanceCircleAdd(this.circleAddIdentifier()),
      )
    ) {
      this.circleAddOpen.set(false)
    }
  }

  /**
   * What tells a circle row apart in its data-ids: the address, or the membership
   * id while the address is hidden — every hidden row would share one otherwise.
   *
   * @param row The circle row.
   */
  protected circleKey(row: HilosMaintenanceCircleRow): string {
    return isHiddenValue(row.identifier)
      ? `member-${row.memberId}`
      : row.identifier
  }

  protected openCircleRemove(row: HilosMaintenanceCircleRow): void {
    // Flush pending and take the row into focus; a row already taken out by
    // someone else does not open the dialog.
    const fresh = this.circle().controller.focusRow(String(row.memberId))
    if (!fresh) {
      return
    }
    this.circleRemove.clearError()
    this.circleRemoveRow.set(fresh)
    this.circleRemoveOpen.set(true)
  }

  protected closeCircleRemove(): void {
    this.circleRemoveOpen.set(false)
    this.circle().controller.releaseFocus()
  }

  protected async submitCircleRemove(): Promise<void> {
    const row = this.circleRemoveRow()
    if (!row || this.circleRemove.busy() || this.removeGone()) {
      return
    }
    if (
      await this.circleRemove.run(
        this.actions().sendMaintenanceCircleRemove(row.memberId),
      )
    ) {
      this.closeCircleRemove()
    }
  }
}
