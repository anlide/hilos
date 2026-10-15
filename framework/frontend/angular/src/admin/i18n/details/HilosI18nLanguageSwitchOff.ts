// HilosI18nLanguageSwitchOff — the main card's "Switch off" control and its
// confirmation. The default language is never switched off: the control stands
// disabled before anything opens, with the core's reason as visible text beside
// it (hilosI18nLanguageSwitchOff); the server checks again when the action runs.
// The window holds the language it opened on and the tracked action through its
// own echo: the card switching off under a busy confirmation is that echo and
// says nothing, and only `::success` closes it. A switch made elsewhere while the
// window stands idle is said in the notice row and blocks the confirm; a card
// that disappears takes the window with it. A viewer of the admin view mode opens
// and reads the window, while the confirm stands disabled. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY,
  createHilosI18nLanguageSwitchOff,
  hilosI18nLanguageSwitchOff,
} from '@hilos/core'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
} from '@hilos/core'

import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { injectLookOnly } from '../../../hilosLookOnly.js'
import { createHilosTrackedAction } from '../../../hilosTrackedAction.js'

/** The language-card switch-off control and its confirmation. */
@Component({
  selector: 'hilos-i18n-language-switch-off',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    @if (verdict().shown) {
      <button
        type="button"
        class="btn btn-sm btn-outline-danger"
        [disabled]="verdict().disabled"
        [attr.aria-describedby]="
          verdict().reason ? 'language-card-switch-off-reason' : null
        "
        data-id="language-card-switch-off"
        (click)="openWindow()"
      >
        {{ copy.open }}
      </button>
      @if (verdict().reason; as reason) {
        <p
          id="language-card-switch-off-reason"
          class="small text-body-secondary mt-1 mb-0"
          data-id="language-card-switch-off-reason"
        >
          {{ reason }}
        </p>
      }
    }

    <hilos-modal
      [open]="target() !== null"
      (openChange)="$event || closeWindow()"
      [title]="copy.title(target()?.name ?? '')"
      [closeOnBackdrop]="!action.busy()"
      [closeOnEsc]="!action.busy()"
      [closeDisabled]="action.busy()"
      initialFocus="dialog"
    >
      <div data-id="language-card-switch-off-error">
        <hilos-action-error
          [action]="action"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      <p>{{ copy.body }}</p>
      <div class="alert alert-secondary small py-2 mb-0">{{ copy.note }}</div>
      <hilos-edit-notice
        [kind]="elsewhere() ? 'updated' : null"
        [text]="copy.elsewhere"
        dataId="language-card-switch-off-notice"
      />
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="language-card-switch-off-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.loading()"
          [disabled]="action.busy() || elsewhere() || verdict().disabled"
          data-id="language-card-switch-off-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLanguageSwitchOff {
  /** The project context whose action lifecycle the switch runs on. */
  readonly context = input.required<HilosI18nLanguageContext>()
  /** The live language card, or null once the page source is gone. */
  readonly card = input.required<HilosI18nLanguageCard | null>()

  protected readonly copy = HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY
  protected readonly verdict = computed(() =>
    hilosI18nLanguageSwitchOff(this.card()),
  )
  protected readonly action = createHilosTrackedAction()
  /** The language the window opened on; null while it is closed. */
  protected readonly target = signal<{
    languageId: number
    name: string
  } | null>(null)
  protected readonly elsewhere = computed(
    () =>
      this.target() !== null &&
      !this.action.busy() &&
      this.card()?.enabled === false,
  )
  private readonly command = computed(() =>
    createHilosI18nLanguageSwitchOff(this.context()),
  )
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)

  constructor() {
    // A card that disappeared, or a card of another language, closes the window.
    effect(() => {
      const card = this.card()
      const target = this.target()
      if (
        target !== null &&
        (card === null || card.languageId !== target.languageId)
      ) {
        this.target.set(null)
      }
    })
  }

  protected openWindow(): void {
    const card = this.card()
    if (card === null) {
      return
    }
    this.action.clearError()
    this.target.set({ languageId: card.languageId, name: card.nativeName })
  }

  protected closeWindow(): void {
    if (!this.action.busy()) {
      this.target.set(null)
    }
  }

  protected async confirm(): Promise<void> {
    const target = this.target()
    if (
      target === null ||
      this.action.busy() ||
      this.lookOnly.locked() ||
      this.elsewhere() ||
      this.verdict().disabled
    ) {
      return
    }
    if (
      (await this.action.run(this.command().switchOff(target.languageId))) &&
      !this.destroyRef.destroyed
    ) {
      this.target.set(null)
    }
  }
}
