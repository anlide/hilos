// HilosMaintenancePage — the Overview of the framework maintenance section inside
// the admin shell (HIL-1119), under Maintenance's shared section head and its row
// of tabs (HIL-1682). Angular counterpart of the Vue page (HIL-1123, HIL-1365,
// HIL-1679, HIL-1680): the live protected-mode state with manual entry, exit and
// code issuance, and the restore card with the initiating browser's way out — mint
// a code, open the system, close the window again. Each action waits for its own
// tracked answer independently of the live state frame. Above the state stands the
// watchdog's one reserved alarm line, the server's verdict as it arrives; under
// the state and the ladder, in every position, the watchdog's thresholds, read
// only. The verifier circle is its own tab, HilosMaintenanceCirclePage; Overview
// opens no table. The state, actions and words belong to the core headless. A
// project supplies only HilosMaintenanceContext. Bootstrap classes only.
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
  formatMoment,
  ActionError,
  HilosPages,
  hilosTimeZone,
  subscribeSignal,
  type HilosMaintenanceState,
} from '@hilos/core/base'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_RESTORE_CLOSE_COPY,
  HILOS_MAINTENANCE_RESTORE_OPEN_COPY,
  HILOS_MAINTENANCE_RESTORE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  hilosMaintenanceElapsed,
  hilosMaintenanceCard,
  hilosMaintenanceOtherBrowserLine,
  hilosMaintenancePosition,
  hilosMaintenanceRestoreLadder,
  hilosMaintenanceWatchdogAlert,
  hilosMaintenanceWatchdogThresholds,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'

import {
  HilosActionError,
  HilosAdminPage,
  HilosHideable,
  HilosModal,
  LoadingButton,
  createHilosTrackedAction,
  hilosSignal,
} from '@hilos/angular/base'

import { HilosMaintenanceWatchdogAlert } from './HilosMaintenanceWatchdogAlert.js'

/**
 * How a confirmed action reports a failure: a refusal in the server's own words,
 * anything else as the action's note that its outcome is unconfirmed.
 *
 * @param unconfirmedNote The action's own note for an outcome nobody confirmed.
 */
function describeActionError(
  unconfirmedNote: string,
): (error: unknown) => string {
  return (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : unconfirmedNote
}

/**
 * The protected-mode block, with dialogs that close only on the server's answer.
 */
@Component({
  selector: 'hilos-maintenance-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosAdminPage,
    HilosHideable,
    HilosMaintenanceWatchdogAlert,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <hilos-maintenance-watchdog-alert [alert]="watchdogAlert()" />

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
                    <code data-id="hilos-maintenance-started-at">{{
                      formatTime(card.startedAt)
                    }}</code>
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
                  <dt class="col-sm-3">{{ stateCopy.initiator }}</dt>
                  <dd class="col-sm-9" data-id="hilos-maintenance-initiator">
                    <code>{{ card.initiatorCaption }}</code>
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.started }}</dt>
                  <dd class="col-sm-9">
                    <code data-id="hilos-maintenance-started-at">{{
                      formatTime(card.startedAt)
                    }}</code>
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.frozen }}</dt>
                  <dd class="col-sm-9">
                    <code data-id="hilos-maintenance-activated-at">{{
                      formatTime(card.activatedAt)
                    }}</code>
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.lastMovement }}</dt>
                  <dd class="col-sm-9">
                    <code data-id="hilos-maintenance-progress-at">{{
                      formatTime(card.progressAt)
                    }}</code>
                    @if (hasProgressMoment(card.progressAt)) {
                      <span
                        class="text-body-secondary ms-2"
                        data-id="hilos-maintenance-progress-elapsed"
                        >{{ progressElapsed(card.progressAt) }}</span
                      >
                    }
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.agentsStopped }}</dt>
                  <dd
                    class="col-sm-9"
                    data-id="hilos-maintenance-stopped-agents"
                  >
                    {{ card.stoppedAgentsCaption }}
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.diagnosticsNode }}</dt>
                  <dd
                    class="col-sm-9"
                    data-id="hilos-maintenance-diagnostics-node"
                  >
                    <code>{{ card.diagnosticsNodeId ?? '—' }}</code>
                  </dd>
                  <dt class="col-sm-3">{{ stateCopy.agentStart }}</dt>
                  <dd class="col-sm-9" data-id="hilos-maintenance-agent-start">
                    {{ card.agentStartCaption }}
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
                    [loading]="passAction.busy()"
                    data-id="hilos-maintenance-pass"
                    (click)="openPass()"
                  >
                    {{ passCopy.issue }}
                  </button>
                  <button
                    hilosLoadingButton
                    class="btn-warning btn-sm"
                    [loading]="disableAction.busy()"
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

      @if (restoreLadder() === 'initiator') {
        <div class="card mb-3" data-id="hilos-maintenance-restore-ladder">
          <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <button
              hilosLoadingButton
              class="btn-outline-primary btn-sm"
              [loading]="restorePassAction.busy()"
              data-id="hilos-maintenance-restore-pass"
              (click)="openRestorePass()"
            >
              {{ restorePassCopy.issue }}
            </button>
            <button
              hilosLoadingButton
              class="btn-warning btn-sm"
              [loading]="restoreOpenAction.busy()"
              data-id="hilos-maintenance-restore-open"
              (click)="openRestoreOpen()"
            >
              {{ restoreOpenCopy.issue }}
            </button>
            <button
              hilosLoadingButton
              class="btn-outline-danger btn-sm ms-auto"
              [loading]="restoreCloseAction.busy()"
              data-id="hilos-maintenance-restore-close"
              (click)="openRestoreClose()"
            >
              {{ restoreCloseCopy.issue }}
            </button>
          </div>
        </div>
      } @else if (restoreLadder() === 'other') {
        <div
          class="alert alert-secondary small mb-3"
          role="status"
          data-id="hilos-maintenance-other-browser"
        >
          <i class="bi bi-person-workspace me-1" aria-hidden="true"></i>
          {{ otherBrowserLine() }}
        </div>
      }

      @if (watchdogThresholds(); as thresholds) {
        <section
          class="card mb-3"
          data-id="hilos-maintenance-watchdog-thresholds"
        >
          <div class="card-header">
            <h2 class="h6 mb-0">{{ thresholds.title }}</h2>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              @for (row of thresholds.rows; track row.label) {
                <dt class="col-sm-5">{{ row.label }}</dt>
                <dd class="col-sm-7 text-break">
                  <hilos-hideable [value]="row.value" />
                </dd>
              }
            </dl>
          </div>
          <div class="card-footer small text-body-secondary">
            {{ thresholds.footer }}
          </div>
        </section>
      }

      <hilos-modal
        [open]="enableOpen()"
        (openChange)="$event || closeEnable()"
        [title]="enableCopy.enableTitle"
        [busy]="enableAction.busy()"
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
            [loading]="enableAction.busy()"
            [disabled]="position() !== 'open'"
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
        [busy]="disableAction.busy()"
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
            [loading]="disableAction.busy()"
            [disabled]="position() !== 'manual'"
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
        [busy]="passAction.busy()"
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
              [loading]="passAction.busy()"
              [disabled]="position() !== 'manual'"
              data-id="hilos-maintenance-pass-confirm"
              (click)="submitPass()"
            >
              {{ passCopy.mint }}
            </button>
          }
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="restorePassOpen()"
        (openChange)="$event || closeRestorePass()"
        [title]="
          restorePassStep() === 'confirm'
            ? restorePassCopy.mintTitle
            : restorePassCopy.codeTitle
        "
        [busy]="restorePassAction.busy()"
        [copyText]="restorePass() ?? ''"
        initialFocus="dialog"
      >
        <div data-id="hilos-maintenance-restore-pass-modal">
          @if (restorePassStep() === 'confirm') {
            <hilos-action-error
              [action]="restorePassAction"
              [detailsTitle]="restorePassCopy.refusalTitle"
            />
            <p class="mb-2">{{ restorePassCopy.lead }}</p>
            <p class="mb-0 text-body-secondary">
              {{ restorePassCopy.reuseNote }}
            </p>
          } @else {
            <div data-id="hilos-maintenance-restore-pass-result">
              <p class="mb-2 text-body-secondary">
                {{ restorePassCopy.resultLead }}
              </p>
              <div
                #restorePassValue
                tabindex="-1"
                class="border rounded-3 p-3 bg-body-tertiary text-center user-select-all text-break"
                data-id="hilos-maintenance-restore-pass-value"
              >
                <code class="fs-5">{{ restorePass() }}</code>
              </div>
              <p class="small text-body-secondary mt-2 mb-0">
                {{ restorePassCopy.resultNote }}
              </p>
            </div>
          }
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="restorePassAction.busy()"
            data-id="hilos-maintenance-restore-pass-cancel"
            (click)="requestClose()"
          >
            {{
              restorePassStep() === 'confirm'
                ? restorePassCopy.cancel
                : restorePassCopy.close
            }}
          </button>
          @if (restorePassStep() === 'confirm') {
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="restorePassAction.busy()"
              [disabled]="restoreLadder() !== 'initiator'"
              data-id="hilos-maintenance-restore-pass-confirm"
              (click)="submitRestorePass()"
            >
              {{ restorePassCopy.mint }}
            </button>
          }
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="restoreOpenOpen()"
        (openChange)="$event || closeRestoreOpen()"
        [title]="restoreOpenCopy.title"
        [busy]="restoreOpenAction.busy()"
        initialFocus="dialog"
      >
        <div data-id="hilos-maintenance-restore-open-modal">
          <hilos-action-error
            [action]="restoreOpenAction"
            [detailsTitle]="restoreOpenCopy.refusalTitle"
          />
          <p class="mb-2">{{ restoreOpenCopy.lead }}</p>
          <p class="mb-0 text-body-secondary">
            {{ restoreOpenCopy.noWayBack }}
          </p>
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="restoreOpenAction.busy()"
            data-id="hilos-maintenance-restore-open-cancel"
            (click)="requestClose()"
          >
            {{ restoreOpenCopy.cancel }}
          </button>
          <button
            hilosLoadingButton
            class="btn-warning"
            [loading]="restoreOpenAction.busy()"
            [disabled]="restoreLadder() !== 'initiator'"
            data-id="hilos-maintenance-restore-open-confirm"
            (click)="submitRestoreOpen()"
          >
            {{ restoreOpenCopy.confirm }}
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="restoreCloseOpen()"
        (openChange)="$event || closeRestoreClose()"
        [title]="restoreCloseCopy.title"
        [busy]="restoreCloseAction.busy()"
        initialFocus="dialog"
      >
        <div data-id="hilos-maintenance-restore-close-modal">
          <hilos-action-error
            [action]="restoreCloseAction"
            [detailsTitle]="restoreCloseCopy.refusalTitle"
          />
          <p class="mb-2">{{ restoreCloseCopy.lead }}</p>
          <p class="mb-0 text-body-secondary">
            {{ restoreCloseCopy.footer }}
          </p>
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="restoreCloseAction.busy()"
            data-id="hilos-maintenance-restore-close-cancel"
            (click)="requestClose()"
          >
            {{ restoreCloseCopy.cancel }}
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="restoreCloseAction.busy()"
            [disabled]="restoreLadder() !== 'initiator'"
            data-id="hilos-maintenance-restore-close-confirm"
            (click)="submitRestoreClose()"
          >
            {{ restoreCloseCopy.confirm }}
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
  protected readonly stateCopy = HILOS_MAINTENANCE_STATE_COPY
  protected readonly enableCopy = HILOS_MAINTENANCE_ENABLE_COPY
  protected readonly disableCopy = HILOS_MAINTENANCE_DISABLE_COPY
  protected readonly passCopy = HILOS_MAINTENANCE_PASS_COPY
  protected readonly restorePassCopy = HILOS_MAINTENANCE_RESTORE_PASS_COPY
  protected readonly restoreOpenCopy = HILOS_MAINTENANCE_RESTORE_OPEN_COPY
  protected readonly restoreCloseCopy = HILOS_MAINTENANCE_RESTORE_CLOSE_COPY
  private readonly timeZone = hilosSignal(hilosTimeZone)

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
  // Whether this browser leads the restore is the server's verdict, never a local pick.
  protected readonly restoreLadder = computed(() =>
    hilosMaintenanceRestoreLadder(this.maintenanceState()),
  )
  protected readonly otherBrowserLine = computed(() => {
    const state = this.maintenanceState()

    return state === null ? '' : hilosMaintenanceOtherBrowserLine(state)
  })
  // The verdict is the server's; the zone is read so its moments follow the reader.
  protected readonly watchdogAlert = computed(() => {
    this.timeZone()

    return hilosMaintenanceWatchdogAlert(this.maintenanceState())
  })
  protected readonly watchdogThresholds = computed(() =>
    hilosMaintenanceWatchdogThresholds(this.maintenanceState()),
  )

  /** Format an absolute maintenance moment in the reader's current time zone. */
  protected formatTime(moment: string | null): string {
    this.timeZone()

    return formatMoment(moment ?? '', { kind: 'dateTime' }) ?? '—'
  }

  /** Whether a progress mark is present and can be shown as an elapsed time. */
  protected hasProgressMoment(moment: string | null): boolean {
    return moment !== null && moment !== '' && this.formatTime(moment) !== '—'
  }

  /** The elapsed time since the latest progress mark, when it is valid. */
  protected progressElapsed(moment: string | null): string {
    if (!this.hasProgressMoment(moment)) {
      return '—'
    }

    return hilosMaintenanceElapsed(moment, this.nowMs())
  }

  // A state push may replace the open banner while this action still awaits its ack.
  protected readonly enableOpen = signal(false)
  protected readonly enableAction = createHilosTrackedAction({
    describeError: describeActionError(
      HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote,
    ),
  })

  // The dialog survives a state push; the shared lift may reload before its ack arrives.
  protected readonly disableOpen = signal(false)
  protected readonly disableAction = createHilosTrackedAction({
    describeError: describeActionError(
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
    describeError: describeActionError(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    ),
  })

  // The clear restore code belongs only to this dialog's result and is forgotten on every exit.
  protected readonly restorePassOpen = signal(false)
  protected readonly restorePass = signal<string | null>(null)
  protected readonly restorePassStep = signal<'confirm' | 'result'>('confirm')
  private readonly restorePassValue =
    viewChild<ElementRef<HTMLElement>>('restorePassValue')
  private restorePassDisposed = false
  protected readonly restorePassAction = createHilosTrackedAction({
    describeError: describeActionError(
      HILOS_MAINTENANCE_RESTORE_PASS_COPY.unconfirmedNote,
    ),
  })

  // Opening ends the restore; the reload it brings may take this page before its ack.
  protected readonly restoreOpenOpen = signal(false)
  protected readonly restoreOpenAction = createHilosTrackedAction({
    describeError: describeActionError(
      HILOS_MAINTENANCE_RESTORE_OPEN_COPY.unconfirmedNote,
    ),
  })

  // The full freeze it brings back may take this page before its ack.
  protected readonly restoreCloseOpen = signal(false)
  protected readonly restoreCloseAction = createHilosTrackedAction({
    describeError: describeActionError(
      HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.unconfirmedNote,
    ),
  })

  private readonly actions = computed(() =>
    createHilosMaintenanceActions(this.context()),
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
      this.restorePassDisposed = true
      this.restorePass.set(null)
      this.restorePassOpen.set(false)
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

  protected openRestorePass(): void {
    if (
      this.restorePassDisposed ||
      this.restorePassAction.busy() ||
      this.restoreLadder() !== 'initiator'
    ) {
      return
    }
    this.restorePass.set(null)
    this.restorePassStep.set('confirm')
    this.restorePassAction.clearError()
    this.restorePassOpen.set(true)
  }

  protected closeRestorePass(): void {
    if (this.restorePassAction.busy()) {
      return
    }
    this.restorePassOpen.set(false)
    this.restorePass.set(null)
    this.restorePassStep.set('confirm')
  }

  protected async submitRestorePass(): Promise<void> {
    if (
      this.restorePassDisposed ||
      !this.restorePassOpen() ||
      this.restorePassAction.busy() ||
      this.restorePassStep() !== 'confirm' ||
      this.restoreLadder() !== 'initiator'
    ) {
      return
    }
    const handle = this.actions().sendMaintenanceRestorePass()
    if (await this.restorePassAction.run(handle)) {
      const result = await handle.done
      if (this.restorePassDisposed || !this.restorePassOpen()) {
        return
      }
      this.restorePass.set(result.reply!.pass)
      this.restorePassStep.set('result')
      // The value takes focus once the result is on screen.
      afterNextRender(() => this.restorePassValue()?.nativeElement.focus(), {
        injector: this.injector,
      })
    }
  }

  protected openRestoreOpen(): void {
    if (this.restoreOpenAction.busy() || this.restoreLadder() !== 'initiator') {
      return
    }
    this.restoreOpenAction.clearError()
    this.restoreOpenOpen.set(true)
  }

  protected closeRestoreOpen(): void {
    if (!this.restoreOpenAction.busy()) {
      this.restoreOpenOpen.set(false)
    }
  }

  protected async submitRestoreOpen(): Promise<void> {
    if (this.restoreOpenAction.busy() || this.restoreLadder() !== 'initiator') {
      return
    }
    if (
      await this.restoreOpenAction.run(
        this.actions().sendMaintenanceRestoreOpen(),
      )
    ) {
      this.restoreOpenOpen.set(false)
    }
  }

  protected openRestoreClose(): void {
    if (
      this.restoreCloseAction.busy() ||
      this.restoreLadder() !== 'initiator'
    ) {
      return
    }
    this.restoreCloseAction.clearError()
    this.restoreCloseOpen.set(true)
  }

  protected closeRestoreClose(): void {
    if (!this.restoreCloseAction.busy()) {
      this.restoreCloseOpen.set(false)
    }
  }

  protected async submitRestoreClose(): Promise<void> {
    if (
      this.restoreCloseAction.busy() ||
      this.restoreLadder() !== 'initiator'
    ) {
      return
    }
    if (
      await this.restoreCloseAction.run(
        this.actions().sendMaintenanceRestoreClose(),
      )
    ) {
      this.restoreCloseOpen.set(false)
    }
  }
}
