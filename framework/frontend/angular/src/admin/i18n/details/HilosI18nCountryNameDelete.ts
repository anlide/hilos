// HilosI18nCountryNameDelete — the delete confirmation of a country's name in a
// language: the base name, which takes its locale corrections with it, or one
// correction, whose locale then inherits the base again. Where the built-in
// catalog knows the country's name in that language, deleting the base brings
// the catalog's name back at once, unlocked. It fixes the codes, the labels and
// the literal value it removes at the moment it opens — the server deletes only
// that value — and follows the live row after that: a name gone while the
// window stands idle is said in the notice row and blocks the confirm, and the
// window stays open until it is closed by hand. Its own deletion is different:
// the echo that takes the name away while the action is in flight says
// nothing, the window keeps its target and its action, and only `::success`
// closes it. An empty value is a saved one and is said so. The page reads
// whether the action is in flight, so the edit window does not take the row's
// focus from under it. A viewer of the admin view mode opens and reads the
// window, while the confirm stands disabled. Bootstrap classes only
// (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
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
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  createHilosI18nCountryNameDelete,
} from '@hilos/core/admin/i18n'
import type {
  HilosI18nCountryContext,
  HilosI18nCountryNameDeleteTarget,
  HilosI18nNameRow,
} from '@hilos/core/admin/i18n'

import {
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  injectLookOnly,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/** The delete confirmation of a base name or of one locale correction. */
@Component({
  selector: 'hilos-i18n-country-name-delete',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosEditNotice,
    HilosModal,
    LoadingButton,
    NgTemplateOutlet,
  ],
  template: `
    <hilos-modal
      [open]="open()"
      (openChange)="$event || closeWindow()"
      [title]="title()"
      [busy]="action.busy()"
      initialFocus="dialog"
    >
      @if (opened(); as held) {
        <div data-id="i18n-country-name-delete-window">
          <div data-id="i18n-country-name-delete-error">
            <hilos-action-error
              [action]="action"
              [detailsTitle]="copy.refusalTitle"
            />
          </div>
          <p>
            <span
              >{{ held.countryName }} ({{ held.countryCode.toUpperCase() }}) ·
            </span>
            <span
              >{{ held.inLanguageNativeName }} ({{ held.inLanguageCode }})</span
            >
            @if (held.kind === 'override') {
              <span> · {{ held.localeCode }}</span>
            }
          </p>
          <p>{{ held.kind === 'base' ? copy.baseBody : copy.overrideBody }}</p>
          <div class="py-2 border-top border-bottom my-2 small text-break">
            <div>
              <span class="text-body-secondary me-1">{{ copy.current }}</span>
              <ng-container
                [ngTemplateOutlet]="value"
                [ngTemplateOutletContext]="{ $implicit: held.expectedName }"
              />
            </div>
            @if (held.kind === 'override') {
              <div>
                <span class="text-body-secondary me-1">{{
                  copy.inherited
                }}</span>
                @if (source()?.name === '') {
                  <ng-container
                    [ngTemplateOutlet]="value"
                    [ngTemplateOutletContext]="{ $implicit: '' }"
                  />
                } @else {
                  <span class="fw-semibold">{{
                    source()?.name ?? copy.missing
                  }}</span>
                }
              </div>
            }
          </div>
          <div
            [class]="
              'alert small py-2 mb-0 ' +
              (held.kind === 'base' ? 'alert-warning' : 'alert-secondary')
            "
          >
            @if (held.kind === 'base') {
              <p>{{ copy.cascade }}</p>
              <p class="mb-0">{{ copy.catalog }}</p>
            } @else {
              {{ copy.overrideNote }}
            }
          </div>
          <hilos-edit-notice
            [kind]="missing() && !action.busy() ? 'deleted' : null"
            [text]="copy.missing"
            dataId="i18n-country-name-delete-notice"
          />
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="i18n-country-name-delete-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.busy()"
          [disabled]="missing()"
          data-id="i18n-country-name-delete-confirm"
          (click)="confirm()"
        >
          {{ opened()?.kind === 'base' ? copy.delete : copy.remove }}
        </button>
      </ng-template>
    </hilos-modal>
    <ng-template #value let-name>
      @if (name === '') {
        <span class="fw-semibold"
          >{{ copy.empty
          }}<span class="visually-hidden"
            >. {{ copy.emptyDescription }}</span
          ></span
        >
      } @else {
        <span class="fw-semibold">{{ name }}</span>
      }
    </ng-template>
  `,
})
export class HilosI18nCountryNameDelete {
  /** Whether the page has the confirmation open. */
  readonly open = input.required<boolean>()
  /** The project context whose action lifecycle the deletion runs on. */
  readonly context = input.required<HilosI18nCountryContext>()
  /** The coordinates and value the page took from the row it holds in focus. */
  readonly target = input.required<HilosI18nCountryNameDeleteTarget | null>()
  /** The live row the window follows, or null once it is gone. */
  readonly source = input.required<HilosI18nNameRow | null>()
  /** The window asks to close: Cancel, Esc, the backdrop, or its own success. */
  readonly closed = output<void>()

  protected readonly copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY
  protected readonly action = createHilosTrackedAction()
  /** Whether this window's deletion is in flight. */
  readonly busy = computed(() => this.action.busy())
  /** What the window is about, fixed when it opened. */
  protected readonly opened = signal<HilosI18nCountryNameDeleteTarget | null>(
    null,
  )
  protected readonly title = computed(() => {
    const held = this.opened()

    return held === null ? '' : this.copy.title(held)
  })
  protected readonly missing = computed(() => {
    const held = this.opened()
    const row = this.source()
    if (
      held === null ||
      row?.inLanguageId !== held.inLanguageId ||
      row.name === null
    )
      return true

    return (
      (held.kind === 'base' && row.nameId !== held.nameId) ||
      (held.kind === 'override' &&
        row.corrections.find((item) => item.localeId === held.localeId)
          ?.nameId !== held.nameId)
    )
  })
  private readonly lookOnly = injectLookOnly()
  private readonly destroyRef = inject(DestroyRef)

  constructor() {
    // Fix what the window is about at the moment it opens; the live row is read
    // from there only to tell that the name left.
    let wasOpen = false
    effect(() => {
      const open = this.open()
      untracked(() => {
        const target = this.target()
        if (open && !wasOpen && target !== null) {
          this.opened.set({ ...target })
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
    const target = this.opened()
    if (
      !this.open() ||
      target === null ||
      this.action.busy() ||
      this.lookOnly.locked() ||
      this.missing()
    ) {
      return
    }
    const { deleteName } = createHilosI18nCountryNameDelete(this.context())
    // A route change may have closed this confirmation while its reply was in
    // flight; only the opening that sent it closes on its success.
    if (
      (await this.action.run(deleteName(target))) &&
      !this.destroyRef.destroyed &&
      this.opened() === target
    ) {
      this.closed.emit()
    }
  }
}
