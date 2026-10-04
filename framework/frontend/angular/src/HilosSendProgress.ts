import {
  SEND_AGAIN_LABEL,
  SEND_PROGRESS_DETAILS_CLASS,
  SEND_PROGRESS_ROW_CLASS,
  sendAgainIn,
  sendAgainLocked,
  sendProgressLine,
  type CodeSendProgress,
} from '@hilos/core'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'

/** Profile code delivery status and a timed resend control. */
@Component({
  selector: 'hilos-send-progress',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLongText, HilosModal],
  template: `
    <div [attr.data-id]="dataId()">
      @if (line(); as current) {
        <div
          [class]="rowClass + ' ' + current.tone"
          [attr.data-id]="dataId() + '-line'"
        >
          <i
            [class]="'bi ' + current.icon + ' flex-shrink-0'"
            aria-hidden="true"
          ></i>
          <span class="flex-grow-1 text-truncate">{{ current.text }}</span>
          <button
            type="button"
            [class]="detailsClass"
            aria-label="Show send details"
            title="Show send details"
            [attr.data-id]="dataId() + '-details'"
            (click)="detailOpen.set(true)"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </button>
        </div>
      } @else {
        <div
          [class]="rowClass + ' invisible'"
          aria-hidden="true"
          [attr.data-id]="dataId() + '-idle'"
        >
          <i class="bi bi-hourglass-split flex-shrink-0" aria-hidden="true"></i>
          <span class="flex-grow-1 text-truncate">&nbsp;</span>
          <span [class]="detailsClass"
            ><i class="bi bi-info-circle" aria-hidden="true"></i
          ></span>
        </div>
      }

      <div class="mb-3">
        @if (countdown(); as remaining) {
          <span
            class="btn btn-link btn-sm p-0 disabled"
            [attr.data-id]="dataId() + '-again-in'"
          >
            {{ remaining }}
          </span>
        } @else if (hasSend()) {
          <button
            type="button"
            class="btn btn-link btn-sm p-0"
            [disabled]="locked()"
            [attr.aria-busy]="busy() ? 'true' : null"
            [attr.data-id]="dataId() + '-again'"
            (click)="sendAgain.emit()"
          >
            {{ sendAgainLabel }}
          </button>
        } @else {
          <span class="btn btn-link btn-sm p-0 invisible" aria-hidden="true">{{
            sendAgainLabel
          }}</span>
        }
      </div>
    </div>

    <hilos-modal
      [open]="detailOpen()"
      (openChange)="detailOpen.set($event)"
      title="Send details"
      initialFocus="dialog"
    >
      <hilos-long-text
        kind="prose"
        [text]="line()?.text ?? ''"
        [dataId]="dataId() + '-full'"
      />
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [attr.data-id]="dataId() + '-close'"
          (click)="requestClose()"
        >
          Close
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosSendProgress {
  /** The session's send line for this window, or null before the first send. */
  readonly progress = input.required<CodeSendProgress | null>()

  /** Address or phone number receiving the code. */
  readonly to = input.required<string>()

  /** Local-scale moment another send is allowed, or null. */
  readonly resendAt = input.required<number | null>()

  /** Whether this window is ordering a code now. */
  readonly busy = input.required<boolean>()

  /** Data-id of the whole block; the row and controls derive theirs from it. */
  readonly dataId = input.required<string>()

  /** The person asked for another code. */
  readonly sendAgain = output<void>()

  protected readonly sendAgainLabel = SEND_AGAIN_LABEL
  protected readonly rowClass = SEND_PROGRESS_ROW_CLASS
  protected readonly detailsClass = SEND_PROGRESS_DETAILS_CLASS
  protected readonly now = signal(Date.now())
  protected readonly detailOpen = signal(false)
  protected readonly line = computed(() =>
    sendProgressLine(this.progress(), this.to()),
  )
  protected readonly countdown = computed(() =>
    sendAgainIn(this.resendAt(), this.now()),
  )
  protected readonly locked = computed(() =>
    sendAgainLocked(this.progress(), this.busy(), this.resendAt(), this.now()),
  )
  protected readonly hasSend = computed(
    () => this.progress() !== null || this.resendAt() !== null,
  )

  constructor() {
    effect((onCleanup) => {
      const moment = this.resendAt()
      const current = Date.now()
      this.now.set(current)
      if (moment === null || current >= moment) {
        return
      }
      const clock = setInterval(() => {
        const next = Date.now()
        this.now.set(next)
        if (next >= moment) {
          clearInterval(clock)
        }
      }, 1000)
      onCleanup(() => clearInterval(clock))
    })
    effect(() => {
      if (this.line() === null) {
        this.detailOpen.set(false)
      }
    })
  }
}
