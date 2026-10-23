// HilosI18nLocaleDelete — the delete confirmation of one locale of the language,
// opened from its row in the locales table. The page fixes the pair's codes and
// labels at the moment it opens, and hands the live pair after that: a locale
// gone while the window stands idle is said in the notice row and blocks the
// confirm, and the window stays open until it is closed by hand. Its own
// deletion is different: the echo that takes the locale away while the action
// is in flight says nothing, the window keeps its target and its action, and
// only `::success` closes it — the pair's row stays in the table, now without a
// locale. Whether the locale may go is the server's alone: it refuses an enabled
// locale, a country's chosen default and a locale any name override still uses,
// empty ones included, and its refusal stays in the reserved row. A viewer of
// the admin view mode opens and reads the window, while the confirm stands
// disabled. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
} from '@angular/core'
import { HILOS_I18N_LOCALE_DELETE_COPY } from '@hilos/core/admin/i18n'
import type { ActionHandle } from '@hilos/core/base'
import type { HilosI18nLocaleDeleteTarget } from '@hilos/core/admin/i18n'

import {
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/** The delete confirmation of one locale. */
@Component({
  selector: 'hilos-i18n-locale-delete',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosEditNotice, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="open()"
      (openChange)="$event || closeWindow()"
      [title]="
        copy.title(target()?.nativeName ?? '', target()?.countryLabel ?? null)
      "
      [busy]="action.busy()"
      initialFocus="dialog"
    >
      <div data-id="i18n-locale-delete-window">
        <div data-id="i18n-locale-delete-error">
          <hilos-action-error
            [action]="action"
            [detailsTitle]="copy.refusalTitle"
          />
        </div>
        <p>
          {{
            copy.body(
              target()?.languageCode ?? '',
              target()?.countryCode ?? null
            )
          }}
        </p>
        <div class="alert alert-warning small py-2 mb-0">{{ copy.note }}</div>
        <hilos-edit-notice
          [kind]="elsewhere() ? 'deleted' : null"
          [text]="copy.elsewhere"
          dataId="i18n-locale-delete-notice"
        />
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="i18n-locale-delete-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.busy()"
          [disabled]="elsewhere()"
          data-id="i18n-locale-delete-confirm"
          (click)="confirm()"
        >
          {{ copy.confirm }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosI18nLocaleDelete {
  /** Whether the page has the confirmation open. */
  readonly open = input.required<boolean>()
  /** The pair's codes and labels, fixed by the page when it opened the window. */
  readonly target = input.required<HilosI18nLocaleDeleteTarget | null>()
  /** The live locale and language, or null once either is gone. */
  readonly source = input.required<HilosI18nLocaleDeleteTarget | null>()
  /** The page's tracked deletion dispatch (createHilosI18nLocaleDelete). */
  readonly deleteLocale =
    input.required<
      (
        languageId: number,
        countryId: number | null,
        localeId: number,
      ) => ActionHandle
    >()
  /** The window asks to close: Cancel, Esc, the backdrop, or its own success. */
  readonly closed = output<void>()

  protected readonly copy = HILOS_I18N_LOCALE_DELETE_COPY
  protected readonly action = createHilosTrackedAction()
  protected readonly elsewhere = computed(() => {
    const target = this.target()
    const source = this.source()

    return (
      this.open() &&
      !this.action.busy() &&
      (source === null ||
        source.languageId !== target?.languageId ||
        source.countryId !== target?.countryId ||
        source.localeId !== target?.localeId)
    )
  })
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)

  constructor() {
    // An opening starts without the refusal of the previous visit.
    effect(() => {
      if (this.open()) this.action.clearError()
    })
  }

  protected closeWindow(): void {
    if (!this.action.busy()) {
      this.closed.emit()
    }
  }

  protected async confirm(): Promise<void> {
    const target = this.target()
    if (
      !this.open() ||
      target === null ||
      this.action.busy() ||
      this.lookOnly.locked() ||
      this.elsewhere()
    ) {
      return
    }
    if (
      (await this.action.run(
        this.deleteLocale()(
          target.languageId,
          target?.countryId,
          target?.localeId,
        ),
      )) &&
      !this.destroyRef.destroyed
    ) {
      this.closed.emit()
    }
  }
}
