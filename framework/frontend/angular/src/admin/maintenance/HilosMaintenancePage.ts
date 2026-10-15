// HilosMaintenancePage — the framework maintenance section inside the admin shell
// (HIL-1119), with the verifier circle's add and remove dialogs (HIL-1120/1121).
// Angular counterpart of the Vue page (HIL-1123, HIL-1365): the live protected-mode
// state with manual entry, exit and code issuance, each waiting for its own
// tracked answer independently of the live state frame; the remove dialog holds
// its row in focus and reports a removal in another tab; the list and its online
// marks stay live. The state, table, row view-model, actions and words belong to
// the core headless. A project supplies only HilosMaintenanceContext. Bootstrap
// classes only.
import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  Injector,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  ActionError,
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  HilosPages,
  hilosMaintenanceCard,
  hilosMaintenancePosition,
  isHiddenValue,
  subscribeSignal,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
  type HilosMaintenanceState,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/**
 * How a manual action reports a failure: a refusal in the server's own words,
 * anything else as the action's note that its outcome is unconfirmed.
 *
 * @param unconfirmedNote The action's own note for an outcome nobody confirmed.
 */
function describeManualError(
  unconfirmedNote: string,
): (error: unknown) => string {
  return (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : unconfirmedNote
}

/**
 * The protected-mode block and the verifier circle, with dialogs that close only
 * on the server's answer.
 */
@Component({
  selector: 'hilos-maintenance-page',
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
    <hilos-admin-page [page]="page">
      <div aria-live="polite" data-id="hilos-maintenance-state">
        @if (position() === 'open') {
          <div
            class="alert alert-success d-flex flex-column flex-sm-row gap-3 align-items-start mb-3"
            data-id="hilos-maintenance-state-open"
          >
            <div class="d-flex gap-3 flex-grow-1">
              <i class="bi bi-check-circle-fill fs-5" aria-hidden="true"></i>
              <div>
                <h2 class="h6 fw-semibold mb-1">{{ stateCopy.openTitle }}</h2>
                <p class="small mb-0">{{ stateCopy.openDescription }}</p>
              </div>
            </div>
            <button
              hilosLoadingButton
              class="btn-warning btn-sm text-nowrap"
              [disabled]="enableAction.busy()"
              data-id="hilos-maintenance-enable"
              (click)="openEnable()"
            >
              {{ enableCopy.enableTitle }}
            </button>
          </div>
        } @else if (stateCard(); as card) {
          <section
            class="card border-warning mb-3"
            [attr.data-id]="'hilos-maintenance-state-' + card.position"
            [attr.aria-label]="card.title"
          >
            <div
              class="card-header d-flex flex-wrap align-items-center gap-2 bg-warning-subtle"
            >
              <h2 class="h6 fw-semibold mb-0">{{ card.title }}</h2>
              <span class="badge text-bg-warning">{{ card.phase }}</span>
              <span class="small text-body-secondary ms-auto">
                {{ stateCopy.operationPrefix }}
                <code>{{ card.operation ?? '—' }}</code>
              </span>
            </div>
            <div class="card-body">
              <dl class="row mb-0">
                <dt class="col-sm-3">{{ stateCopy.phase }}</dt>
                <dd class="col-sm-9">
                  <span class="badge text-bg-warning">{{ card.phase }}</span>
                  @if (card.phaseNote) {
                    <span class="text-body-secondary ms-2">{{
                      card.phaseNote
                    }}</span>
                  }
                </dd>

                @if (card.position === 'manual') {
                  <dt class="col-sm-3">{{ stateCopy.closedSince }}</dt>
                  <dd class="col-sm-9">
                    <code>{{ card.startedAt ?? '—' }}</code>
                    <span class="text-body-secondary ms-2">{{
                      card.elapsed
                    }}</span>
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.turnedOn }}</dt>
                  <dd class="col-sm-9">{{ card.turnedOn }}</dd>
                } @else {
                  <dt class="col-sm-3">{{ stateCopy.operation }}</dt>
                  <dd class="col-sm-9">
                    <code>{{ card.operation ?? '—' }}</code>
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.started }}</dt>
                  <dd class="col-sm-9">
                    <code>{{ card.startedAt ?? '—' }}</code>
                  </dd>
                }

                <dt class="col-sm-3">{{ stateCopy.codesInHand }}</dt>
                <dd class="col-sm-9" data-id="hilos-maintenance-pass-count">
                  {{ card.passCount }}
                </dd>
                <dt class="col-sm-3">{{ stateCopy.circle }}</dt>
                <dd class="col-sm-9">{{ card.circle }}</dd>
              </dl>
              @if (card.position === 'manual') {
                <div class="hilos-button-row mt-3">
                  <button
                    hilosLoadingButton
                    class="btn-outline-primary btn-sm"
                    [loading]="passAction.loading()"
                    [disabled]="passAction.busy()"
                    data-id="hilos-maintenance-pass"
                    (click)="openPass()"
                  >
                    {{ passCopy.issue }}
                  </button>
                  <button
                    hilosLoadingButton
                    class="btn-warning btn-sm"
                    [loading]="disableAction.loading()"
                    [disabled]="disableAction.busy()"
                    data-id="hilos-maintenance-disable"
                    (click)="openDisable()"
                  >
                    {{ disableCopy.disableTitle }}
                  </button>
                </div>
              }
            </div>
          </section>
        }
      </div>

      <hilos-modal
        [open]="enableOpen()"
        (openChange)="$event || closeEnable()"
        [title]="enableCopy.enableTitle"
        [closeOnBackdrop]="!enableAction.busy()"
        [closeOnEsc]="!enableAction.busy()"
        [closeDisabled]="enableAction.busy()"
        initialFocus="dialog"
      >
        <div data-id="hilos-maintenance-enable-modal">
          <hilos-action-error
            [action]="enableAction"
            [detailsTitle]="enableCopy.refusalTitle"
          />
          <p class="mb-2">{{ enableCopy.lead }}</p>
          <ul class="mb-2 ps-3">
            <li>
              <strong>{{ enableCopy.outside }}</strong>
              — {{ enableCopy.outsideNote }}
            </li>
            <li>
              <strong>{{ enableCopy.inside }}</strong>
              — {{ enableCopy.insideNote }}
            </li>
            <li>
              <strong>{{ enableCopy.agents }}</strong>
              — {{ enableCopy.agentsNote }}
            </li>
          </ul>
          <p class="mb-0 text-body-secondary">{{ enableCopy.footer }}</p>
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="enableAction.busy()"
            data-id="hilos-maintenance-enable-cancel"
            (click)="requestClose()"
          >
            {{ enableCopy.cancel }}
          </button>
          <button
            hilosLoadingButton
            class="btn-warning"
            [loading]="enableAction.loading()"
            [disabled]="enableAction.busy() || position() !== 'open'"
            data-id="hilos-maintenance-enable-confirm"
            (click)="submitEnable()"
          >
            {{ enableCopy.confirm }}
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="disableOpen()"
        (openChange)="$event || closeDisable()"
        [title]="disableCopy.disableTitle"
        [closeOnBackdrop]="!disableAction.busy()"
        [closeOnEsc]="!disableAction.busy()"
        [closeDisabled]="disableAction.busy()"
        initialFocus="dialog"
      >
        <div data-id="hilos-maintenance-disable-modal">
          <hilos-action-error
            [action]="disableAction"
            [detailsTitle]="disableCopy.refusalTitle"
          />
          <p class="mb-2">{{ disableCopy.lead }}</p>
          <p class="mb-0 text-body-secondary">{{ disableCopy.footer }}</p>
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="disableAction.busy()"
            data-id="hilos-maintenance-disable-cancel"
            (click)="requestClose()"
          >
            {{ disableCopy.cancel }}
          </button>
          <button
            hilosLoadingButton
            class="btn-warning"
            [loading]="disableAction.loading()"
            [disabled]="disableAction.busy() || position() !== 'manual'"
            data-id="hilos-maintenance-disable-confirm"
            (click)="submitDisable()"
          >
            {{ disableCopy.confirm }}
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="passOpen()"
        (openChange)="$event || closePass()"
        [title]="
          passStep() === 'confirm' ? passCopy.mintTitle : passCopy.codeTitle
        "
        [closeOnBackdrop]="!passAction.busy()"
        [closeOnEsc]="!passAction.busy()"
        [closeDisabled]="passAction.busy()"
        [copyText]="pass() ?? ''"
        initialFocus="dialog"
      >
        <div data-id="hilos-maintenance-pass-modal">
          @if (passStep() === 'confirm') {
            <hilos-action-error
              [action]="passAction"
              [detailsTitle]="passCopy.refusalTitle"
            />
            <p class="mb-2">{{ passCopy.lead }}</p>
            <p class="mb-0 text-body-secondary">{{ passCopy.reuseNote }}</p>
          } @else {
            <div data-id="hilos-maintenance-pass-result">
              <p class="mb-2 text-body-secondary">{{ passCopy.resultLead }}</p>
              <div
                #passValue
                tabindex="-1"
                class="border rounded-3 p-3 bg-body-tertiary text-center user-select-all text-break"
                data-id="hilos-maintenance-pass-value"
              >
                <code class="fs-5">{{ pass() }}</code>
              </div>
              <p class="small text-body-secondary mt-2 mb-0">
                {{ passCopy.resultNote }}
              </p>
            </div>
          }
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="passAction.busy()"
            data-id="hilos-maintenance-pass-cancel"
            (click)="requestClose()"
          >
            {{ passStep() === 'confirm' ? passCopy.cancel : passCopy.close }}
          </button>
          @if (passStep() === 'confirm') {
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="passAction.loading()"
              [disabled]="passAction.busy() || position() !== 'manual'"
              data-id="hilos-maintenance-pass-confirm"
              (click)="submitPass()"
            >
              {{ passCopy.mint }}
            </button>
          }
        </ng-template>
      </hilos-modal>

      <div class="card mb-3" data-id="hilos-maintenance-circle-panel">
        <div class="card-body">
          <hilos-viewport-table
            dataId="hilos-maintenance-circle-table"
            [controller]="circle().controller"
          >
            <ng-template hilosTableCell="identifier" let-row>
              <span
                [attr.data-id]="
                  'hilos-maintenance-circle-row-' + circleKey(row)
                "
              >
                <hilos-hideable [value]="row.identifier" />
              </span>
            </ng-template>
            <ng-template hilosTableCell="online" let-row>
              <span
                [class]="row.online ? 'text-success' : 'text-body-secondary'"
                [attr.data-id]="
                  'hilos-maintenance-circle-online-' + circleKey(row)
                "
                >{{ row.online ? circleCopy.online : circleCopy.offline }}</span
              >
            </ng-template>
            <ng-template hilosTableCell="actions" let-row>
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
            </ng-template>
          </hilos-viewport-table>
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
  protected readonly isHiddenValue = isHiddenValue
  protected readonly circleCopy = HILOS_MAINTENANCE_CIRCLE_COPY
  protected readonly stateCopy = HILOS_MAINTENANCE_STATE_COPY
  protected readonly enableCopy = HILOS_MAINTENANCE_ENABLE_COPY
  protected readonly disableCopy = HILOS_MAINTENANCE_DISABLE_COPY
  protected readonly passCopy = HILOS_MAINTENANCE_PASS_COPY

  private readonly injector = inject(Injector)
  private readonly stateHandle = computed(() =>
    createHilosMaintenanceState(this.context()),
  )
  private readonly maintenanceState = signal<HilosMaintenanceState | null>(null)
  private readonly nowMs = signal(Date.now())
  protected readonly position = computed(() =>
    hilosMaintenancePosition(this.maintenanceState()),
  )
  protected readonly stateCard = computed(() =>
    hilosMaintenanceCard(this.maintenanceState(), this.nowMs()),
  )

  // A state push may replace the open banner while this action still awaits its ack.
  protected readonly enableOpen = signal(false)
  protected readonly enableAction = createHilosTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote,
    ),
  })

  // The dialog survives a state push; the shared lift may reload before its ack arrives.
  protected readonly disableOpen = signal(false)
  protected readonly disableAction = createHilosTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote,
    ),
  })

  // The clear code belongs only to this dialog's result and is forgotten on every exit.
  protected readonly passOpen = signal(false)
  protected readonly pass = signal<string | null>(null)
  protected readonly passStep = signal<'confirm' | 'result'>('confirm')
  private readonly passValue = viewChild<ElementRef<HTMLElement>>('passValue')
  private passDisposed = false
  protected readonly passAction = createHilosTrackedAction({
    describeError: describeManualError(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    ),
  })
  protected readonly circle = computed(() =>
    createHilosMaintenanceCircleTable(this.context(), {
      openAdd: () => this.openCircleAdd(),
    }),
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
    // Rebind the state frame and its mirror together when the context changes.
    effect((onCleanup) => {
      const handle = this.stateHandle()
      handle.start()
      this.maintenanceState.set(handle.state.get())
      const unsubscribe = subscribeSignal(handle.state, (state) =>
        this.maintenanceState.set(state),
      )
      onCleanup(() => {
        unsubscribe()
        handle.dispose()
      })
    })
    // "N minutes ago" moves on the browser's clock, and the clock stops with the page.
    const clockTick = setInterval(() => this.nowMs.set(Date.now()), 30_000)
    const destroyRef = inject(DestroyRef)
    destroyRef.onDestroy(() => clearInterval(clockTick))
    // Leaving the page forgets the code, and an answer that arrives later finds the
    // view gone.
    destroyRef.onDestroy(() => {
      this.passDisposed = true
      this.pass.set(null)
      this.passOpen.set(false)
    })
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

  protected openEnable(): void {
    if (this.enableAction.busy() || this.position() !== 'open') {
      return
    }
    this.enableAction.clearError()
    this.enableOpen.set(true)
  }

  protected closeEnable(): void {
    if (!this.enableAction.busy()) {
      this.enableOpen.set(false)
    }
  }

  protected async submitEnable(): Promise<void> {
    if (this.enableAction.busy() || this.position() !== 'open') {
      return
    }
    if (await this.enableAction.run(this.actions().sendMaintenanceEnable())) {
      this.enableOpen.set(false)
    }
  }

  protected openDisable(): void {
    if (this.disableAction.busy() || this.position() !== 'manual') {
      return
    }
    this.disableAction.clearError()
    this.disableOpen.set(true)
  }

  protected closeDisable(): void {
    if (!this.disableAction.busy()) {
      this.disableOpen.set(false)
    }
  }

  protected async submitDisable(): Promise<void> {
    if (this.disableAction.busy() || this.position() !== 'manual') {
      return
    }
    if (await this.disableAction.run(this.actions().sendMaintenanceDisable())) {
      this.disableOpen.set(false)
    }
  }

  protected openPass(): void {
    if (
      this.passDisposed ||
      this.passAction.busy() ||
      this.position() !== 'manual'
    ) {
      return
    }
    this.pass.set(null)
    this.passStep.set('confirm')
    this.passAction.clearError()
    this.passOpen.set(true)
  }

  protected closePass(): void {
    if (this.passAction.busy()) {
      return
    }
    this.passOpen.set(false)
    this.pass.set(null)
    this.passStep.set('confirm')
  }

  protected async submitPass(): Promise<void> {
    if (
      this.passDisposed ||
      !this.passOpen() ||
      this.passAction.busy() ||
      this.passStep() !== 'confirm' ||
      this.position() !== 'manual'
    ) {
      return
    }
    const handle = this.actions().sendMaintenancePass()
    if (await this.passAction.run(handle)) {
      const result = await handle.done
      if (this.passDisposed || !this.passOpen()) {
        return
      }
      this.pass.set(result.reply!.pass)
      this.passStep.set('result')
      // The value takes focus once the result is on screen.
      afterNextRender(() => this.passValue()?.nativeElement.focus(), {
        injector: this.injector,
      })
    }
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
