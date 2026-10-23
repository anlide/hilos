// HilosI18nCountryAdd — the two windows of adding a country: the form, where the
// code, the currency symbol and the currency code are typed, and the
// confirmation a code outside the built-in catalog asks for before the same
// action leaves. A code the catalog knows is already in the list — the server
// refuses it as a duplicate — and the plate says so before anything is sent. A
// new country arrives switched off and without locales, so its default locale
// is chosen later. The steps, the normalization, the verdict on what may be
// sent and the dispatch are the core's (createHilosI18nCountryAdd, over the
// codes the page answer carries); this view mirrors them, owns the two tracked
// actions, and closes only on the server's `::success`. A refusal stays in the
// window it belongs to, and the toast says the backend's own sentence. A busy
// send holds Cancel, Esc, the backdrop and the close button. A viewer of the
// admin view mode reads and fills the form, while the submit stands disabled
// and neither Enter nor a click sends anything. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import type { WritableSignal } from '@angular/core'
import { HILOS_I18N_COUNTRY_ADD_COPY } from '@hilos/core/admin/i18n'
import { subscribeSignal } from '@hilos/core/base'
import type { HilosI18nCountryAdd as CountryAdd } from '@hilos/core/admin/i18n'

import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  mirrorHilosSignal,
  createHilosTrackedAction,
} from '@hilos/angular/base'
import { HilosI18nCountryFields } from '../details/HilosI18nCountryFields.js'

/** The step of the creation flow, as the core holds it. */
type CountryAddStep = ReturnType<CountryAdd['step']['get']>
/** The creation form, as the core holds it. */
type CountryAddForm = ReturnType<CountryAdd['form']['get']>
/** Whether the typed code is in the built-in catalog, as the core reads it. */
type CountryAddKind = ReturnType<CountryAdd['kind']['get']>

/** The country creation form and its custom-country confirmation. */
@Component({
  selector: 'hilos-i18n-country-add',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosI18nCountryFields,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="step() === 'form'"
      (openChange)="$event || add().close()"
      [title]="copy.title"
      [confirmOnClose]="dirty()"
      initialFocus="inner"
      [busy]="formAction.busy()"
    >
      <form id="i18n-country-add-form" (submit)="submit($event)">
        <hilos-i18n-country-fields
          idPrefix="i18n-country-add"
          mode="add"
          [code]="form().code"
          [currencySymbol]="form().currencySymbol"
          [currencyCode]="form().currencyCode"
          (codeChange)="add().setCode($event)"
          (currencySymbolChange)="add().setCurrencySymbol($event)"
          (currencyCodeChange)="add().setCurrencyCode($event)"
        />
      </form>
      <div class="mt-3" data-id="i18n-country-add-error">
        <hilos-action-error
          [action]="formAction"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      @if (kind(); as current) {
        <div
          [class]="
            'alert small py-2 mb-0 d-flex gap-2 ' +
            (current === 'known' ? 'alert-success' : 'alert-warning')
          "
          [attr.data-kind]="current"
          data-id="i18n-country-add-plate"
        >
          <i
            [class]="
              'bi flex-shrink-0 mt-1 ' +
              (current === 'known' ? 'bi-database-check' : 'bi-question-circle')
            "
            aria-hidden="true"
          ></i>
          <div>
            <strong>{{
              current === 'known' ? copy.knownLead : copy.ownLead
            }}</strong>
            {{ current === 'known' ? copy.knownRest : copy.ownRest }}
          </div>
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="formAction.busy()"
          data-id="i18n-country-add-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-primary"
          type="submit"
          form="i18n-country-add-form"
          [loading]="formAction.busy()"
          [disabled]="!submitAvailable()"
          data-id="i18n-country-add-submit"
        >
          {{ copy.submit }}
        </button>
      </ng-template>
    </hilos-modal>

    <hilos-modal
      [open]="step() === 'confirm'"
      (openChange)="$event || add().close()"
      [title]="copy.confirmTitle"
      [busy]="confirmAction.busy()"
      initialFocus="dialog"
    >
      <div
        class="d-flex align-items-center gap-3 mb-3"
        data-id="i18n-country-confirm"
      >
        <span
          class="rounded-3 bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center fs-4 px-3 py-2 text-uppercase"
          data-id="i18n-country-confirm-code"
          >{{ form().code }}</span
        >
        <div>
          <div class="fw-semibold">
            {{ form().currencySymbol }} {{ form().currencyCode }}
          </div>
          <div class="small text-body-secondary">{{ copy.entered }}</div>
        </div>
      </div>
      <p>{{ copy.catalogLine(form().code, catalogSize() ?? 0) }}</p>
      <ul class="mb-2 ps-3">
        @for (consequence of copy.consequences; track consequence) {
          <li>{{ consequence }}</li>
        }
      </ul>
      <div data-id="i18n-country-confirm-error">
        <hilos-action-error
          [action]="confirmAction"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      <div
        class="alert alert-secondary small py-2 mb-0"
        data-id="i18n-country-confirm-note"
      >
        {{ copy.confirmNote }}
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="confirmAction.busy()"
          data-id="i18n-country-confirm-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-warning"
          [loading]="confirmAction.busy()"
          [disabled]="!submitAvailable()"
          data-id="i18n-country-confirm-submit"
          (click)="confirm()"
        >
          {{ copy.confirmSubmit }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nCountryAdd {
  /** The core creation flow the page opens from the table's main action. */
  readonly add = input.required<CountryAdd>()

  protected readonly copy = HILOS_I18N_COUNTRY_ADD_COPY
  protected readonly step = signal<CountryAddStep>('closed')
  // Typed by hand: the core keeps the form's interface out of its barrel, and
  // the emitted declaration has to name the type through the local alias.
  protected readonly form: WritableSignal<CountryAddForm> =
    signal<CountryAddForm>({
      code: '',
      currencySymbol: '',
      currencyCode: '',
    })
  protected readonly kind = signal<CountryAddKind>(null)
  /** How many countries the catalog the page answer carried holds. */
  protected readonly catalogSize = signal<number | null>(null)
  protected readonly submitAvailable = signal(false)
  /** Whether the person typed anything a close would discard. */
  protected readonly dirty = computed(() => {
    const form = this.form()

    return (
      form.code !== '' || form.currencySymbol !== '' || form.currencyCode !== ''
    )
  })
  protected readonly formAction = createHilosTrackedAction()
  protected readonly confirmAction = createHilosTrackedAction()
  private readonly lookOnly = injectLookOnly()

  constructor() {
    // Mirror the flow the page bound, and start each step without the refusal
    // of an earlier visit; a new flow tears the old mirrors down.
    effect((onCleanup) => {
      const add = this.add()
      const stops = [
        mirrorHilosSignal(add.step, this.step),
        mirrorHilosSignal(add.form, this.form),
        mirrorHilosSignal(add.kind, this.kind),
        mirrorHilosSignal(add.catalogSize, this.catalogSize),
        mirrorHilosSignal(add.submitAvailable, this.submitAvailable),
        subscribeSignal(add.step, (next) => {
          if (next === 'form') this.formAction.clearError()
          if (next === 'confirm') this.confirmAction.clearError()
        }),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
      })
    })
  }

  // Enter and the submit button go through one door: the core refuses what may
  // not leave, asks for the confirmation of an own code, or sends.
  protected submit(event: Event): void {
    event.preventDefault()
    if (!this.lookOnly.locked()) {
      void this.add().submit(this.formAction.run)
    }
  }

  protected confirm(): void {
    if (!this.lookOnly.locked()) {
      void this.add().confirm(this.confirmAction.run)
    }
  }
}
