import { DOCUMENT } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  TemplateRef,
  ViewContainerRef,
  computed,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core'
import type { AfterViewInit } from '@angular/core'
import { type Hideable, isHiddenValue } from '@hilos/core/base'

import { HilosHideable } from './HilosHideable.js'
import { HilosModal } from './HilosModal.js'

/**
 * Read an unavailable action's reason through a native button and the standard
 * modal. A reason hidden by the server still gives the opener, without a title
 * or string conversion; the dialog draws its snapshot through HilosHideable.
 */
@Component({
  selector: 'button[hilosActionReason]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosHideable, HilosModal],
  host: {
    class: 'btn btn-sm btn-outline-secondary',
    '[attr.type]': "'button'",
    '[hidden]': 'reason() === null',
    '[class.d-none]': 'reason() === null',
    '[attr.title]': 'openTitle()',
    '[attr.aria-label]': 'label()',
    '(click)': 'showReason()',
  },
  template: `
    <i class="bi bi-question-circle" aria-hidden="true"></i>
    <ng-template #explanationModal>
      <hilos-modal [(open)]="open" [title]="title()" initialFocus="dialog">
        <p class="mb-0 text-break" data-id="hilos-action-reason-body">
          <hilos-hideable [value]="explanation()" />
        </p>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="hilos-action-reason-close"
            (click)="requestClose()"
          >
            Close
          </button>
        </ng-template>
      </hilos-modal>
    </ng-template>
  `,
})
export class HilosActionReason implements AfterViewInit {
  /** The current reason; null hides only the opener, preserving an open explanation. */
  readonly reason = input.required<Hideable<string | null>>()
  /** Accessible name of the native explanation button. */
  readonly label = input('Why this action is unavailable')
  /** Visible and accessible name of the explanation dialog. */
  readonly title = input('Action unavailable')

  protected readonly open = signal(false)
  protected readonly explanation = signal<Hideable<string>>('')
  protected readonly openTitle = computed(() => {
    const reason = this.reason()
    return reason === null || isHiddenValue(reason) ? null : reason
  })
  private readonly modalTemplate =
    viewChild.required<TemplateRef<void>>('explanationModal')
  private readonly container = inject(ViewContainerRef)
  private readonly doc = inject(DOCUMENT)
  private readonly destroyRef = inject(DestroyRef)

  /** Place the owned view outside the native opener and its action flex row. */
  ngAfterViewInit(): void {
    // HilosModal renders in place. Keep Angular's declaration DI, change
    // detection and view ownership, but move its roots out of the button/row:
    // neither a nested Close button nor a modal host may become an action item.
    const view = this.container.createEmbeddedView(this.modalTemplate())
    for (const node of view.rootNodes) this.doc.body.appendChild(node)
    this.destroyRef.onDestroy(() => view.destroy())
  }

  /** Read the current reason once for this visit to the dialog. */
  protected showReason(): void {
    const reason = this.reason()
    if (reason === null) return
    this.explanation.set(reason)
    this.open.set(true)
  }
}
