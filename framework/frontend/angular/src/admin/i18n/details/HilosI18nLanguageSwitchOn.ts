// HilosI18nLanguageSwitchOn — the main card's "Switch on" control and its
// confirmation. What the window says — the countryless locale, the number of
// names, the warning — is the core's live verdict over the card
// (hilosI18nLanguageSwitchOn), read again on every change; the server checks
// again when the action runs. The window holds the language it opened on and the
// tracked action through its own echo: the card switching on under a busy
// confirmation is that echo and says nothing, and only `::success` closes it. A
// switch made elsewhere while the window stands idle is said in the notice row
// and blocks the confirm; a card that disappears takes the window with it. A
// viewer of the admin view mode opens and reads the window, while the confirm
// stands disabled. Bootstrap classes only (styling-rules.md).
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
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  createHilosI18nLanguageSwitchOn,
  hilosI18nLanguageSwitchOn,
} from '@hilos/core/admin/i18n'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'

import {
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/** The language-card switch-on control and its live confirmation. */
@Component({
  selector: 'hilos-i18n-language-switch-on',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    @if (verdict().shown) {
      <button
        type="button"
        class="btn btn-sm btn-outline-primary"
        data-id="language-card-switch-on"
        (click)="openWindow()"
      >
        {{ copy.open }}
      </button>
    }

    <hilos-modal
      [open]="target() !== null"
      (openChange)="$event || closeWindow()"
      [title]="copy.title(target()?.name ?? '')"
      [busy]="action.busy()"
      initialFocus="dialog"
    >
      <div data-id="language-card-switch-on-error">
        <hilos-action-error
          [action]="action"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      <hilos-edit-notice
        [kind]="elsewhere() ? 'updated' : null"
        [text]="copy.elsewhere"
        dataId="language-card-switch-on-notice"
      />
      <p>{{ copy.body }}</p>
      <div
        class="d-flex flex-wrap gap-3 py-2 border-top border-bottom my-2 small"
      >
        <div>
          <span class="text-body-secondary">{{ copy.localeLabel }}</span>
          @if (verdict().locale !== 'on') {
            <span
              class="badge text-bg-warning-subtle text-warning-emphasis"
              data-id="language-card-switch-on-locale"
            >
              <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
              {{ copy.locale[verdict().locale] }}
            </span>
          } @else {
            <span data-id="language-card-switch-on-locale">
              {{ copy.locale.on }}
            </span>
          }
        </div>
        <div>
          <span class="text-body-secondary">{{ copy.namesLabel }}</span>
          <span class="fw-semibold" data-id="language-card-switch-on-names">
            {{ verdict().nameCount }}
          </span>
        </div>
      </div>
      @if (verdict().warning; as warning) {
        <div
          class="alert alert-warning small py-2 mb-0"
          data-id="language-card-switch-on-warning"
        >
          {{ warning }}
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="language-card-switch-on-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-primary"
          [loading]="action.busy()"
          [disabled]="elsewhere()"
          data-id="language-card-switch-on-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLanguageSwitchOn {
  /** The project context whose action lifecycle the switch runs on. */
  readonly context = input.required<HilosI18nLanguageContext>()
  /** The live language card, or null once the page source is gone. */
  readonly card = input.required<HilosI18nLanguageCard | null>()

  protected readonly copy = HILOS_I18N_LANGUAGE_SWITCH_ON_COPY
  protected readonly verdict = computed(() =>
    hilosI18nLanguageSwitchOn(this.card()),
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
      this.card()?.enabled === true,
  )
  private readonly command = computed(() =>
    createHilosI18nLanguageSwitchOn(this.context()),
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
      this.elsewhere()
    ) {
      return
    }
    if (
      (await this.action.run(this.command().switchOn(target.languageId))) &&
      !this.destroyRef.destroyed
    ) {
      this.target.set(null)
    }
  }
}
