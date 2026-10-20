// HilosI18nLanguageNameWindow — the one window of a language's name written in
// another language: the base name or one locale correction of it, added where
// there is none and edited where there is one. The session is the core's
// (createHilosI18nLanguageNameWindow): the parent row held in focus, the mode
// fixed at opening, the creation form, the baseline/draft/incoming merge of an
// edit, its notice and the one canSave verdict. This view mirrors it, starts and
// disposes it with itself and with a new session bound in its place, and gives
// every opening a tracked action of its own, so a late refusal from a previous
// address paints nothing on the next one. An empty text is a value the server
// stores, never a deletion. The window closes on the server's `::success`, a
// refusal stays in its reserved row, and a busy save holds Cancel, Esc, the
// backdrop and the close button. A viewer of the admin view mode reads and
// fills the form, the conflict choices stay live, and neither Save nor Enter
// sends. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_I18N_LANGUAGE_NAME_COPY,
  HILOS_I18N_LANGUAGE_NAME_MAX,
} from '@hilos/core/admin/i18n'
import { hilosRowEditIdle, subscribeSignal } from '@hilos/core/base'
import type {
  HilosI18nLanguageNameTarget,
  HilosI18nLanguageNameWindow as NameWindow,
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
type NameWindowNotice = ReturnType<NameWindow['notice']['get']>

/** The add/edit window of a language name or one of its locale corrections. */
@Component({
  selector: 'hilos-i18n-language-name-window',
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
      [confirmOnClose]="dirty()"
      [closeOnBackdrop]="!saving()"
      [closeOnEsc]="!saving()"
      [closeDisabled]="saving()"
    >
      <div modalHeader data-id="i18n-language-name-title">
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
      <div data-id="i18n-language-name-window">
        <dl class="row small mb-3">
          <dt class="col-sm-4">{{ copy.labels.subject }}</dt>
          <dd class="col-sm-8">
            {{ target()?.languageNativeName }} ({{ target()?.languageCode }})
          </dd>
          <dt class="col-sm-4">{{ copy.labels.inLanguage }}</dt>
          <dd class="col-sm-8">
            {{ target()?.inLanguageNativeName }} ({{
              target()?.inLanguageCode
            }})
          </dd>
          @if (target()?.localeCode; as localeCode) {
            <dt class="col-sm-4">{{ copy.labels.locale }}</dt>
            <dd class="col-sm-8">{{ localeCode }}</dd>
          }
        </dl>
        <form (submit)="submit($event)">
          <label class="form-label" for="i18n-language-name-input">{{
            copy.labels.name
          }}</label>
          <input
            id="i18n-language-name-input"
            class="form-control"
            [value]="form().name"
            [attr.maxlength]="nameMax"
            [disabled]="saving()"
            aria-describedby="i18n-language-name-hint"
            data-id="i18n-language-name-input"
            data-autofocus
            (input)="changeName($event)"
          />
          <div id="i18n-language-name-hint" class="form-text">{{ hint() }}</div>
        </form>
        <div data-id="i18n-language-name-error">
          <hilos-action-error
            [action]="action()"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <hilos-edit-notice
          [kind]="notice()?.kind ?? null"
          [text]="notice()?.text ?? ''"
          dataId="i18n-language-name-notice"
        />
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
                [disabled]="saving()"
                data-id="i18n-language-name-cancel"
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
                [loading]="action().loading()"
                [disabled]="saveDisabled"
                data-id="i18n-language-name-submit"
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
            [disabled]="saving()"
            data-id="i18n-language-name-cancel"
            (click)="requestClose()"
          >
            {{ copy.cancel }}
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="action().loading()"
            [disabled]="!canSubmit()"
            data-id="i18n-language-name-submit"
            (click)="submit()"
          >
            {{ saveLabel() }}
          </button>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLanguageNameWindow {
  /** The core window session over the names table of the page. */
  readonly window = input.required<NameWindow>()

  protected readonly copy = HILOS_I18N_LANGUAGE_NAME_COPY
  protected readonly nameMax = HILOS_I18N_LANGUAGE_NAME_MAX
  protected readonly opened = signal(false)
  protected readonly target = signal<HilosI18nLanguageNameTarget | null>(null)
  protected readonly mode = signal<'add' | 'edit' | null>(null)
  protected readonly form = signal({ name: '' })
  protected readonly state = signal(hilosRowEditIdle({ name: '' }))
  protected readonly dirty = signal(false)
  protected readonly notice = signal<NameWindowNotice>(null)
  protected readonly saving = signal(false)
  protected readonly canSave = signal(false)
  protected readonly canSubmit = signal(false)
  protected readonly saveLabel = signal<string>(
    HILOS_I18N_LANGUAGE_NAME_COPY.add,
  )
  // Each opening has its own runner: a previous address's late failure cannot
  // paint an error or unlock the action of the next opening.
  protected readonly action = signal(createHilosTrackedAction())
  private readonly lookOnly = injectLookOnly()

  protected readonly title = computed(() => {
    const held = this.target()
    if (held === null) return ''
    const key =
      held.localeCode === null
        ? held.mode
        : held.mode === 'add'
          ? 'addCorrection'
          : 'editCorrection'

    return `${this.copy.title[key]} · ${held.languageNativeName}`
  })
  protected readonly hint = computed(() =>
    this.target()?.localeCode === null
      ? this.copy.hints.base
      : this.copy.hints.correction,
  )

  constructor() {
    // Start the bound session and mirror it; a new session bound in its place,
    // or the end of this view, disposes the old one and lets its row go.
    effect((onCleanup) => {
      const window = this.window()
      window.start()
      const stops = [
        mirrorHilosSignal(window.opened, this.opened),
        mirrorHilosSignal(window.target, this.target),
        mirrorHilosSignal(window.mode, this.mode),
        mirrorHilosSignal(window.form, this.form),
        mirrorHilosSignal(window.state, this.state),
        mirrorHilosSignal(window.dirty, this.dirty),
        mirrorHilosSignal(window.notice, this.notice),
        mirrorHilosSignal(window.saving, this.saving),
        mirrorHilosSignal(window.canSave, this.canSave),
        mirrorHilosSignal(window.canSubmit, this.canSubmit),
        mirrorHilosSignal(window.saveLabel, this.saveLabel),
        subscribeSignal(window.target, () =>
          this.action.set(createHilosTrackedAction()),
        ),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        window.dispose()
      })
    })
  }

  protected closeWindow(): void {
    this.window().close()
  }

  protected changeName(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.window().patchForm({ name: event.target.value })
    }
  }

  // Save and Enter go through one door: nothing leaves for a viewer, while a
  // save is in flight, or while the session refuses what would leave.
  protected submit(event?: Event): void {
    event?.preventDefault()
    if (
      this.lookOnly.locked() ||
      this.saving() ||
      !(this.mode() === 'add' ? this.canSubmit() : this.canSave())
    ) {
      return
    }
    void this.window().save(this.action().run)
  }
}
