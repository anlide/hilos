import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_MODERATION_RULE_ADD_COPY,
  type HilosModerationRuleAdd as RuleAdd,
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
} from '@hilos/angular/base'

/** Internal add window over the core moderation rule factory. */
@Component({
  selector: 'hilos-moderation-rule-add',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="opened()"
      (openChange)="$event || add().close()"
      [title]="title()"
      [busy]="busy()"
      [confirmOnClose]="dirty()"
    >
      <div data-id="hilos-moderation-rule-add">
        <div data-id="hilos-moderation-rule-add-error">
          <hilos-action-error
            [action]="action()"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <form id="hilos-moderation-rule-add-form" (submit)="submit($event)">
          <label class="form-label" for="hilos-moderation-rule-add-rule">{{
            copy.ruleLabel
          }}</label>
          <textarea
            id="hilos-moderation-rule-add-rule"
            class="form-control"
            rows="4"
            [value]="rule()"
            data-id="hilos-moderation-rule-add-rule"
            data-autofocus
            (input)="changeRule($event)"
          ></textarea>
        </form>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="hilos-moderation-rule-add-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-primary"
          type="submit"
          form="hilos-moderation-rule-add-form"
          [loading]="busy()"
          [disabled]="!submitAvailable() || lookOnly.locked()"
          data-id="hilos-moderation-rule-add-submit"
        >
          {{ copy.save }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosModerationRuleAdd {
  /** The ready-made window owned by the Rules page. */
  readonly add = input.required<RuleAdd>()

  protected readonly copy = HILOS_MODERATION_RULE_ADD_COPY
  protected readonly lookOnly = injectLookOnly()
  protected readonly opened = signal(false)
  protected readonly target = signal<ReturnType<RuleAdd['target']['get']>>(null)
  protected readonly saving = signal(false)
  protected readonly submitAvailable = signal(false)
  protected readonly action = signal(createHilosTrackedAction({ toast: false }))
  protected readonly busy = computed(
    () => this.saving() || this.action().busy(),
  )
  protected readonly rule = signal('')
  protected readonly dirty = signal(false)
  protected readonly title = computed(() => {
    const target = this.target()
    return target ? this.copy.title(target.title) : this.copy.open
  })

  constructor() {
    effect((onCleanup) => {
      const window = this.add()
      const off = [
        mirrorHilosSignal(window.openState, this.opened),
        mirrorHilosSignal(window.target, this.target),
        mirrorHilosSignal(window.saving, this.saving),
        mirrorHilosSignal(window.submitAvailable, this.submitAvailable),
        mirrorHilosSignal(window.rule, this.rule),
        mirrorHilosSignal(window.dirty, this.dirty),
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

  protected changeRule(event: Event): void {
    if (event.target instanceof HTMLTextAreaElement) {
      this.add().setRule(event.target.value)
    }
  }

  protected async submit(event?: Event): Promise<void> {
    event?.preventDefault()
    const window = this.add()
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
