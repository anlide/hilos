// HilosI18nCountryWindow — the one edit/view window of a country, opened from a
// countries-table row or from the main card. The session is the core's
// (createHilosI18nCountryRowWindow / createHilosI18nCountryCardWindow): the
// mode fixed at opening, the baseline/draft/incoming merge, the catalog's
// suggested default locale, the options over the country's own locales —
// switched off ones included, a vanished choice kept — the plate, the notice
// and the one canSave verdict Save and Enter both read. This view mirrors them,
// starts and disposes the session with itself and with a new session bound in
// its place, and owns the tracked action the save runs on: the window closes on
// the server's `::success`, a refusal stays in its reserved row, and a busy save
// holds Cancel, Esc, the backdrop and the close button. The code never changes;
// a switched-on country opens in view and stays in view, and a draft outlives a
// switch or a deletion made elsewhere. A catalog suggestion is not the
// person's input, so closing over it asks nothing. A viewer of the admin view
// mode reads and fills the form, the conflict choices stay live, and neither
// Save nor Enter sends. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { HILOS_I18N_COUNTRY_WINDOW_COPY } from '@hilos/core/admin/i18n'
import { hilosRowEditIdle, subscribeSignal } from '@hilos/core/base'
import type {
  HilosI18nCountryEditFields,
  HilosI18nCountryEditable,
  HilosI18nCountryLocaleOption,
  HilosI18nCountryWindow as CountryWindow,
  HilosI18nCountryWindowMode,
} from '@hilos/core/admin/i18n'

import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  mirrorHilosSignal,
  createHilosTrackedAction,
} from '@hilos/angular/base'
import { HilosI18nCountryFields } from './HilosI18nCountryFields.js'

/** The window's one line about the other side, as the core resolves it. */
type CountryWindowNoticeKind = ReturnType<CountryWindow['noticeKind']['get']>
/** The plate under the fields, as the core resolves it. */
type CountryWindowPlate = ReturnType<CountryWindow['plate']['get']>

/** The fields of a country no window holds yet. */
const NO_FIELDS: HilosI18nCountryEditFields = {
  currencySymbol: '',
  currencyCode: '',
  defaultLocaleId: null,
}

/** The shared view/edit window over one country. */
@Component({
  selector: 'hilos-i18n-country-window',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ConflictActions,
    ConflictHeader,
    HilosActionError,
    HilosEditNotice,
    HilosI18nCountryFields,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="opened()"
      (openChange)="$event || closeWindow()"
      [title]="title()"
      [confirmOnClose]="mode() === 'edit' && touched()"
      [closeOnBackdrop]="!busy()"
      [closeOnEsc]="!busy()"
      [closeDisabled]="busy()"
      initialFocus="inner"
    >
      <div modalHeader data-id="i18n-country-title">
        @if (mode() === 'edit') {
          <h5
            hilosConflictHeader
            [title]="title()"
            [conflict]="state().conflict"
          ></h5>
        } @else {
          <h5 class="modal-title mb-0">{{ title() }}</h5>
        }
      </div>
      <div data-id="i18n-country-window">
        <form id="i18n-country-edit-form" (submit)="submit($event)">
          <hilos-i18n-country-fields
            idPrefix="i18n-country-edit"
            [mode]="mode()"
            [code]="row()?.code ?? ''"
            [currencySymbol]="form().currencySymbol"
            [currencyCode]="form().currencyCode"
            [defaultLocaleId]="form().defaultLocaleId"
            [localeOptions]="options()"
            [localeHint]="localeHint()"
            describedBy="i18n-country-plate"
            (currencySymbolChange)="window().setSymbol($event)"
            (currencyCodeChange)="window().setCurrencyCode($event)"
            (defaultLocaleIdChange)="window().setDefaultLocale($event)"
          />
        </form>
        <div class="mt-3">
          <hilos-edit-notice
            [kind]="noticeKind()"
            [text]="noticeText()"
            dataId="i18n-country-notice"
          />
        </div>
        @if (mode() === 'edit') {
          <div data-id="i18n-country-error">
            <hilos-action-error
              [action]="action"
              [detailsTitle]="copy.refusalTitle"
            />
          </div>
        }
        <div class="hilos-stack">
          <!-- The switch can change while editing; its longer explanation
          holds its room before it arrives, in either theme and at any width. -->
          @if (mode() === 'edit') {
            <div class="hilos-stack invisible" aria-hidden="true" inert>
              @for (variant of plates; track variant.rest) {
                <div class="alert small py-2 mb-0 d-flex gap-2 alert-info">
                  <i
                    class="bi bi-pencil flex-shrink-0 mt-1"
                    aria-hidden="true"
                  ></i>
                  <div>
                    <strong>{{ variant.lead }}</strong> {{ variant.rest }}
                  </div>
                </div>
              }
            </div>
          }
          <div
            id="i18n-country-plate"
            [class]="'alert small py-2 mb-0 d-flex gap-2 ' + plate().tone"
            data-id="i18n-country-plate"
          >
            <i
              [class]="'bi flex-shrink-0 mt-1 ' + plate().icon"
              aria-hidden="true"
            ></i>
            <div>
              <strong>{{ plate().lead }}</strong> {{ plate().rest }}
            </div>
          </div>
        </div>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        @if (mode() === 'edit') {
          <div
            hilosConflictActions
            [conflict]="state().conflict"
            [disableSave]="!canSave() || busy()"
            [saveLabel]="saveLabel()"
            (save)="submit()"
            (acceptMine)="window().keepMine()"
            (acceptTheirs)="window().takeTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="busy()"
                data-id="i18n-country-cancel"
                (click)="requestClose()"
              >
                {{ copy.cancel }}
              </button>
            </ng-template>
            <ng-template
              #saveButton
              let-saveDisabled="disabled"
              let-onSave="onSave"
            >
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="action.loading()"
                [disabled]="saveDisabled"
                data-id="i18n-country-save"
                (click)="onSave()"
              >
                {{ saveLabel() }}
              </button>
            </ng-template>
          </div>
        } @else {
          <button
            type="button"
            class="btn btn-secondary"
            data-id="i18n-country-close"
            (click)="requestClose()"
          >
            {{ copy.close }}
          </button>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nCountryWindow {
  /** The core window session over a table row or over the main card. */
  readonly window = input.required<CountryWindow>()

  protected readonly copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  protected readonly plates = Object.values(
    HILOS_I18N_COUNTRY_WINDOW_COPY.plates,
  )
  protected readonly opened = signal(false)
  protected readonly mode = signal<HilosI18nCountryWindowMode>('view')
  protected readonly title = signal('')
  protected readonly row = signal<HilosI18nCountryEditable | null>(null)
  protected readonly form = signal<HilosI18nCountryEditFields>(NO_FIELDS)
  protected readonly state = signal(hilosRowEditIdle(NO_FIELDS))
  protected readonly noticeKind = signal<CountryWindowNoticeKind>(null)
  protected readonly noticeText = signal('')
  protected readonly plate = signal<CountryWindowPlate>({
    ...HILOS_I18N_COUNTRY_WINDOW_COPY.plates.view,
    tone: 'alert-warning',
    icon: 'bi-lock',
  })
  protected readonly options = signal<readonly HilosI18nCountryLocaleOption[]>(
    [],
  )
  protected readonly localeHint = signal('')
  protected readonly touched = signal(false)
  protected readonly canSave = signal(false)
  protected readonly saveLabel = signal<string>(
    HILOS_I18N_COUNTRY_WINDOW_COPY.save,
  )
  protected readonly saving = signal(false)
  protected readonly action = createHilosTrackedAction()
  protected readonly busy = computed(() => this.saving() || this.action.busy())
  private readonly lookOnly = injectLookOnly()

  constructor() {
    // Start the bound session and mirror it; a new session bound in its place,
    // or the end of this view, disposes the old one and lets its row go. An
    // opening starts without the refusal of the previous visit.
    effect((onCleanup) => {
      const window = this.window()
      window.start()
      if (window.opened.get()) this.action.clearError()
      const stops = [
        mirrorHilosSignal(window.opened, this.opened),
        mirrorHilosSignal(window.mode, this.mode),
        mirrorHilosSignal(window.title, this.title),
        mirrorHilosSignal(window.row, this.row),
        mirrorHilosSignal(window.form, this.form),
        mirrorHilosSignal(window.state, this.state),
        mirrorHilosSignal(window.noticeKind, this.noticeKind),
        mirrorHilosSignal(window.noticeText, this.noticeText),
        mirrorHilosSignal(window.plate, this.plate),
        mirrorHilosSignal(window.options, this.options),
        mirrorHilosSignal(window.localeHint, this.localeHint),
        mirrorHilosSignal(window.touched, this.touched),
        mirrorHilosSignal(window.canSave, this.canSave),
        mirrorHilosSignal(window.saveLabel, this.saveLabel),
        mirrorHilosSignal(window.saving, this.saving),
        subscribeSignal(window.opened, (next) => {
          if (next) this.action.clearError()
        }),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        window.dispose()
      })
    })
  }

  protected closeWindow(): void {
    if (!this.busy()) {
      this.window().close()
    }
  }

  // Save and Enter go through the session's one door: nothing leaves for a
  // viewer, in view, or while a save is in flight; the session refuses what
  // may not leave, closes an unchanged draft, or sends and closes on
  // `::success`.
  protected submit(event?: Event): void {
    event?.preventDefault()
    if (this.mode() === 'edit' && !this.lookOnly.locked() && !this.busy()) {
      void this.window().save(this.action.run)
    }
  }
}
