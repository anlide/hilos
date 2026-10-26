// HilosI18nCountryNameWindow — the one window of a country's name written in a
// language: the base name or one locale correction of it, added where there is
// none and edited where there is one. The session is the core's
// (createHilosI18nCountryNameWindow): the writing language's row held in focus,
// the title fixed at opening, live occupancy, the baseline/draft/incoming merge,
// its notice and the one canSave verdict. This view mirrors it, starts and
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
  HILOS_I18N_COUNTRY_NAME_COPY,
  HILOS_I18N_COUNTRY_NAME_MAX,
} from '@hilos/core/admin/i18n'
import { hilosRowEditIdle, subscribeSignal } from '@hilos/core/base'
import type {
  HilosI18nCountryNameTarget,
  HilosI18nCountryNameWindow as NameWindow,
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
type NameWindowNoticeKind = ReturnType<NameWindow['noticeKind']['get']>

/** The add/edit window of a country name or one of its locale corrections. */
@Component({
  selector: 'hilos-i18n-country-name-window',
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
      [confirmOnClose]="dirty() && !saving()"
      [busy]="saving()"
    >
      <div modalHeader data-id="i18n-country-name-title">
        <h5
          hilosConflictHeader
          [title]="title()"
          [conflict]="state().conflict"
        ></h5>
      </div>
      <div data-id="i18n-country-name-window">
        @if (target(); as held) {
          <dl class="row small mb-3">
            <dt class="col-sm-4">{{ copy.labels.subject }}</dt>
            <dd class="col-sm-8">
              {{ held.countryName }} ({{ held.countryCode.toUpperCase() }})
            </dd>
            <dt class="col-sm-4">{{ copy.labels.inLanguage }}</dt>
            <dd class="col-sm-8">
              {{ held.inLanguageNativeName }} ({{ held.inLanguageCode }})
            </dd>
            @if (held.localeCode !== null) {
              <dt class="col-sm-4">{{ copy.labels.locale }}</dt>
              <dd class="col-sm-8">{{ held.localeCode }}</dd>
            }
          </dl>
        }
        <form (submit)="submit($event)">
          <label class="form-label" for="i18n-country-name-input">{{
            copy.labels.name
          }}</label>
          <input
            id="i18n-country-name-input"
            class="form-control"
            [value]="form().name"
            [attr.maxlength]="nameMax"
            [disabled]="saving()"
            aria-describedby="i18n-country-name-hint"
            data-id="i18n-country-name-input"
            data-autofocus
            (input)="changeName($event)"
          />
          <div id="i18n-country-name-hint" class="form-text">{{ hint() }}</div>
        </form>
        @if (target()?.localeCode === null) {
          <p class="small mt-3 mb-0">{{ copy.hints.catalog }}</p>
        }
        <div data-id="i18n-country-name-error">
          <hilos-action-error
            [action]="action()"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <hilos-edit-notice
          [kind]="noticeKind()"
          [text]="noticeText()"
          dataId="i18n-country-name-notice"
        />
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <div
          hilosConflictActions
          [conflict]="state().conflict"
          [disableSave]="!saveAvailable()"
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
              data-id="i18n-country-name-cancel"
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
              [loading]="action().busy()"
              [disabled]="saveDisabled"
              data-id="i18n-country-name-submit"
              (click)="onSave()"
            >
              {{ saveLabel() }}
            </button>
          </ng-template>
        </div>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nCountryNameWindow {
  /** The core window session over the names table of the page. */
  readonly window = input.required<NameWindow>()

  protected readonly copy = HILOS_I18N_COUNTRY_NAME_COPY
  protected readonly nameMax = HILOS_I18N_COUNTRY_NAME_MAX
  protected readonly opened = signal(false)
  protected readonly target = signal<HilosI18nCountryNameTarget | null>(null)
  protected readonly form = signal({ name: '' })
  protected readonly state = signal(hilosRowEditIdle({ name: '' }))
  protected readonly dirty = signal(false)
  protected readonly noticeKind = signal<NameWindowNoticeKind>(null)
  protected readonly noticeText = signal('')
  protected readonly saving = signal(false)
  protected readonly canSave = signal(false)
  protected readonly saveAvailable = signal(false)
  protected readonly saveLabel = signal<string>(
    HILOS_I18N_COUNTRY_NAME_COPY.add,
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

    return `${this.copy.title[key]} · ${held.countryName}`
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
        mirrorHilosSignal(window.form, this.form),
        mirrorHilosSignal(window.state, this.state),
        mirrorHilosSignal(window.dirty, this.dirty),
        mirrorHilosSignal(window.noticeKind, this.noticeKind),
        mirrorHilosSignal(window.noticeText, this.noticeText),
        mirrorHilosSignal(window.saving, this.saving),
        mirrorHilosSignal(window.canSave, this.canSave),
        mirrorHilosSignal(window.saveAvailable, this.saveAvailable),
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
    if (this.lookOnly.locked() || !this.canSave()) {
      return
    }
    void this.window().save(this.action().run)
  }
}
