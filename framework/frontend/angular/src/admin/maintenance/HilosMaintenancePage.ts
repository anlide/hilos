// HilosMaintenancePage — the framework maintenance section inside the admin shell
// (HIL-1119), with the verifier circle's add and remove dialogs (HIL-1120/1121).
// Angular counterpart of the Vue page (HIL-1123): the remove dialog holds its row in
// focus and reports a removal in another tab; the list and its online marks stay
// live. The table, row view-model, actions and words belong to the core headless.
// A project supplies only HilosMaintenanceContext. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HilosPages,
  hiddenAsWord,
  HILOS_VIEW_MODE_COPY,
  isHiddenValue,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  subscribeSignal,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
  type HilosTableColumnOf,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

const CIRCLE_COLUMNS: HilosTableColumnOf<HilosMaintenanceCircleRow>[] = [
  {
    key: MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.addressColumn,
    sortable: true,
  },
  {
    key: MAINTENANCE_CIRCLE_ONLINE_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.onlineColumn,
  },
  {
    key: 'actions',
    label: '',
    headerClass: 'text-end',
    reads: [MAINTENANCE_CIRCLE_IDENTIFIER_FIELD],
  },
]

/** The verifier circle, with dialogs that close only on the server's answer. */
@Component({
  selector: 'hilos-maintenance-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosAdminPage,
    HilosEditNotice,
    HilosModal,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <div class="card mb-3" data-id="hilos-maintenance-circle-panel">
        <div class="card-body">
          <div
            class="d-flex align-items-start justify-content-between gap-2 flex-wrap"
          >
            <div>
              <div class="fw-semibold">{{ circleCopy.title }}</div>
              <div class="small text-body-secondary">{{ circleCopy.rule }}</div>
              <div class="small text-body-secondary">
                {{ circleCopy.volatile }}
              </div>
            </div>
            <button
              type="button"
              class="btn btn-outline-primary btn-sm text-nowrap"
              data-id="hilos-maintenance-circle-add"
              (click)="openCircleAdd()"
            >
              {{ circleCopy.addButton }}
            </button>
          </div>
          <div class="mt-3">
            <hilos-viewport-table
              dataId="hilos-maintenance-circle-table"
              [label]="circleCopy.title"
              [controller]="circle().controller"
              [columns]="circleColumns"
              [emptyText]="circleCopy.empty"
            >
              <ng-template #row let-row>
                <td
                  [attr.data-id]="
                    'hilos-maintenance-circle-row-' + circleKey(row)
                  "
                >
                  {{ hiddenAsWord(row.identifier) }}
                </td>
                <td>
                  <span
                    [class]="
                      row.online ? 'text-success' : 'text-body-secondary'
                    "
                    [attr.data-id]="
                      'hilos-maintenance-circle-online-' + circleKey(row)
                    "
                    >{{
                      row.online ? circleCopy.online : circleCopy.offline
                    }}</span
                  >
                </td>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    [title]="circleCopy.removeTitle"
                    [attr.aria-label]="circleCopy.removeTitle"
                    [attr.data-id]="
                      'hilos-maintenance-circle-remove-' + circleKey(row)
                    "
                    (click)="openCircleRemove(row)"
                  >
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                </td>
              </ng-template>
            </hilos-viewport-table>
          </div>
        </div>
      </div>

      <hilos-modal
        [open]="circleAddOpen()"
        (openChange)="circleAddOpen.set($event)"
        [title]="circleCopy.addTitle"
        [closeOnBackdrop]="!circleAdd.busy()"
        [closeOnEsc]="!circleAdd.busy()"
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
            [loading]="circleAdd.loading()"
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
        [closeOnBackdrop]="!circleRemove.busy()"
        [closeOnEsc]="!circleRemove.busy()"
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
              {{ hiddenWord }}
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
            [loading]="circleRemove.loading()"
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
export class HilosMaintenancePage {
  /** The project context: scope stores, the connection and the action lifecycle. */
  readonly context = input.required<HilosMaintenanceContext>()

  protected readonly page = HilosPages.MAINTENANCE
  protected readonly hiddenAsWord = hiddenAsWord
  protected readonly hiddenWord = HILOS_VIEW_MODE_COPY.hidden
  protected readonly isHiddenValue = isHiddenValue
  protected readonly circleColumns = CIRCLE_COLUMNS
  protected readonly circleCopy = HILOS_MAINTENANCE_CIRCLE_COPY
  protected readonly circle = computed(() =>
    createHilosMaintenanceCircleTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosMaintenanceActions(this.context()),
  )
  private readonly liveRow = signal<HilosMaintenanceCircleRow | undefined>(
    undefined,
  )

  protected readonly circleAddOpen = signal(false)
  protected readonly circleAddIdentifier = signal('')
  protected readonly circleAdd = createHilosTrackedAction()
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
  // Our own echo can make the row a placeholder before the action ack arrives.
  protected readonly removeGone = computed(
    () =>
      this.circleRemoveRow() !== null &&
      !this.circleRemove.busy() &&
      this.removeLive() === undefined,
  )

  constructor() {
    // Rebind the table and its focused-row mirror together when the context changes.
    effect((onCleanup) => {
      const circle = this.circle()
      circle.start()
      this.liveRow.set(circle.controller.focusedRow.get())
      const unsubscribe = subscribeSignal(circle.controller.focusedRow, (row) =>
        this.liveRow.set(row),
      )
      onCleanup(() => {
        unsubscribe()
        circle.dispose()
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
