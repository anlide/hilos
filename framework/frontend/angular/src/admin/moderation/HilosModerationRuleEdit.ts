import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_MODERATION_RULE_EDIT_COPY,
  type HilosModerationRuleEdit as RuleEdit,
} from '@hilos/core/admin/moderation'
import {
  hilosRowEditIdle,
  hilosToasts,
  subscribeSignal,
  type ActionHandle,
} from '@hilos/core/base'
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  createHilosTrackedAction,
  injectLookOnly,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** Internal edit window over the core row-edit session and focused rule. */
@Component({
  selector: 'hilos-moderation-rule-edit',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ConflictActions,
    ConflictHeader,
    HilosActionError,
    HilosEditNotice,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="opened()"
      (openChange)="$event || edit().close()"
      [aria-label]="title()"
      [busy]="busy()"
      [confirmOnClose]="dirty()"
    >
      <h5
        hilosConflictHeader
        modalHeader
        [title]="title()"
        [conflict]="state().conflict"
      ></h5>
      <div data-id="hilos-moderation-rule-edit">
        <div data-id="hilos-moderation-rule-edit-error">
          <hilos-action-error
            [action]="action()"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <form id="hilos-moderation-rule-edit-form" (submit)="submit($event)">
          <label class="form-label" for="hilos-moderation-rule-edit-rule">{{
            copy.ruleLabel
          }}</label>
          <textarea
            id="hilos-moderation-rule-edit-rule"
            class="form-control"
            rows="4"
            [value]="form().rule"
            data-id="hilos-moderation-rule-edit-rule"
            data-autofocus
            (input)="changeRule($event)"
          ></textarea>
          <hilos-edit-notice
            [kind]="notice()?.kind ?? null"
            [text]="notice()?.text ?? ''"
            dataId="hilos-moderation-rule-edit-notice"
          />
        </form>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <div
          hilosConflictActions
          [conflict]="state().conflict"
          [disableSave]="!saveAvailable()"
          [saveLabel]="saveLabel()"
          (save)="submit()"
          (acceptMine)="edit().keepMine()"
          (acceptTheirs)="edit().takeTheirs()"
        >
          <ng-template #cancelButton>
            <button
              type="button"
              class="btn btn-secondary"
              [disabled]="busy()"
              data-id="hilos-moderation-rule-edit-cancel"
              (click)="requestClose()"
            >
              {{ copy.cancel }}
            </button>
          </ng-template>
          <ng-template #saveButton let-disabled="disabled" let-onSave="onSave">
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="busy()"
              [disabled]="disabled"
              data-id="hilos-moderation-rule-edit-submit"
              (click)="onSave()"
            >
              {{ saveLabel() }}
            </button>
          </ng-template>
        </div>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosModerationRuleEdit {
  /** The ready-made window owned by the Rules page. */
  readonly edit = input.required<RuleEdit>()

  protected readonly copy = HILOS_MODERATION_RULE_EDIT_COPY
  protected readonly lookOnly = injectLookOnly()
  protected readonly opened = signal(false)
  protected readonly target =
    signal<ReturnType<RuleEdit['target']['get']>>(null)
  protected readonly form = signal({ rule: '' })
  protected readonly state = signal(hilosRowEditIdle({ rule: '' }))
  protected readonly dirty = signal(false)
  protected readonly notice =
    signal<ReturnType<RuleEdit['notice']['get']>>(null)
  protected readonly saving = signal(false)
  protected readonly saveAvailable = signal(false)
  protected readonly saveLabel = signal('Save')
  protected readonly action = signal(createHilosTrackedAction({ toast: false }))
  protected readonly busy = computed(
    () => this.saving() || this.action().busy(),
  )
  protected readonly title = computed(() => {
    const target = this.target()
    return target ? this.copy.title(target.title) : this.copy.open
  })

  constructor() {
    effect((onCleanup) => {
      const edit = this.edit()
      const off = [
        mirrorHilosSignal(edit.opened, this.opened),
        mirrorHilosSignal(edit.target, this.target),
        mirrorHilosSignal(edit.form, this.form),
        mirrorHilosSignal(edit.state, this.state),
        mirrorHilosSignal(edit.dirty, this.dirty),
        mirrorHilosSignal(edit.notice, this.notice),
        mirrorHilosSignal(edit.saving, this.saving),
        mirrorHilosSignal(edit.saveAvailable, this.saveAvailable),
        mirrorHilosSignal(edit.saveLabel, this.saveLabel),
        subscribeSignal(edit.target, () =>
          this.action.set(createHilosTrackedAction({ toast: false })),
        ),
      ]
      this.action.set(createHilosTrackedAction({ toast: false }))
      onCleanup(() => {
        for (const stop of off) stop()
        this.action.set(createHilosTrackedAction({ toast: false }))
      })
    })
  }

  protected changeRule(event: Event): void {
    if (event.target instanceof HTMLTextAreaElement) {
      this.edit().patchForm({ rule: event.target.value })
    }
  }

  protected async submit(event?: Event): Promise<void> {
    event?.preventDefault()
    const edit = this.edit()
    if (this.lookOnly.locked() || !edit.canSave.get()) return
    const action = this.action()
    const target = edit.target.get()
    await edit.save(async (handle: ActionHandle) => {
      const success = await action.run(handle)
      if (success) {
        const { message } = await handle.done
        if (
          this.action() === action &&
          edit.target.get() === target &&
          message !== undefined
        ) {
          hilosToasts.push(message, { severity: 'success' })
        }
      }
      return success
    })
  }
}
