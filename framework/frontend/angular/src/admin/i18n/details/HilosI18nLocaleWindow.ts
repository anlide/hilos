// HilosI18nLocaleWindow — the one window of a locale of the language, opened from
// its pair's row in the locales table: add where the pair has no locale, edit a
// switched-off locale, view a switched-on one — the mode fixed at opening. The
// seven formats are chosen from closed lists the first page answer carries,
// never typed: an add starts from the built-in catalog's formats when it knows
// the pair, or from "Choose…" with Save unavailable until all seven are chosen.
// The sessions are the core's (createHilosI18nLocaleAdd, createHilosI18nLocaleEdit
// with its baseline/draft/incoming merge, the switch dispatches and the switch-off
// verdict); this view mirrors them, owns their tracked actions, and draws the
// switching inside the same window as one more step: switching on stands beside
// Save, switching off beside Close, and Back returns to the form without sending
// anything. Switching on freezes what is saved, so a draft with unsaved changes or
// an open conflict asks to be saved first. Every part of the window — the header,
// the body and the footer — holds the room of its tallest step through an
// invisible twin (.hilos-stack), so stepping moves nothing; the twins carry no
// form, no control and no data-id, and stay out of the accessibility tree and of
// focus. A new step puts the focus on the dialog. The window closes on the
// server's `::success`, a refusal stays in its reserved row, and a busy action
// holds Cancel, Back, Esc, the backdrop and the close button. A locale gone or
// changed elsewhere keeps the window and the draft, said in the notice row. A
// viewer of the admin view mode opens and reads every step, the conflict choices
// and Back stay live, and neither the confirms nor Enter send. Bootstrap classes
// only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterRenderEffect,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core'
import {
  HILOS_I18N_LOCALE_COPY,
  createHilosI18nLocaleAdd,
  createHilosI18nLocaleEdit,
  createHilosI18nLocaleSwitchOff,
  createHilosI18nLocaleSwitchOn,
  hilosI18nLocaleCountryLabel,
  hilosI18nLocaleSwitchOff,
} from '@hilos/core/admin/i18n'
import { createSignal, focusInitial, hilosRowEditIdle } from '@hilos/core/base'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
  HilosI18nLocaleAddForm,
  HilosI18nLocaleFormats,
  HilosI18nLocaleRow,
  HilosI18nLocaleTemplates,
  HilosI18nLocaleWindowMode,
} from '@hilos/core/admin/i18n'
import type {
  RowEditNoticeKind,
  TableViewportController,
} from '@hilos/core/base'

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

/** The seven formats of a locale, in the order the form lists them. */
const FIELDS = [
  'date',
  'time',
  'number',
  'phone',
  'address',
  'measurement',
  'collation',
] as const

/** One of the seven formats. */
type LocaleField = (typeof FIELDS)[number]

/** The pair the page opened the window over, and the mode it opened in. */
export interface HilosI18nLocaleWindowOpening {
  /** The row key of the pair: the code of its locale (`ru`, `ru-UA`). */
  readonly rowKey: string
  /** The mode fixed at opening from the pair's fresh row. */
  readonly mode: HilosI18nLocaleWindowMode
}

/** The empty creation form: nothing chosen yet. */
const NOTHING_CHOSEN: HilosI18nLocaleAddForm = {
  date: '',
  time: '',
  number: '',
  phone: '',
  address: '',
  measurement: '',
  collation: '',
}

/** The footer row of one step, as the modal draws its own. */
const FOOTER = 'modal-footer hilos-button-row p-0 m-0 border-0 align-self-end'

/** The add/edit/view window of one locale with its switch-on and switch-off steps. */
@Component({
  selector: 'hilos-i18n-locale-window',
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
      [open]="opened() !== null"
      (openChange)="$event || closeWindow()"
      [title]="title()"
      [confirmOnClose]="mode() === 'edit' && editState().dirty"
      [busy]="busy()"
      initialFocus="dialog"
    >
      <div
        modalHeader
        [class]="mode() !== 'add' ? 'hilos-stack flex-grow-1' : ''"
      >
        <!-- Each part holds its tallest ordinary step: a body twin alone cannot
        keep a wrapped heading or the footer's conflict row from moving Save. -->
        @if (mode() !== 'add') {
          <div class="hilos-stack invisible" aria-hidden="true" inert>
            <h5 class="modal-title d-inline-flex align-items-center gap-2 mb-0">
              <span>{{ formTitle() }}</span>
              @if (mode() === 'edit') {
                <span class="badge text-bg-danger">conflict</span>
              }
            </h5>
            <h5 class="modal-title mb-0">
              {{ mode() === 'edit' ? switchOnTitle() : switchOffTitle() }}
            </h5>
          </div>
        }
        <div data-id="i18n-locale-title">
          @if (mode() === 'edit' && step() === 'form') {
            <h5
              hilosConflictHeader
              [title]="title()"
              [conflict]="editState().conflict"
            ></h5>
          } @else {
            <h5 class="modal-title mb-0">{{ title() }}</h5>
          }
        </div>
      </div>
      <div #body data-id="i18n-locale-window">
        <div class="d-flex flex-wrap gap-3 mb-2 small">
          <div>
            <span class="text-body-secondary">{{ copy.countryLabel }}</span>
            <span class="fw-semibold">{{ country() ?? copy.noCountry }}</span>
          </div>
          <div>
            <span class="text-body-secondary">{{ copy.codeLabel }}</span>
            <code data-id="i18n-locale-code">{{
              row()?.localeCode ??
                card()?.code +
                  (row()?.countryCode
                    ? '-' + row()!.countryCode!.toUpperCase()
                    : '')
            }}</code>
          </div>
        </div>
        <div [class]="mode() !== 'add' ? 'hilos-stack' : ''">
          @if (mode() !== 'add') {
            <div class="hilos-stack invisible" aria-hidden="true" inert>
              <p class="small text-body-secondary">{{ copy.lead }}</p>
              <p class="small text-body-secondary">
                {{
                  mode() === 'edit' ? copy.switchOn.body : copy.switchOff.body
                }}
              </p>
            </div>
          }
          @switch (step()) {
            @case ('switchOn') {
              <p
                class="small text-body-secondary"
                data-id="i18n-locale-switch-on-body"
              >
                {{ copy.switchOn.body }}
              </p>
            }
            @case ('switchOff') {
              <p
                class="small text-body-secondary"
                data-id="i18n-locale-switch-off-body"
              >
                {{ copy.switchOff.body }}
              </p>
            }
            @default {
              <p class="small text-body-secondary">{{ copy.lead }}</p>
            }
          }
        </div>
        <div data-id="i18n-locale-error">
          <div
            [attr.data-id]="
              step() === 'switchOff' ? 'i18n-locale-switch-off-error' : null
            "
          >
            <hilos-action-error
              [action]="activeAction()"
              [detailsTitle]="refusalTitle()"
            />
          </div>
        </div>
        <hilos-edit-notice
          [kind]="notice().kind"
          [text]="notice().text"
          dataId="i18n-locale-notice"
        />
        <div [class]="mode() !== 'add' ? 'hilos-stack' : ''">
          <!-- Inert twins hold every ordinary step's room without entering
          locators or focus. -->
          @if (mode() !== 'add') {
            <div class="hilos-stack invisible" aria-hidden="true" inert>
              <div>
                <div class="row g-2">
                  @for (field of fields; track field) {
                    <div [class]="field === 'address' ? 'col-12' : 'col-6'">
                      <label class="form-label small fw-semibold">{{
                        copy.labels[field]
                      }}</label>
                      <div class="form-control form-control-sm">&nbsp;</div>
                    </div>
                  }
                </div>
                <div
                  [class]="
                    'alert small py-2 mt-3 mb-0 ' +
                    (frozen() ? 'alert-warning' : 'alert-info')
                  "
                >
                  <i
                    [class]="'bi me-1 ' + (frozen() ? 'bi-lock' : 'bi-pencil')"
                    aria-hidden="true"
                  ></i>
                  {{ plate() }}
                </div>
              </div>
              <div>
                @if (catalog()) {
                  <div class="alert alert-secondary small py-2 mb-0">
                    {{
                      mode() === 'edit'
                        ? copy.switchOn.catalogNote
                        : copy.switchOff.catalogNote
                    }}
                  </div>
                }
                @if (mode() === 'edit') {
                  <div class="alert alert-warning small py-2 mb-0">
                    {{ copy.switchOn.unsaved }}
                  </div>
                } @else {
                  <div class="hilos-stack">
                    <div class="alert alert-danger small py-2 mb-0">
                      {{ copy.switchOff.defaultReason }}
                    </div>
                    <div class="alert alert-warning small py-2 mb-0">
                      {{ copy.switchOff.languageWarning }}
                    </div>
                    <div class="alert alert-warning small py-2 mb-0">
                      {{ copy.switchOff.countryWarning }}
                    </div>
                  </div>
                }
              </div>
            </div>
          }
          @switch (step()) {
            @case ('switchOn') {
              <div>
                @if (catalog()) {
                  <div
                    class="alert alert-secondary small py-2 mb-0"
                    data-id="i18n-locale-switch-on-note"
                  >
                    {{ copy.switchOn.catalogNote }}
                  </div>
                }
                @if (unsavedAtStep()) {
                  <div
                    class="alert alert-warning small py-2 mb-0"
                    data-id="i18n-locale-switch-on-unsaved"
                  >
                    {{ copy.switchOn.unsaved }}
                  </div>
                }
              </div>
            }
            @case ('switchOff') {
              <div>
                @if (catalog()) {
                  <div
                    class="alert alert-secondary small py-2 mb-0"
                    data-id="i18n-locale-switch-off-note"
                  >
                    {{ copy.switchOff.catalogNote }}
                  </div>
                }
                @if (offVerdict().reason; as reason) {
                  <div
                    class="alert alert-danger small py-2 mb-0"
                    data-id="i18n-locale-switch-off-reason"
                  >
                    {{ reason }}
                  </div>
                }
                @if (offVerdict().warning; as warning) {
                  <div
                    class="alert alert-warning small py-2 mb-0"
                    data-id="i18n-locale-switch-off-warning"
                  >
                    {{ warning }}
                  </div>
                }
              </div>
            }
            @default {
              <div>
                <form (submit)="submit($event)">
                  <div class="row g-2">
                    @for (field of fields; track field) {
                      <div [class]="field === 'address' ? 'col-12' : 'col-6'">
                        <label
                          class="form-label small fw-semibold"
                          [attr.for]="'i18n-locale-' + field"
                          >{{ copy.labels[field] }}</label
                        >
                        <select
                          [id]="'i18n-locale-' + field"
                          class="form-select form-select-sm"
                          [disabled]="fieldsDisabled()"
                          [attr.data-id]="'i18n-locale-field-' + field"
                          (change)="changeField(field, $event)"
                        >
                          @if (form()[field] === '') {
                            <option value="" disabled selected>
                              {{ copy.choose }}
                            </option>
                          }
                          @for (
                            value of templates()?.[field] ?? [];
                            track value
                          ) {
                            <option
                              [value]="value"
                              [selected]="value === form()[field]"
                            >
                              {{ choiceLabel(field, value) }}
                            </option>
                          }
                        </select>
                      </div>
                    }
                  </div>
                </form>
                <div
                  [class]="
                    'alert small py-2 mt-3 mb-0 ' +
                    (frozen()
                      ? 'alert-warning'
                      : mode() === 'edit'
                        ? 'alert-info'
                        : 'alert-secondary')
                  "
                  data-id="i18n-locale-plate"
                >
                  @if (frozen()) {
                    <i class="bi bi-lock me-1" aria-hidden="true"></i>
                  } @else if (mode() === 'edit') {
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                  }
                  {{ plate() }}
                </div>
              </div>
            }
          }
        </div>
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        @switch (mode()) {
          @case ('add') {
            <button
              type="button"
              class="btn btn-secondary"
              [disabled]="busy()"
              data-id="i18n-locale-cancel"
              (click)="requestClose()"
            >
              {{ copy.cancel }}
            </button>
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="addAction.loading()"
              [disabled]="!addCanSave()"
              data-id="i18n-locale-submit"
              (click)="submit()"
            >
              {{ addGone() ? 'Deleted' : copy.add }}
            </button>
          }
          @case ('edit') {
            <div class="hilos-stack w-100 m-0">
              <div class="hilos-stack invisible" aria-hidden="true" inert>
                <div [class]="footer">
                  <div
                    class="hilos-button-group d-md-flex align-items-center gap-2 flex-wrap"
                  >
                    <div class="hilos-conflict-choices d-flex gap-2">
                      <span class="btn btn-outline-secondary">Keep mine</span>
                      <span class="btn btn-outline-secondary">Take theirs</span>
                    </div>
                    <span class="btn btn-secondary">{{ copy.cancel }}</span>
                    <span class="btn btn-outline-primary">{{
                      copy.switchOn.open
                    }}</span>
                    <span class="btn btn-primary">{{ editSaveLabel() }}</span>
                  </div>
                </div>
                <div [class]="footer">
                  <span class="btn btn-secondary">{{
                    copy.switchOn.back
                  }}</span>
                  <span class="btn btn-primary">{{
                    editState().gone ? 'Deleted' : copy.switchOn.confirm
                  }}</span>
                </div>
              </div>
              <div [class]="footer">
                @if (step() === 'switchOn') {
                  <button
                    type="button"
                    class="btn btn-secondary"
                    [disabled]="busy()"
                    data-id="i18n-locale-switch-on-back"
                    (click)="back()"
                  >
                    {{ copy.switchOn.back }}
                  </button>
                  <button
                    hilosLoadingButton
                    class="btn-primary"
                    [loading]="switchAction.loading()"
                    [disabled]="
                      busy() ||
                      unsavedAtStep() ||
                      lockedOn() ||
                      editState().gone
                    "
                    data-id="i18n-locale-switch-on-confirm"
                    (click)="confirmSwitchOn()"
                  >
                    {{ editState().gone ? 'Deleted' : copy.switchOn.confirm }}
                  </button>
                } @else {
                  <div
                    hilosConflictActions
                    [conflict]="editState().conflict"
                    [disableSave]="!editCanSave()"
                    [saveLabel]="editSaveLabel()"
                    (save)="submit()"
                    (acceptMine)="session().editor.keepMine()"
                    (acceptTheirs)="session().editor.takeTheirs()"
                  >
                    <ng-template #cancelButton>
                      <button
                        type="button"
                        class="btn btn-secondary"
                        [disabled]="busy()"
                        data-id="i18n-locale-cancel"
                        (click)="requestClose()"
                      >
                        {{ copy.cancel }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-outline-primary"
                        [disabled]="busy() || editState().gone || lockedOn()"
                        data-id="i18n-locale-switch-on"
                        (click)="openSwitchOn()"
                      >
                        {{ copy.switchOn.open }}
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
                        [loading]="editAction.loading()"
                        [disabled]="saveDisabled"
                        data-id="i18n-locale-submit"
                        (click)="onSave()"
                      >
                        {{ editSaveLabel() }}
                      </button>
                    </ng-template>
                  </div>
                }
              </div>
            </div>
          }
          @default {
            <div class="hilos-stack w-100 m-0">
              <div class="hilos-stack invisible" aria-hidden="true" inert>
                <div [class]="footer">
                  <span class="btn btn-secondary">{{ copy.close }}</span>
                  <span class="btn btn-outline-danger">{{
                    copy.switchOff.open
                  }}</span>
                </div>
                <div [class]="footer">
                  <span class="btn btn-secondary">{{
                    copy.switchOff.back
                  }}</span>
                  <span class="btn btn-danger">{{
                    copy.switchOff.confirm
                  }}</span>
                </div>
              </div>
              <div [class]="footer">
                @if (step() === 'switchOff') {
                  <button
                    type="button"
                    class="btn btn-secondary"
                    [disabled]="busy()"
                    data-id="i18n-locale-switch-off-back"
                    (click)="back()"
                  >
                    {{ copy.switchOff.back }}
                  </button>
                  <button
                    hilosLoadingButton
                    class="btn-danger"
                    [loading]="offAction.loading()"
                    [disabled]="busy() || offVerdict().disabled"
                    data-id="i18n-locale-switch-off-confirm"
                    (click)="confirmSwitchOff()"
                  >
                    {{ copy.switchOff.confirm }}
                  </button>
                } @else {
                  <button
                    type="button"
                    class="btn btn-secondary"
                    [disabled]="busy()"
                    data-id="i18n-locale-close"
                    (click)="requestClose()"
                  >
                    {{ copy.close }}
                  </button>
                  <button
                    type="button"
                    class="btn btn-outline-danger"
                    [disabled]="
                      busy() || viewGone() || liveRow()?.enabled !== true
                    "
                    data-id="i18n-locale-switch-off"
                    (click)="openSwitchOff()"
                  >
                    {{ copy.switchOff.open }}
                  </button>
                }
              </div>
            </div>
          }
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLocaleWindow {
  /** The project context whose action lifecycle the window's actions run on. */
  readonly context = input.required<HilosI18nLanguageContext>()
  /** The locales table's controller: the pair's row is held in its focus. */
  readonly controller =
    input.required<TableViewportController<HilosI18nLocaleRow>>()
  /** The live language card, or null once it is gone. */
  readonly card = input.required<HilosI18nLanguageCard | null>()
  /** The closed format lists of the first page answer, or null before it. */
  readonly templates = input.required<HilosI18nLocaleTemplates | null>()
  /** The pair the page opened the window over, or null while it is closed. */
  readonly opened = input.required<HilosI18nLocaleWindowOpening | null>()
  /** The window closed: Cancel, Esc, the backdrop, or its own success. */
  readonly closed = output<void>()

  protected readonly copy = HILOS_I18N_LOCALE_COPY
  protected readonly fields = FIELDS
  protected readonly footer = FOOTER

  // The language the window was opened under: every action of this opening
  // addresses it, whatever the card says later.
  private readonly address = createSignal<number | null>(null)

  /** The creation and edit sessions and the switch dispatches of one binding. */
  protected readonly session = computed(() => {
    const context = this.context()
    const controller = this.controller()

    return {
      controller,
      editor: createHilosI18nLocaleEdit(controller, context),
      creator: createHilosI18nLocaleAdd(controller, context),
      switcher: createHilosI18nLocaleSwitchOn(context),
      offSwitcher: createHilosI18nLocaleSwitchOff(context),
    }
  })

  protected readonly editRow = signal<HilosI18nLocaleRow | null>(null)
  protected readonly editForm = signal<HilosI18nLocaleFormats>({
    date: '',
    time: '',
    number: '',
    phone: '',
    address: '',
    measurement: 'metric',
    collation: '',
  })
  protected readonly editState = signal(
    hilosRowEditIdle<HilosI18nLocaleFormats>(this.editForm()),
  )
  protected readonly editNotice = signal('')
  protected readonly editCanSave = signal(false)
  protected readonly editSaveLabel = signal<string>(HILOS_I18N_LOCALE_COPY.save)
  protected readonly addRow = signal<HilosI18nLocaleRow | null>(null)
  protected readonly addForm = signal<HilosI18nLocaleAddForm>(NOTHING_CHOSEN)
  protected readonly addCanSave = signal(false)
  protected readonly addElsewhere = signal(false)
  protected readonly addGone = signal(false)
  private readonly focusedRow = signal<HilosI18nLocaleRow | undefined>(
    undefined,
  )

  protected readonly addAction = createHilosTrackedAction()
  protected readonly editAction = createHilosTrackedAction()
  protected readonly switchAction = createHilosTrackedAction()
  protected readonly offAction = createHilosTrackedAction()
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)
  private readonly body = viewChild<ElementRef<HTMLElement>>('body')

  protected readonly step = signal<'form' | 'switchOn' | 'switchOff'>('form')
  protected readonly unsavedAtStep = signal(false)
  private readonly openedName = signal('')
  private readonly viewSnapshot = signal<HilosI18nLocaleRow | null>(null)

  protected readonly mode = computed(() => this.opened()?.mode ?? null)
  protected readonly row = computed(() => {
    const mode = this.mode()

    return mode === 'add'
      ? this.addRow()
      : mode === 'edit'
        ? this.editRow()
        : this.viewSnapshot()
  })
  protected readonly liveRow = computed(() => {
    const focused = this.focusedRow()

    return focused?.rowKey === this.row()?.rowKey &&
      (this.mode() === 'add' || focused?.localeId === this.row()?.localeId)
      ? focused
      : undefined
  })
  private readonly shownRow = computed(() =>
    this.mode() === 'view'
      ? (this.liveRow() ?? this.viewSnapshot())
      : this.row(),
  )
  protected readonly country = computed(() => {
    const row = this.row()

    return row === null ? null : hilosI18nLocaleCountryLabel(row)
  })
  protected readonly formTitle = computed(() =>
    this.copy.title(this.mode() ?? 'view', this.openedName(), this.country()),
  )
  protected readonly switchOnTitle = computed(() =>
    this.copy.switchOn.title(this.openedName(), this.country()),
  )
  protected readonly switchOffTitle = computed(() =>
    this.copy.switchOff.title(this.openedName(), this.country()),
  )
  protected readonly title = computed(() => {
    const step = this.step()

    return step === 'switchOn'
      ? this.switchOnTitle()
      : step === 'switchOff'
        ? this.switchOffTitle()
        : this.formTitle()
  })
  protected readonly form = computed((): HilosI18nLocaleAddForm => {
    const mode = this.mode()
    if (mode === 'add') return this.addForm()
    if (mode === 'edit') return this.editForm()

    return (
      this.shownRow()?.formats ?? this.viewSnapshot()?.formats ?? this.addForm()
    )
  })
  protected readonly busy = computed(
    () =>
      this.addAction.busy() ||
      this.editAction.busy() ||
      this.switchAction.busy() ||
      this.offAction.busy(),
  )
  protected readonly viewGone = computed(() => {
    const live = this.liveRow()

    return (
      this.card() === null ||
      live === undefined ||
      live.localeCode === null ||
      live.formats === null
    )
  })
  protected readonly offVerdict = computed(() =>
    hilosI18nLocaleSwitchOff(this.card(), this.liveRow()),
  )
  protected readonly lockedOn = computed(
    () =>
      this.mode() === 'edit' &&
      !this.busy() &&
      this.liveRow()?.enabled === true,
  )
  private readonly switchedOff = computed(
    () =>
      this.mode() === 'view' &&
      !this.busy() &&
      this.liveRow()?.enabled === false,
  )
  protected readonly frozen = computed(
    () => this.mode() === 'view' || this.lockedOn(),
  )
  protected readonly catalog = computed(
    () => (this.row()?.catalogFormats ?? null) !== null,
  )
  protected readonly fieldsDisabled = computed(
    () =>
      this.frozen() ||
      (this.mode() === 'edit' && this.editState().gone) ||
      (this.mode() === 'add' && this.addGone()) ||
      this.busy(),
  )
  /** The notice row's kind and words, as the mode and the live pair decide. */
  protected readonly notice = computed(
    (): { kind: RowEditNoticeKind | null; text: string } => {
      const elsewhere = this.copy.elsewhere
      const mode = this.mode()
      if (mode === 'add') {
        return this.addGone()
          ? { kind: 'deleted', text: elsewhere.deleted }
          : this.addElsewhere()
            ? { kind: 'updated', text: elsewhere.added }
            : { kind: null, text: '' }
      }
      if (mode === 'edit') {
        const state = this.editState()

        return state.gone
          ? { kind: 'deleted', text: this.editNotice() }
          : this.lockedOn()
            ? { kind: 'updated', text: elsewhere.switchedOn }
            : { kind: state.notice?.kind ?? null, text: this.editNotice() }
      }

      return this.viewGone()
        ? { kind: 'deleted', text: elsewhere.deleted }
        : this.switchedOff()
          ? { kind: 'updated', text: elsewhere.switchedOff }
          : { kind: null, text: '' }
    },
  )
  protected readonly plate = computed(() => {
    const plate = this.copy.plate
    const known = (this.row()?.catalogFormats ?? null) !== null
    if (this.mode() === 'add') return known ? plate.addKnown : plate.addOwn
    if (this.frozen()) return plate.view

    return known ? plate.editKnown : plate.editOwn
  })
  protected readonly activeAction = computed(() => {
    const step = this.step()
    if (step === 'switchOn') return this.switchAction
    if (step === 'switchOff') return this.offAction

    return this.mode() === 'add' ? this.addAction : this.editAction
  })
  protected readonly refusalTitle = computed(() => {
    const step = this.step()
    if (step === 'switchOn') return this.copy.switchOn.refusalTitle
    if (step === 'switchOff') return this.copy.switchOff.refusalTitle

    return this.mode() === 'add'
      ? this.copy.refusalTitle.add
      : this.copy.refusalTitle.edit
  })

  constructor() {
    // Start the bound sessions and mirror them; a new binding, or the end of
    // this view, disposes the old ones and lets their row go.
    effect((onCleanup) => {
      const { controller, editor, creator } = this.session()
      editor.start()
      const stops = [
        mirrorHilosSignal(editor.row, this.editRow),
        mirrorHilosSignal(editor.form, this.editForm),
        mirrorHilosSignal(editor.state, this.editState),
        mirrorHilosSignal(editor.noticeText, this.editNotice),
        mirrorHilosSignal(editor.canSave, this.editCanSave),
        mirrorHilosSignal(editor.saveLabel, this.editSaveLabel),
        mirrorHilosSignal(creator.row, this.addRow),
        mirrorHilosSignal(creator.form, this.addForm),
        mirrorHilosSignal(creator.canAdd, this.addCanSave),
        mirrorHilosSignal(creator.elsewhere, this.addElsewhere),
        mirrorHilosSignal(creator.gone, this.addGone),
        mirrorHilosSignal(controller.focusedRow, this.focusedRow),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        editor.dispose()
        creator.dispose()
        controller.releaseFocus()
      })
    })
    // Each opening starts on the form, without the refusals of the previous
    // one, and takes its pair into focus through the session of its mode. A
    // closing has already let the pair go — the window does that before it
    // says so — so a close never lets go of a row another window took since.
    effect(() => {
      const opening = this.opened()
      untracked(() => this.begin(opening))
    })
    // A new step replaces the body under the focus: put it on the dialog.
    let previous = this.step()
    afterRenderEffect(() => {
      const step = this.step()
      if (step === previous) return
      previous = step
      const dialog =
        this.body()?.nativeElement.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog, 'dialog')
    })
  }

  private begin(opening: HilosI18nLocaleWindowOpening | null): void {
    this.step.set('form')
    this.unsavedAtStep.set(false)
    if (opening === null) return
    const { controller, editor, creator } = this.session()
    const card = this.card()
    this.openedName.set(card?.nativeName ?? '')
    this.address.set(card?.languageId ?? null)
    for (const action of [
      this.addAction,
      this.editAction,
      this.switchAction,
      this.offAction,
    ]) {
      action.clearError()
    }
    if (opening.mode === 'add' && !creator.open(opening.rowKey)) {
      this.closed.emit()
    }
    if (opening.mode === 'edit' && !editor.open(opening.rowKey)) {
      this.closed.emit()
    }
    if (opening.mode === 'view') {
      const fresh = controller.focusRow(opening.rowKey)
      this.viewSnapshot.set(fresh)
      if (fresh === null) this.closed.emit()
    }
  }

  protected closeWindow(): void {
    if (this.busy()) return
    const { controller, editor, creator } = this.session()
    this.step.set('form')
    this.unsavedAtStep.set(false)
    editor.close()
    creator.close()
    controller.releaseFocus()
    this.closed.emit()
  }

  protected openSwitchOn(): void {
    const state = this.editState()
    if (this.busy() || this.lockedOn() || state.gone || this.row() === null)
      return
    this.unsavedAtStep.set(state.dirty || state.conflict)
    this.switchAction.clearError()
    this.step.set('switchOn')
  }

  protected openSwitchOff(): void {
    if (
      this.busy() ||
      this.mode() !== 'view' ||
      this.viewGone() ||
      this.liveRow()?.enabled !== true
    )
      return
    this.offAction.clearError()
    this.step.set('switchOff')
  }

  // Back only returns to the form: nothing is sent.
  protected back(): void {
    if (!this.busy()) this.step.set('form')
  }

  protected async confirmSwitchOn(): Promise<void> {
    const row = this.row()
    if (
      this.lookOnly.locked() ||
      this.busy() ||
      this.unsavedAtStep() ||
      this.lockedOn() ||
      this.editState().gone ||
      row === null
    )
      return
    const { switcher } = this.session()
    if (
      (await this.switchAction.run(
        switcher.switchOn(row.languageId, row.countryId, row.localeId!),
      )) &&
      !this.destroyRef.destroyed
    ) {
      this.closeWindow()
    }
  }

  protected async confirmSwitchOff(): Promise<void> {
    const live = this.liveRow()
    if (
      this.lookOnly.locked() ||
      this.busy() ||
      this.offVerdict().disabled ||
      this.card() === null ||
      live === undefined
    )
      return
    const { offSwitcher } = this.session()
    if (
      (await this.offAction.run(
        offSwitcher.switchOff(live.languageId, live.countryId, live.localeId!),
      )) &&
      !this.destroyRef.destroyed
    ) {
      this.closeWindow()
    }
  }

  protected choiceLabel(field: LocaleField, value: string): string {
    return field === 'measurement' &&
      (value === 'metric' || value === 'imperial')
      ? this.copy.measurement[value]
      : value
  }

  protected changeField(field: LocaleField, event: Event): void {
    const target = event.target
    if (!(target instanceof HTMLSelectElement)) return
    const { editor, creator } = this.session()
    if (this.mode() === 'add') creator.setField(field, target.value)
    if (this.mode() === 'edit') {
      editor.patchForm({
        [field]: target.value,
      } as Partial<HilosI18nLocaleFormats>)
    }
  }

  // Save and Enter go through one door: nothing leaves for a viewer, while an
  // action is in flight, or from a step other than the form; the sessions refuse
  // what may not leave and close themselves on `::success`.
  protected async submit(event?: Event): Promise<void> {
    event?.preventDefault()
    if (this.lookOnly.locked() || this.busy() || this.step() !== 'form') return
    const { editor, creator } = this.session()
    if (this.mode() === 'add') {
      await creator.add(this.addAction.run)
      if (!this.destroyRef.destroyed && !creator.opened.get()) {
        this.closed.emit()
      }
    } else if (this.mode() === 'edit') {
      await editor.save(this.editAction.run)
      if (!this.destroyRef.destroyed && !editor.opened.get()) {
        this.closed.emit()
      }
    }
  }
}
