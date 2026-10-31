import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_MODERATION_RULE_DELETE_COPY,
  type HilosModerationRuleDelete as RuleDelete,
} from '@hilos/core/admin/moderation'
import {
  hilosToasts,
  subscribeSignal,
  type ActionHandle,
} from '@hilos/core/base'
import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  createHilosTrackedAction,
  injectLookOnly,
  mirrorHilosSignal,
  HilosEditNotice,
} from '@hilos/angular/base'

/** Internal delete window over the core moderation rule factory. */
@Component({
  selector: 'hilos-moderation-rule-delete',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosModal, LoadingButton, HilosEditNotice],
  template: `
    <hilos-modal
      [open]="opened()"
      (openChange)="$event || remove().close()"
      [title]="title()"
      [busy]="busy()"
      initialFocus="dialog"
    >
      <div data-id="hilos-moderation-rule-delete">
        <div data-id="hilos-moderation-rule-delete-error">
          <hilos-action-error
            [action]="action()"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        @if (target(); as held) {
          <p class="mb-2">{{ copy.body(held.title) }}</p>
          <p
            class="mb-0 text-truncate text-body-secondary"
            [title]="held.expectedRule"
            data-id="hilos-moderation-rule-delete-rule"
          >
            {{ held.expectedRule }}
          </p>
        }
        <hilos-edit-notice
          [kind]="notice()?.kind ?? null"
          [text]="notice()?.text ?? ''"
          dataId="hilos-moderation-rule-delete-notice"
        />
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="hilos-moderation-rule-delete-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          type="button"
          (click)="submit()"
          [loading]="busy()"
          [disabled]="!submitAvailable() || lookOnly.locked()"
          data-id="hilos-moderation-rule-delete-confirm"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosModerationRuleDelete {
  /** The ready-made window owned by the Rules page. */
  readonly remove = input.required<RuleDelete>()

  protected readonly copy = HILOS_MODERATION_RULE_DELETE_COPY
  protected readonly lookOnly = injectLookOnly()
  protected readonly opened = signal(false)
  protected readonly target =
    signal<ReturnType<RuleDelete['target']['get']>>(null)
  protected readonly saving = signal(false)
  protected readonly submitAvailable = signal(false)
  protected readonly action = signal(createHilosTrackedAction({ toast: false }))
  protected readonly busy = computed(
    () => this.saving() || this.action().busy(),
  )
  protected readonly notice =
    signal<ReturnType<RuleDelete['notice']['get']>>(null)
  protected readonly title = computed(() => this.copy.title)

  constructor() {
    effect((onCleanup) => {
      const window = this.remove()
      const off = [
        mirrorHilosSignal(window.openState, this.opened),
        mirrorHilosSignal(window.target, this.target),
        mirrorHilosSignal(window.saving, this.saving),
        mirrorHilosSignal(window.submitAvailable, this.submitAvailable),
        mirrorHilosSignal(window.notice, this.notice),
        subscribeSignal(window.target, () =>
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

  protected async submit(): Promise<void> {
    const window = this.remove()
    if (this.lookOnly.locked() || !window.canSubmit.get()) return
    const action = this.action()
    const target = window.target.get()
    await window.submit(async (handle: ActionHandle) => {
      const success = await action.run(handle)
      if (success) {
        const { message } = await handle.done
        if (
          this.action() === action &&
          window.target.get() === target &&
          message !== undefined
        ) {
          hilosToasts.push(message, { severity: 'success' })
        }
      }
      return success
    })
  }
}
