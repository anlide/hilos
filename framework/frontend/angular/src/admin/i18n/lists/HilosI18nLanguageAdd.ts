// HilosI18nLanguageAdd — the two windows of adding a language: the form, where a
// code from the built-in catalog the page answer carries fills the native name
// and the writing direction in at once, and the confirmation a code outside that
// catalog asks for before the same action leaves. The steps, the normalization,
// the verdict on what may be sent and the dispatch are the core's
// (createHilosI18nLanguageAdd); this view mirrors them, owns the two tracked
// actions, and closes only on the server's `::success`. A refusal stays in the
// window it belongs to, and the toast says the backend's own sentence. A viewer
// of the admin view mode reads and fills the form, while the submit stands
// disabled and neither Enter nor a click sends anything. Bootstrap classes only
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
import {
  HILOS_I18N_LANGUAGE_ADD_COPY,
  createHilosI18nBuiltInLanguages,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosI18nBuiltInLanguage,
  HilosI18nLanguageAdd as LanguageAdd,
  HilosI18nLanguageContext,
} from '@hilos/core'

import { HilosActionError } from '../../../HilosActionError.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { injectLookOnly } from '../../../hilosLookOnly.js'
import { mirrorHilosSignal } from '../../../hilosSignal.js'
import { createHilosTrackedAction } from '../../../hilosTrackedAction.js'

/** The step of the creation flow, as the core holds it. */
type LanguageAddStep = ReturnType<LanguageAdd['step']['get']>
/** The creation form, as the core holds it. */
type LanguageAddForm = ReturnType<LanguageAdd['form']['get']>

/** The language creation form and its custom-language confirmation. */
@Component({
  selector: 'hilos-i18n-language-add',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="step() === 'form'"
      (openChange)="$event || add().close()"
      [title]="copy.title"
      [closeOnBackdrop]="!formAction.busy()"
      [closeOnEsc]="!formAction.busy()"
      [closeDisabled]="formAction.busy()"
    >
      <form id="i18n-language-add-form" (submit)="submit($event)">
        <div class="row g-2">
          <div class="col-12 col-sm-4">
            <label
              class="form-label small fw-semibold"
              for="i18n-language-add-code"
            >
              {{ copy.codeLabel }}
            </label>
            <input
              id="i18n-language-add-code"
              class="form-control form-control-sm"
              [value]="form().code"
              [placeholder]="copy.codePlaceholder"
              [disabled]="formAction.busy()"
              maxlength="2"
              aria-describedby="i18n-language-add-code-hint"
              data-id="i18n-language-add-code"
              data-autofocus
              (input)="changeCode($event)"
            />
            <div id="i18n-language-add-code-hint" class="form-text">
              {{ copy.codeHint }}
            </div>
          </div>
          <div class="col-12 col-sm-8">
            <label
              class="form-label small fw-semibold"
              for="i18n-language-add-native-name"
            >
              {{ copy.nativeNameLabel }}
            </label>
            <input
              id="i18n-language-add-native-name"
              class="form-control form-control-sm"
              [value]="form().nativeName"
              [placeholder]="copy.nativeNamePlaceholder"
              [disabled]="formAction.busy()"
              maxlength="64"
              aria-describedby="i18n-language-add-native-name-hint"
              data-id="i18n-language-add-native-name"
              (input)="changeNativeName($event)"
            />
            <div id="i18n-language-add-native-name-hint" class="form-text">
              {{ known() ? copy.nativeNameKnownHint : copy.nativeNameOwnHint }}
            </div>
          </div>
        </div>
        <div class="form-check mt-2">
          <input
            id="i18n-language-add-rtl"
            class="form-check-input"
            type="checkbox"
            [checked]="form().rtl"
            [disabled]="formAction.busy()"
            data-id="i18n-language-add-rtl"
            (change)="changeRtl($event)"
          />
          <label class="form-check-label small" for="i18n-language-add-rtl">
            {{ copy.rtlLabel }}
          </label>
        </div>
      </form>
      <div class="mt-3" data-id="i18n-language-add-error">
        <hilos-action-error
          [action]="formAction"
          [detailsTitle]="copy.refusalTitle"
        />
      </div>
      @if (complete()) {
        <div
          [class]="
            'alert small py-2 mb-0 d-flex gap-2 ' +
            (known() ? 'alert-success' : 'alert-warning')
          "
          [attr.data-kind]="known() ? 'known' : 'own'"
          data-id="i18n-language-add-plate"
        >
          <i
            [class]="
              'bi flex-shrink-0 mt-1 ' +
              (known() ? 'bi-database-check' : 'bi-question-circle')
            "
            aria-hidden="true"
          ></i>
          <div>
            <strong>{{ known() ? copy.knownLead : copy.ownLead }}</strong>
            {{ known() ? copy.knownRest : copy.ownRest }}
          </div>
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="formAction.busy()"
          data-id="i18n-language-add-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-primary"
          type="submit"
          form="i18n-language-add-form"
          [loading]="formAction.loading()"
          [disabled]="!canSubmit() || formAction.busy()"
          data-id="i18n-language-add-submit"
        >
          {{ copy.submit }}
        </button>
      </ng-template>
    </hilos-modal>

    <hilos-modal
      [open]="step() === 'confirm'"
      (openChange)="$event || add().close()"
      [title]="copy.confirmTitle"
      [closeOnBackdrop]="!confirmAction.busy()"
      [closeOnEsc]="!confirmAction.busy()"
      [closeDisabled]="confirmAction.busy()"
      initialFocus="dialog"
    >
      <div data-id="i18n-language-confirm">
        <div class="d-flex align-items-center gap-3 mb-3">
          <span
            class="rounded-3 bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center fw-semibold text-uppercase p-3"
            data-id="i18n-language-confirm-code"
            >{{ form().code }}</span
          >
          <div>
            <div class="fw-semibold">{{ form().nativeName.trim() }}</div>
            <div class="small text-body-secondary">
              {{ copy.direction(form().rtl) }}
            </div>
          </div>
        </div>
        <p>{{ copy.catalogLine(form().code, catalogSize()) }}</p>
        <ul class="mb-2 ps-3">
          @for (consequence of copy.consequences; track consequence) {
            <li>{{ consequence }}</li>
          }
        </ul>
        <div data-id="i18n-language-confirm-error">
          <hilos-action-error
            [action]="confirmAction"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <div class="alert alert-secondary small py-2 mb-0">
          {{ copy.confirmNote }}
        </div>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="confirmAction.busy()"
          data-id="i18n-language-confirm-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-warning"
          [loading]="confirmAction.loading()"
          [disabled]="!canSubmit() || confirmAction.busy()"
          data-id="i18n-language-confirm-submit"
          (click)="confirm()"
        >
          {{ copy.confirmSubmit }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLanguageAdd {
  /** The core creation flow the page opens from the table's main action. */
  readonly add = input.required<LanguageAdd>()
  /** The project context whose page answer carries the built-in catalog. */
  readonly context = input.required<HilosI18nLanguageContext>()

  protected readonly copy = HILOS_I18N_LANGUAGE_ADD_COPY
  protected readonly step = signal<LanguageAddStep>('closed')
  // Typed by hand: the core keeps the form's interface out of its barrel, and
  // the emitted declaration has to name the type through the local alias.
  protected readonly form: WritableSignal<LanguageAddForm> =
    signal<LanguageAddForm>({
      code: '',
      nativeName: '',
      rtl: false,
    })
  protected readonly known = signal<HilosI18nBuiltInLanguage | null>(null)
  protected readonly complete = signal(false)
  protected readonly canSubmit = signal(false)
  protected readonly catalog = signal<
    readonly HilosI18nBuiltInLanguage[] | null
  >(null)
  /** How many languages the catalog the page answer carried holds. */
  protected readonly catalogSize = computed(() => this.catalog()?.length ?? 0)
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
        mirrorHilosSignal(add.known, this.known),
        mirrorHilosSignal(add.complete, this.complete),
        mirrorHilosSignal(add.canSubmit, this.canSubmit),
        mirrorHilosSignal(
          createHilosI18nBuiltInLanguages(this.context()),
          this.catalog,
        ),
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

  protected changeCode(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.add().setCode(event.target.value)
    }
  }

  protected changeNativeName(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.add().setNativeName(event.target.value)
    }
  }

  protected changeRtl(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.add().setRtl(event.target.checked)
    }
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
