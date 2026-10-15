// HilosI18nCountryDelete — the one delete confirmation of a country, opened from
// a countries-table row (the row in focus) or from the main card (its page
// datum). The page fixes the target — the code and the label — at the moment it
// opens, and hands the live source beside it: a country gone while the window
// stands idle is said in the notice row and blocks the confirm, and the window
// stays open until it is closed by hand. Its own deletion is different: the
// echo that takes the source away while the action is in flight says nothing,
// the window keeps its target and its action, and only `::success` closes it
// and tells the page — a card returns to the list from there. The dependency
// verdict that opened it is a hint; the server checks again when the action
// runs, and its refusal stays in the reserved row. A viewer of the admin view
// mode opens and reads the window, while the confirm stands disabled. Bootstrap
// classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
  untracked,
} from '@angular/core'
import {
  HILOS_I18N_COUNTRY_DELETE_COPY,
  hilosI18nCountryDeleteLabel,
} from '@hilos/core'
import type { ActionHandle, HilosI18nCountryDeleteTarget } from '@hilos/core'

import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { injectLookOnly } from '../../../hilosLookOnly.js'
import { createHilosTrackedAction } from '../../../hilosTrackedAction.js'

/** The shared delete confirmation of a country row and of its card. */
@Component({
  selector: 'hilos-i18n-country-delete',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="open()"
      (openChange)="$event || closeWindow()"
      [title]="copy.title(label())"
      [closeOnBackdrop]="!action.busy()"
      [closeOnEsc]="!action.busy()"
      [closeDisabled]="action.busy()"
      initialFocus="dialog"
    >
      <div data-id="i18n-country-delete-window">
        <div data-id="i18n-country-delete-error">
          <hilos-action-error
            [action]="action"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <p>{{ copy.body }}</p>
        <div class="alert alert-warning small py-2 mb-0">{{ copy.note }}</div>
        <hilos-edit-notice
          [kind]="elsewhere() ? 'deleted' : null"
          [text]="copy.elsewhere"
          dataId="i18n-country-delete-notice"
        />
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="i18n-country-delete-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.loading()"
          [disabled]="action.busy() || elsewhere()"
          data-id="i18n-country-delete-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nCountryDelete {
  /** Whether the page has the confirmation open. */
  readonly open = input.required<boolean>()
  /** The country the page chose on opening; it outlives the deletion echo. */
  readonly target = input.required<HilosI18nCountryDeleteTarget | null>()
  /** The live country the window follows, or null once it is gone. */
  readonly source = input.required<HilosI18nCountryDeleteTarget | null>()
  /** The delete action of the page that opens the window. */
  readonly deleteCountry = input.required<(countryId: number) => ActionHandle>()
  /** The window asks to close: Cancel, Esc, the backdrop, or its own success. */
  readonly closed = output<void>()
  /** The country was deleted by this window's action. */
  readonly deleted = output<void>()

  protected readonly copy = HILOS_I18N_COUNTRY_DELETE_COPY
  protected readonly action = createHilosTrackedAction()
  protected readonly label = computed(() => {
    const target = this.target()

    return target === null ? '' : hilosI18nCountryDeleteLabel(target)
  })
  protected readonly elsewhere = computed(
    () =>
      this.open() &&
      !this.action.busy() &&
      this.source()?.countryId !== this.target()?.countryId,
  )
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)

  constructor() {
    // Every opening starts without the refusal of the previous visit.
    effect(() => {
      if (this.open()) {
        untracked(() => this.action.clearError())
      }
    })
  }

  protected closeWindow(): void {
    if (!this.action.busy()) {
      this.closed.emit()
    }
  }

  protected async confirm(): Promise<void> {
    const target = this.target()
    if (
      !this.open() ||
      target === null ||
      this.action.busy() ||
      this.lookOnly.locked() ||
      this.elsewhere()
    ) {
      return
    }
    // The opening hint may have changed; the server's live verdict decides.
    if (
      (await this.action.run(this.deleteCountry()(target.countryId))) &&
      !this.destroyRef.destroyed
    ) {
      this.closed.emit()
      this.deleted.emit()
    }
  }
}
