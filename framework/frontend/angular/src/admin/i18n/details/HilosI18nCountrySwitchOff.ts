// HilosI18nCountrySwitchOff — the main card's "Switch off" control and its
// confirmation: switching a country off is the first step of editing it. Its
// locales and names stay in place and come back with it; the window counts
// them live from the card. The window holds the country it opened on and the
// tracked action through its own echo: the card switching off under a busy
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
  HILOS_I18N_COUNTRY_SWITCH_OFF_COPY,
  createHilosI18nCountrySwitchOff,
  hilosI18nCountryLabel,
} from '@hilos/core/admin/i18n'
import type {
  HilosI18nCountryCard,
  HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'

import {
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/** The country-card switch-off control and its confirmation. */
@Component({
  selector: 'hilos-i18n-country-switch-off',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    @if (card()?.enabled === true) {
      <button
        type="button"
        class="btn btn-sm btn-outline-danger"
        data-id="country-card-switch-off"
        (click)="openWindow()"
      >
        {{ copy.open }}
      </button>
    }

    <hilos-modal
      [open]="target() !== null"
      (openChange)="$event || closeWindow()"
      [title]="copy.title(target()?.label ?? '')"
      [closeOnBackdrop]="!action.busy()"
      [closeOnEsc]="!action.busy()"
      [closeDisabled]="action.busy()"
      initialFocus="dialog"
    >
      <div data-id="country-card-switch-off-error">
        <hilos-action-error
          [action]="action"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      <p>{{ copy.body }}</p>
      @if (card(); as current) {
        <div
          class="py-2 border-top border-bottom my-2 small"
          data-id="country-card-switch-off-counts"
        >
          <span class="text-body-secondary">{{ copy.localeCount }}</span>
          {{ ' ' }}
          <span
            class="fw-semibold"
            data-id="country-card-switch-off-locale-count"
            >{{ current.summary.localeCount }}</span
          >
          <span class="text-body-secondary ms-2">{{ copy.nameCount }}</span>
          {{ ' ' }}
          <span
            class="fw-semibold"
            data-id="country-card-switch-off-name-count"
            >{{ current.summary.nameCount }}</span
          >
        </div>
      }
      <div class="alert alert-secondary small py-2 mb-0">{{ copy.note }}</div>
      <hilos-edit-notice
        [kind]="elsewhere() ? 'updated' : null"
        [text]="copy.elsewhere"
        dataId="country-card-switch-off-notice"
      />
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="country-card-switch-off-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.loading()"
          [disabled]="action.busy() || elsewhere()"
          data-id="country-card-switch-off-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nCountrySwitchOff {
  /** The project context whose action lifecycle the switch runs on. */
  readonly context = input.required<HilosI18nCountryContext>()
  /** The live country card, or null once the page source is gone. */
  readonly card = input.required<HilosI18nCountryCard | null>()

  protected readonly copy = HILOS_I18N_COUNTRY_SWITCH_OFF_COPY
  protected readonly action = createHilosTrackedAction()
  /** The country the window opened on; null while it is closed. */
  protected readonly target = signal<{
    countryId: number
    label: string
  } | null>(null)
  protected readonly elsewhere = computed(
    () =>
      this.target() !== null &&
      !this.action.busy() &&
      this.card()?.enabled === false,
  )
  private readonly command = computed(() =>
    createHilosI18nCountrySwitchOff(this.context()),
  )
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)

  constructor() {
    // A card that disappeared, or a card of another country, closes the window.
    effect(() => {
      const card = this.card()
      const target = this.target()
      if (
        target !== null &&
        (card === null || card.countryId !== target.countryId)
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
    this.target.set({
      countryId: card.countryId,
      label: hilosI18nCountryLabel(card),
    })
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
      (await this.action.run(this.command().switchOff(target.countryId))) &&
      !this.destroyRef.destroyed
    ) {
      this.target.set(null)
    }
  }
}
