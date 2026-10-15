// HilosI18nCountrySwitchOn — the main card's "Switch on" control and its
// confirmation. A country is switched on without a default locale, or with one
// that is switched off — formats then come from the language — and the window
// says which, as the core's live verdict over the card reads it
// (hilosI18nCountrySwitchOn), again on every change; the server checks again
// when the action runs. The window holds the country it opened on and the
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
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  createHilosI18nCountrySwitchOn,
  hilosI18nCountryLabel,
  hilosI18nCountrySwitchOn,
} from '@hilos/core'
import type { HilosI18nCountryCard, HilosI18nCountryContext } from '@hilos/core'

import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { injectLookOnly } from '../../../hilosLookOnly.js'
import { createHilosTrackedAction } from '../../../hilosTrackedAction.js'

/** The country-card switch-on control and its live confirmation. */
@Component({
  selector: 'hilos-i18n-country-switch-on',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    @if (verdict().shown) {
      <button
        type="button"
        class="btn btn-sm btn-outline-primary"
        data-id="country-card-switch-on"
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
      <div data-id="country-card-switch-on-error">
        <hilos-action-error
          [action]="action"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      <hilos-edit-notice
        [kind]="elsewhere() ? 'updated' : null"
        [text]="copy.elsewhere"
        dataId="country-card-switch-on-notice"
      />
      <p>{{ copy.body }}</p>
      @if (verdict().note; as note) {
        <div
          [class]="
            'alert small py-2 mb-0 ' +
            (verdict().defaultLocale === 'off'
              ? 'alert-warning'
              : 'alert-secondary')
          "
          data-id="country-card-switch-on-note"
        >
          {{ note }}
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="country-card-switch-on-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-primary"
          [loading]="action.loading()"
          [disabled]="action.busy() || elsewhere()"
          data-id="country-card-switch-on-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nCountrySwitchOn {
  /** The project context whose action lifecycle the switch runs on. */
  readonly context = input.required<HilosI18nCountryContext>()
  /** The live country card, or null once the page source is gone. */
  readonly card = input.required<HilosI18nCountryCard | null>()

  protected readonly copy = HILOS_I18N_COUNTRY_SWITCH_ON_COPY
  protected readonly verdict = computed(() =>
    hilosI18nCountrySwitchOn(this.card()),
  )
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
      this.card()?.enabled === true,
  )
  private readonly command = computed(() =>
    createHilosI18nCountrySwitchOn(this.context()),
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
      (await this.action.run(this.command().switchOn(target.countryId))) &&
      !this.destroyRef.destroyed
    ) {
      this.target.set(null)
    }
  }
}
