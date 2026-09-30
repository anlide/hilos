import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  effect,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  focusInitial,
  HILOS_PROFILE_RENAME_COPY,
  HILOS_STEP_UP_COPY,
  openRowEdit,
  resolveRowEdit,
  subscribeSignal,
  type HilosProfileRenameFields,
  type HilosProfileRenameFlow,
  type HilosProfileRenameStep,
  type HilosStepUpOpening,
  type ReadonlySignal,
  type Unsubscribe,
} from '@hilos/core'
import { ConflictActions } from '../ConflictActions.js'
import { ConflictHeader } from '../ConflictHeader.js'
import { HilosEditNotice } from '../HilosEditNotice.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'

/**
 * Put a core signal's value into an Angular one now and on every change.
 *
 * @param source The core signal.
 * @param target The Angular signal that mirrors it.
 */
function mirror<T>(
  source: ReadonlySignal<T>,
  target: { set(value: T): void },
): Unsubscribe {
  target.set(source.get())
  return subscribeSignal(source, (value) => target.set(value))
}

/** Draw the profile root's name window over the project's rename (HIL-1169). */
@Component({
  selector: 'hilos-profile-rename',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ConflictActions,
    ConflictHeader,
    HilosEditNotice,
    HilosFormError,
    HilosModal,
    HilosStepUpStep,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="step() !== 'closed'"
      (openChange)="onOpenChange($event)"
      [confirmOnClose]="asksBeforeClosing()"
      initialFocus="inner"
    >
      <h5
        hilosConflictHeader
        modalHeader
        [title]="step() === 'form' ? copy.title : stepUpCopy.title"
        [conflict]="step() === 'form' && edit().conflict"
      ></h5>
      <!-- This dialog's own voice: the page region behind it is under the
      backdrop, and the dialog is aria-modal, so from inside here that region is
      not there to be read. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="profile-rename-live-assertive"
      >
        {{ step() === 'form' ? refusal() : stepUpRefusal() }}
      </div>
      <div #body>
        @if (step() !== 'form') {
          <form
            id="hilos-profile-rename-step-up"
            data-id="profile-name-step-up"
            (submit)="$event.preventDefault(); flow().confirmStepUp()"
          >
            <hilos-step-up-step [controller]="flow().stepUp" />
          </form>
        } @else {
          <form (submit)="$event.preventDefault(); flow().save()">
            <label class="form-label" for="profile-name-field">{{
              copy.label
            }}</label>
            <input
              id="profile-name-field"
              type="text"
              class="form-control"
              data-autofocus
              data-id="profile-name-input"
              [attr.minlength]="flow().minLength"
              [attr.maxlength]="flow().maxLength"
              [value]="draft()"
              (input)="flow().draft.set(valueOf($event))"
            />
            <div class="form-text">{{ bounds() }}</div>
            <hilos-edit-notice
              [kind]="edit().notice?.kind ?? null"
              [text]="notice()"
              dataId="profile-edit-notice"
            />
            <hilos-form-error
              [message]="refusal()"
              dataId="profile-rename-error"
            />
          </form>
        }
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        @if (step() !== 'form') {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-name-step-up-cancel"
            (click)="requestClose()"
          >
            {{ copy.cancel }}
          </button>
          @if (stepUpOpening() !== null) {
            <button
              hilosLoadingButton
              type="submit"
              form="hilos-profile-rename-step-up"
              class="btn-primary"
              [loading]="stepUpBusy()"
              data-id="profile-name-step-up-confirm"
            >
              {{ stepUpCopy.confirm }}
            </button>
          }
        } @else {
          <div
            hilosConflictActions
            [conflict]="edit().conflict"
            [disableSave]="!valid() || !edit().dirty || busy() || edit().gone"
            [saveLabel]="saveLabel()"
            (save)="flow().save()"
            (acceptMine)="flow().keepMine()"
            (acceptTheirs)="flow().takeTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-outline-secondary"
                [disabled]="busy()"
                data-id="profile-rename-cancel"
                (click)="requestClose()"
              >
                {{ copy.cancel }}
              </button>
            </ng-template>
            <ng-template
              #saveButton
              let-disabled="disabled"
              let-onSave="onSave"
            >
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="busy()"
                [disabled]="disabled"
                data-id="profile-rename-save"
                (click)="onSave()"
              >
                {{ saveLabel() }}
              </button>
            </ng-template>
          </div>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosProfileRename {
  readonly flow = input.required<HilosProfileRenameFlow>()
  protected readonly copy = HILOS_PROFILE_RENAME_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly step = signal<HilosProfileRenameStep>('closed')
  protected readonly draft = signal('')
  protected readonly edit = signal(
    resolveRowEdit<HilosProfileRenameFields>(
      undefined,
      openRowEdit({ name: '' }),
      { name: '' },
    ),
  )
  protected readonly notice = signal('')
  protected readonly valid = signal(false)
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly asksBeforeClosing = signal(false)
  protected readonly stepUpOpening = signal<HilosStepUpOpening | null>(null)
  protected readonly stepUpBusy = signal(false)
  protected readonly stepUpRefusal = signal<string | null>(null)
  private readonly body = viewChild<ElementRef<HTMLElement>>('body')
  private previous: HilosProfileRenameStep = 'closed'
  protected readonly saveLabel = computed(() =>
    this.edit().gone ? this.copy.deleted : this.copy.save,
  )
  protected readonly bounds = computed(() =>
    this.copy.bounds
      .replace('{min}', String(this.flow().minLength))
      .replace('{max}', String(this.flow().maxLength)),
  )

  constructor() {
    effect((onCleanup) => {
      const flow = this.flow()
      const off = [
        mirror(flow.step, this.step),
        mirror(flow.draft, this.draft),
        mirror(flow.edit, this.edit),
        mirror(flow.notice, this.notice),
        mirror(flow.valid, this.valid),
        mirror(flow.busy, this.busy),
        mirror(flow.refusal, this.refusal),
        mirror(flow.asksBeforeClosing, this.asksBeforeClosing),
        mirror(flow.stepUp.opening, this.stepUpOpening),
        mirror(flow.stepUp.busy, this.stepUpBusy),
        mirror(flow.stepUp.refusal, this.stepUpRefusal),
      ]
      onCleanup(() => off.forEach((stop) => stop()))
    })
    // The confirmation's body is replaced by the form: focus its field.
    afterRenderEffect(() => {
      const step = this.step()
      const from = this.previous
      this.previous = step
      if (from !== 'step-up' || step !== 'form') return
      const dialog =
        this.body()?.nativeElement.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog)
    })
  }
  protected onOpenChange(open: boolean): void {
    if (!open) this.flow().close()
  }
  protected valueOf(event: Event): string {
    return (event.target as HTMLInputElement).value
  }
}
