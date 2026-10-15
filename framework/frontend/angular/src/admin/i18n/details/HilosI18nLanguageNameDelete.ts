// HilosI18nLanguageNameDelete — the delete confirmation of a language's name in
// another language: the base name, which takes its locale corrections with it,
// or one correction, whose locale then inherits the base again. It fixes the
// codes, the labels and the literal value it removes at the moment it opens —
// the server deletes only that value — and follows the live row after that: a
// name gone while the window stands idle is said in the notice row and blocks
// the confirm, and the window stays open until it is closed by hand. Its own
// deletion is different: the echo that takes the name away while the action is
// in flight says nothing, the window keeps its target and its action, and only
// `::success` closes it. An empty value is a saved one and is said so. A viewer
// of the admin view mode opens and reads the window, while the confirm stands
// disabled. Bootstrap classes only (styling-rules.md).
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
  HILOS_I18N_LANGUAGE_NAME_DELETE_COPY,
  createHilosI18nLanguageNameDelete,
} from '@hilos/core'
import type {
  HilosI18nLanguageContext,
  HilosI18nLanguageNameDeleteTarget,
  HilosI18nNameRow,
} from '@hilos/core'

import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { injectLookOnly } from '../../../hilosLookOnly.js'
import { createHilosTrackedAction } from '../../../hilosTrackedAction.js'

/** The delete confirmation of a base name or of one locale correction. */
@Component({
  selector: 'hilos-i18n-language-name-delete',
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
      [closeOnBackdrop]="!action.busy()"
      [closeOnEsc]="!action.busy()"
      [closeDisabled]="action.busy()"
      initialFocus="dialog"
    >
      @if (opened(); as held) {
        <div data-id="i18n-language-name-delete-window">
          <div data-id="i18n-language-name-delete-error">
            <hilos-action-error
              [action]="action"
              [detailsTitle]="copy.refusalTitle"
            />
          </div>
          <p>
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
            @if (held.kind === 'base') {
              <div>
                <span class="text-body-secondary me-1">{{
                  copy.fallback
                }}</span>
                <span class="fw-semibold">{{ held.languageNativeName }}</span>
              </div>
            } @else {
              <div>
                <span class="text-body-secondary me-1">{{
                  copy.inherited
                }}</span>
                @if (source()?.name; as base) {
                  <span class="fw-semibold">{{ base }}</span>
                } @else if (source()?.name === '') {
                  <ng-container
                    [ngTemplateOutlet]="value"
                    [ngTemplateOutletContext]="{ $implicit: '' }"
                  />
                } @else {
                  <span class="fw-semibold">{{ copy.missing }}</span>
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
            {{ held.kind === 'base' ? copy.cascade : copy.overrideNote }}
          </div>
          <hilos-edit-notice
            [kind]="missing() && !action.busy() ? 'deleted' : null"
            [text]="copy.missing"
            dataId="i18n-language-name-delete-notice"
          />
        </div>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="action.busy()"
          data-id="i18n-language-name-delete-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          class="btn-danger"
          [loading]="action.loading()"
          [disabled]="action.busy() || missing()"
          data-id="i18n-language-name-delete-confirm"
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
export class HilosI18nLanguageNameDelete {
  /** Whether the page has the confirmation open. */
  readonly open = input.required<boolean>()
  /** The project context whose action lifecycle the deletion runs on. */
  readonly context = input.required<HilosI18nLanguageContext>()
  /** The coordinates and value the page took from the row it holds in focus. */
  readonly target = input.required<HilosI18nLanguageNameDeleteTarget | null>()
  /** The live row the window follows, or null once it is gone. */
  readonly source = input.required<HilosI18nNameRow | null>()
  /** The window asks to close: Cancel, Esc, the backdrop, or its own success. */
  readonly closed = output<void>()

  protected readonly copy = HILOS_I18N_LANGUAGE_NAME_DELETE_COPY
  protected readonly action = createHilosTrackedAction()
  /** What the window is about, fixed when it opened. */
  protected readonly opened = signal<HilosI18nLanguageNameDeleteTarget | null>(
    null,
  )
  protected readonly title = computed(() => {
    const held = this.opened()

    return held === null ? '' : this.copy.title(held)
  })
  protected readonly missing = computed(() => {
    const held = this.opened()
    const row = this.source()
    if (held === null || row?.code !== held.inLanguageCode || row.name === null)
      return true

    return (
      held.kind === 'override' &&
      row.corrections.find((item) => item.localeCode === held.localeCode)
        ?.name == null
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
    const { deleteName } = createHilosI18nLanguageNameDelete(this.context())
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
