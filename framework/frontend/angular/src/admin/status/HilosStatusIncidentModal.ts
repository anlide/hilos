// The core window keeps the opening incident identity, draft and tracked reply.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { type ActionError } from '@hilos/core/base'
import {
  HILOS_STATUS_ACTION_COPY,
  type HilosStatusIncidentController,
  type HilosStatusIncidentDialog,
  type HilosStatusIncidentCard,
} from '@hilos/core/admin/status'
import {
  HilosEditNotice,
  HilosFormError,
  HilosModal,
  LoadingButton,
  mirrorHilosSignal,
} from '@hilos/angular/base'

let incidentFormSequence = 0

/** Confirm publication or append a plain-text update to a captured incident. */
@Component({
  selector: 'hilos-status-incident-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosEditNotice, HilosFormError, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="dialog() !== null"
      [title]="copy()?.title ?? ''"
      [busy]="busy()"
      [initialFocus]="dialog()?.operation === 'add-update' ? 'inner' : 'dialog'"
      (openChange)="controller().close()"
    >
      @if (dialog(); as dialog) {
        @if (copy(); as copy) {
          <form
            [id]="formId"
            data-id="hilos-status-incident-form"
            (submit)="onSubmit($event)"
            (keydown)="onEnter($event)"
          >
            <hilos-form-error
              [message]="error()"
              [errorType]="failure()?.errorType ?? null"
              [errorDetail]="failure()?.errorDetail ?? null"
              [detailsTitle]="copy.refusalTitle"
              [copyText]="copyText()"
              dataId="hilos-status-incident-error"
              [announce]="true"
            />
            <p
              class="fw-semibold text-break"
              data-id="hilos-status-incident-target"
            >
              {{ dialog.incident.componentName }} · #{{
                dialog.incident.incidentId
              }}
            </p>
            <p>{{ copy.lead }}</p>
            @if (target(); as target) {
              <p
                class="small text-body-secondary"
                data-id="hilos-status-incident-time"
              >
                {{ target.label }} ·
                {{ target.publishedAtLabel ?? target.publishAtLabel }}
              </p>
            }
            <hilos-edit-notice
              [kind]="blockedReason() ? 'deleted' : null"
              [text]="blockedReason() ?? ''"
              dataId="hilos-status-incident-blocked"
            />
            @if (dialog.operation === 'add-update') {
              <label [for]="fieldId" class="form-label">{{
                actionCopy.updateField
              }}</label>
              <textarea
                [id]="fieldId"
                [value]="draft()"
                class="form-control"
                rows="5"
                [placeholder]="actionCopy.updatePlaceholder"
                data-id="hilos-status-update-text"
                data-autofocus
                (input)="onDraft($event)"
              ></textarea>
              <p class="small text-body-secondary mb-0 mt-2">
                Ctrl/Command+Enter to publish.
              </p>
            }
          </form>
        }
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="hilos-status-incident-cancel"
          (click)="requestClose()"
        >
          {{ actionCopy.cancel }}
        </button>
        <button
          hilosLoadingButton
          [attr.form]="formId"
          type="submit"
          [class]="
            dialog()?.operation === 'add-update' ? 'btn-primary' : 'btn-danger'
          "
          [loading]="busy()"
          [disabled]="!busy() && !canSubmit()"
          data-id="hilos-status-incident-confirm"
        >
          {{ copy()?.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosStatusIncidentModal {
  /** The page-owned window; replacing it releases only this adapter's mirrors. */
  readonly controller = input.required<HilosStatusIncidentController>()

  protected readonly formId = `hilos-status-incident-form-${incidentFormSequence++}`
  protected readonly fieldId = `${this.formId}-text`
  protected readonly actionCopy = HILOS_STATUS_ACTION_COPY
  protected readonly dialog = signal<HilosStatusIncidentDialog | null>(null)
  protected readonly target = signal<HilosStatusIncidentCard | null>(null)
  protected readonly draft = signal('')
  protected readonly busy = signal(false)
  protected readonly error = signal<string | null>(null)
  protected readonly failure = signal<ActionError | null>(null)
  protected readonly blockedReason = signal<string | null>(null)
  protected readonly canSubmit = signal(false)
  protected readonly copy = computed(() => {
    const dialog = this.dialog()
    return dialog ? HILOS_STATUS_ACTION_COPY[dialog.operation] : null
  })
  protected readonly copyText = computed(() => {
    const failure = this.failure()
    return failure?.errorDetail
      ? [failure.errorType, failure.errorDetail].filter(Boolean).join('\n')
      : (this.error() ?? '')
  })

  constructor() {
    effect((onCleanup) => {
      const controller = this.controller()
      const stops = [
        mirrorHilosSignal(controller.dialog, this.dialog),
        mirrorHilosSignal(controller.target, this.target),
        mirrorHilosSignal(controller.draft, this.draft),
        mirrorHilosSignal(controller.busy, this.busy),
        mirrorHilosSignal(controller.error, this.error),
        mirrorHilosSignal(controller.failure, this.failure),
        mirrorHilosSignal(controller.blockedReason, this.blockedReason),
        mirrorHilosSignal(controller.canSubmit, this.canSubmit),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
      })
    })
  }

  protected onDraft(event: Event): void {
    this.controller().setDraft((event.target as HTMLTextAreaElement).value)
  }

  protected onSubmit(event: Event): void {
    event.preventDefault()
    void this.controller().submit()
  }

  /** Enter confirms; a multiline update uses Ctrl/Command+Enter. */
  protected onEnter(event: Event): void {
    const key = event as KeyboardEvent
    if (key.key !== 'Enter') return
    if (key.target instanceof Element && key.target.closest('button, a')) return
    if (
      this.dialog()?.operation === 'add-update' &&
      !key.ctrlKey &&
      !key.metaKey
    )
      return
    key.preventDefault()
    void this.controller().submit()
  }
}
