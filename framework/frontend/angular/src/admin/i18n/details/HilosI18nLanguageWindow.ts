// HilosI18nLanguageWindow — the one edit/view window of a language, opened from
// a languages-table row or from the main card. The session is the core's
// (createHilosI18nLanguageRowWindow / createHilosI18nLanguageCardWindow): the
// mode fixed at opening, the baseline/draft/incoming merge, the frozen verdict,
// the plate, the notice and the one canSave verdict Save and Enter both read.
// This view mirrors them, starts and disposes the session with itself and with a
// new session bound in its place, and owns the tracked action the save runs on:
// the window closes on the server's `::success`, a refusal stays in its reserved
// row, and a busy save holds Cancel, Esc, the backdrop and the close button. The
// code never changes; a switched-on language opens in view and stays in view.
// A viewer of the admin view mode reads and fills the form, the conflict choices
// stay live, and neither Save nor Enter sends. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
} from '@hilos/core/admin/i18n'
import { hilosRowEditIdle, subscribeSignal } from '@hilos/core/base'
import type {
  HilosI18nLanguageEditFields,
  HilosI18nLanguageEditable,
  HilosI18nLanguageWindow as LanguageWindow,
  HilosI18nLanguageWindowMode,
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

/** The window's one line about the other side, as the core resolves it. */
type LanguageWindowNotice = ReturnType<LanguageWindow['notice']['get']>
/** The plate under the fields, as the core resolves it. */
type LanguageWindowPlate = ReturnType<LanguageWindow['plate']['get']>

/** The shared view/edit window over one language. */
@Component({
  selector: 'hilos-i18n-language-window',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ConflictActions,
    ConflictHeader,
    HilosActionError,
    HilosEditNotice,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="opened()"
      (openChange)="$event || closeWindow()"
      [title]="title()"
      [confirmOnClose]="mode() === 'edit' && state().dirty"
      [busy]="action.busy()"
      initialFocus="dialog"
    >
      <div modalHeader data-id="i18n-language-edit-title">
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
      <div data-id="i18n-language-edit-window">
        <form (submit)="submit($event)">
          <div class="row g-2">
            <div class="col-4">
              <label
                class="form-label small fw-semibold"
                for="i18n-language-edit-code"
                >{{ copy.labels.code }}</label
              >
              <input
                id="i18n-language-edit-code"
                class="form-control form-control-sm"
                [value]="row()?.code ?? ''"
                disabled
                aria-describedby="i18n-language-code-hint"
                data-id="i18n-language-edit-code"
              />
              <div id="i18n-language-code-hint" class="form-text">
                <i class="bi bi-database-check me-1" aria-hidden="true"></i>
                {{ copy.hints.code }}
              </div>
            </div>
            <div class="col-8">
              <label
                class="form-label small fw-semibold"
                for="i18n-language-edit-native-name"
                >{{ copy.labels.nativeName }}</label
              >
              <input
                id="i18n-language-edit-native-name"
                class="form-control form-control-sm"
                [value]="shown().nativeName"
                [attr.maxlength]="nativeNameMax"
                [disabled]="disabled()"
                aria-describedby="i18n-language-name-hint"
                data-id="i18n-language-edit-native-name"
                (input)="changeName($event)"
              />
              <div id="i18n-language-name-hint" class="form-text">
                {{ copy.hints.nativeName[mode() === 'view' ? 'view' : 'edit'] }}
              </div>
            </div>
          </div>
          <div class="form-check mt-2">
            <input
              id="i18n-language-edit-rtl"
              class="form-check-input"
              type="checkbox"
              [checked]="shown().rtl"
              [disabled]="disabled()"
              [attr.aria-describedby]="
                mode() === 'view' ? 'i18n-language-rtl-hint' : null
              "
              data-id="i18n-language-edit-rtl"
              (change)="changeRtl($event)"
            />
            <label class="form-check-label small" for="i18n-language-edit-rtl">
              {{ copy.labels.rtl }}
            </label>
          </div>
          @if (mode() === 'view') {
            <div id="i18n-language-rtl-hint" class="form-text">
              {{ copy.hints.rtl }}
            </div>
          }
        </form>
        <div data-id="i18n-language-edit-error">
          <hilos-action-error
            [action]="action"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <hilos-edit-notice
          [kind]="notice()?.kind ?? null"
          [text]="notice()?.text ?? ''"
          dataId="i18n-language-edit-notice"
        />
        <div
          [class]="
            'alert small py-2 mb-0 d-flex gap-2 ' +
            (plate() === 'on' ? 'alert-warning' : 'alert-info')
          "
          [attr.data-kind]="plate()"
          data-id="i18n-language-edit-plate"
        >
          <i
            [class]="
              'bi flex-shrink-0 mt-1 ' +
              (plate() === 'on' ? 'bi-lock' : 'bi-pencil')
            "
            aria-hidden="true"
          ></i>
          <div>
            <p class="mb-0">
              <strong>{{ copy.plate[plate()].lead }}</strong>
              {{ copy.plate[plate()].text }}
            </p>
            @if (plate() !== 'on') {
              <p class="mt-2 mb-0">{{ copy.plate.code }}</p>
            }
          </div>
        </div>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        @if (mode() === 'edit') {
          <div
            hilosConflictActions
            [conflict]="state().conflict"
            [disableSave]="!canSave()"
            [saveLabel]="saveLabel()"
            (save)="submit()"
            (acceptMine)="window().keepMine()"
            (acceptTheirs)="window().takeTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="action.busy()"
                data-id="i18n-language-edit-cancel"
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
                data-id="i18n-language-edit-submit"
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
            data-id="i18n-language-edit-close"
            (click)="requestClose()"
          >
            {{ copy.close }}
          </button>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLanguageWindow {
  /** The core window session over a table row or over the main card. */
  readonly window = input.required<LanguageWindow>()

  protected readonly copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
  protected readonly nativeNameMax = HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX
  protected readonly opened = signal(false)
  protected readonly mode = signal<HilosI18nLanguageWindowMode | null>(null)
  protected readonly row = signal<HilosI18nLanguageEditable | null>(null)
  protected readonly live = signal<HilosI18nLanguageEditable | undefined>(
    undefined,
  )
  protected readonly form = signal<HilosI18nLanguageEditFields>({
    nativeName: '',
    rtl: false,
  })
  protected readonly state = signal(
    hilosRowEditIdle<HilosI18nLanguageEditFields>({
      nativeName: '',
      rtl: false,
    }),
  )
  protected readonly frozen = signal(false)
  protected readonly plate = signal<LanguageWindowPlate>('known')
  protected readonly notice = signal<LanguageWindowNotice>(null)
  protected readonly canSave = signal(false)
  protected readonly saveLabel = signal<string>(
    HILOS_I18N_LANGUAGE_WINDOW_COPY.save,
  )
  protected readonly action = createHilosTrackedAction()
  private readonly lookOnly = injectLookOnly()

  protected readonly title = computed(() =>
    this.copy.title[this.mode() ?? 'view'](this.row()?.nativeName ?? ''),
  )
  protected readonly shown = computed(() =>
    this.mode() === 'view'
      ? (this.live() ?? this.row() ?? this.form())
      : this.form(),
  )
  protected readonly disabled = computed(
    () => this.frozen() || this.state().gone || this.action.busy(),
  )

  constructor() {
    // Start the bound session and mirror it; a new session bound in its place,
    // or the end of this view, disposes the old one and lets its row go. An
    // opening starts without the refusal of the previous visit.
    effect((onCleanup) => {
      const window = this.window()
      window.start()
      let previous = window.mode.get()
      if (previous !== null) this.action.clearError()
      const stops = [
        mirrorHilosSignal(window.opened, this.opened),
        mirrorHilosSignal(window.mode, this.mode),
        mirrorHilosSignal(window.row, this.row),
        mirrorHilosSignal(window.live, this.live),
        mirrorHilosSignal(window.form, this.form),
        mirrorHilosSignal(window.state, this.state),
        mirrorHilosSignal(window.frozen, this.frozen),
        mirrorHilosSignal(window.plate, this.plate),
        mirrorHilosSignal(window.notice, this.notice),
        mirrorHilosSignal(window.canSave, this.canSave),
        mirrorHilosSignal(window.saveLabel, this.saveLabel),
        subscribeSignal(window.mode, (next) => {
          if (next !== null && previous === null) this.action.clearError()
          previous = next
        }),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        window.dispose()
      })
    })
  }

  protected closeWindow(): void {
    if (!this.action.busy()) {
      this.window().close()
    }
  }

  protected changeName(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.window().patchForm({ nativeName: event.target.value })
    }
  }

  protected changeRtl(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.window().patchForm({ rtl: event.target.checked })
    }
  }

  // Save and Enter go through the session's one door: it refuses what may not
  // leave, closes an unchanged draft, or sends and closes on `::success`.
  protected submit(event?: Event): void {
    event?.preventDefault()
    if (this.mode() === 'edit' && !this.lookOnly.locked()) {
      void this.window().save(this.action.run)
    }
  }
}
