// HilosI18nLanguageDelete — the one delete confirmation of a language, opened
// from a languages-table row (the row in focus) or from the main card (its page
// datum). It fixes the code, the name and the action at the moment it opens, and
// follows the live source after that: a language gone while the window stands
// idle is said in the notice row and blocks the confirm, and the window stays
// open until it is closed by hand. Its own deletion is different: the echo that
// takes the source away while the action is in flight says nothing, the window
// keeps its target and its action, and only `::success` closes it and tells the
// page — a card returns to the list from there. The dependency verdict that
// opened it is a hint; the server checks again when the action runs, and its
// refusal stays in the reserved row. A viewer of the admin view mode opens and
// reads the window, while the confirm stands disabled. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core'
import {
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  createHilosI18nLanguageDelete,
} from '@hilos/core/admin/i18n'
import type {
  HilosI18nLanguageContext,
  HilosI18nLanguageRow,
} from '@hilos/core/admin/i18n'

import {
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/** What the confirmation reads of the language it deletes. */
export type HilosI18nLanguageDeleteSource = Pick<
  HilosI18nLanguageRow,
  'languageId' | 'code' | 'nativeName' | 'canDelete' | 'deleteReason'
>

/** The delete action of the page that opens the confirmation. */
type LanguageDeleteAction =
  | typeof HILOS_I18N_LANGUAGE_DELETE_ACTION
  | typeof HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION

/** The shared delete confirmation of a language row and of its card. */
@Component({
  selector: 'hilos-i18n-language-delete',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="open()"
      (openChange)="$event || closeWindow()"
      [title]="copy.title(target().name)"
      [busy]="action.busy()"
      initialFocus="dialog"
    >
      <div data-id="i18n-language-delete-window">
        <div data-id="i18n-language-delete-error">
          <hilos-action-error
            [action]="action"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <p>{{ copy.body }}</p>
        <div class="alert alert-secondary small py-2 mb-0">
          {{ copy.note }}
        </div>
        <hilos-edit-notice
          [kind]="elsewhere() ? 'deleted' : null"
          [text]="copy.elsewhere"
          dataId="i18n-language-delete-notice"
        />
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="i18n-language-delete-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.loading()"
          [disabled]="action.busy() || elsewhere()"
          data-id="i18n-language-delete-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLanguageDelete {
  /** Whether the page has the confirmation open. */
  readonly open = input.required<boolean>()
  /** The project context whose action lifecycle the deletion runs on. */
  readonly context = input.required<HilosI18nLanguageContext>()
  /** The live language the window follows, or null once it is gone. */
  readonly source = input.required<HilosI18nLanguageDeleteSource | null>()
  /** The delete action of the page that opens the window. */
  readonly actionName = input.required<LanguageDeleteAction>()
  /** The window asks to close: Cancel, Esc, the backdrop, or its own success. */
  readonly closed = output<void>()
  /** The language was deleted by this window's action. */
  readonly deleted = output<void>()

  protected readonly copy = HILOS_I18N_LANGUAGE_DELETE_COPY
  protected readonly action = createHilosTrackedAction()
  /** The ID, name and action fixed when the window opened. */
  protected readonly target = signal<{
    languageId: number | null
    name: string
    action: LanguageDeleteAction
  }>({
    languageId: null,
    name: '',
    action: HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  })
  protected readonly elsewhere = computed(
    () =>
      this.open() &&
      !this.action.busy() &&
      this.source()?.languageId !== this.target().languageId,
  )
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)

  constructor() {
    // Fix what the window is about at the moment it opens; the live source is
    // read from there only to tell that the language left.
    let wasOpen = false
    effect(() => {
      const open = this.open()
      untracked(() => {
        const source = this.source()
        if (open && !wasOpen && source !== null) {
          this.target.set({
            languageId: source.languageId,
            name: source.nativeName,
            action: this.actionName(),
          })
          this.action.clearError()
        }
      })
      wasOpen = open
    })
  }

  protected closeWindow(): void {
    if (!this.action.busy()) {
      this.closed.emit()
    }
  }

  protected async confirm(): Promise<void> {
    if (
      !this.open() ||
      this.action.busy() ||
      this.lookOnly.locked() ||
      this.elsewhere()
    ) {
      return
    }
    // The opening hint may have changed; the server's live verdict decides.
    const target = this.target()
    if (target.languageId === null) return
    const { deleteLanguage } = createHilosI18nLanguageDelete(
      this.context(),
      target.action,
    )
    if (
      (await this.action.run(deleteLanguage(target.languageId))) &&
      !this.destroyRef.destroyed
    ) {
      this.closed.emit()
      this.deleted.emit()
    }
  }
}
