import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import { DomSanitizer } from '@angular/platform-browser'
import {
  HilosPages,
  bindPageInstanceName,
  computedSignal,
  hilosRowEditIdle,
} from '@hilos/core/base'
import {
  createHilosChannelFrame,
  createHilosChannelFrameEdit,
  createHilosChannelSummary,
  createHilosCommunicationsActions,
  LETTER_FRAME_FIELD,
  type HilosChannelFrameEditFields,
  type HilosChannelFrameEditForm,
  type HilosChannelSummary,
  type HilosCommunicationsContext,
  type HilosLetterFrame,
} from '@hilos/core/admin/communications'
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosAdminPage,
  HilosEditNotice,
  HilosHiddenMark,
  HilosHideable,
  HilosModal,
  LoadingButton,
  createHilosTrackedAction,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosCommunicationsChannelHead } from './HilosCommunicationsChannelHead.js'

/** Server-rendered email preview over the shared core edit session. */
@Component({
  selector: 'hilos-communications-channel-frame-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosCommunicationsChannelHead,
    HilosModal,
    HilosActionError,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
    LoadingButton,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="entity" tabsLabel="Channel pages">
      <ng-template #headVisual
        ><hilos-communications-channel-head [summary]="summary()" part="visual"
      /></ng-template>
      <ng-template #headBadges
        ><hilos-communications-channel-head [summary]="summary()" part="badges"
      /></ng-template>
      <ng-template #headDescription
        ><hilos-communications-channel-head
          [summary]="summary()"
          part="description"
      /></ng-template>
      @if (frame(); as current) {
        <section class="card" data-id="hilos-channel-frame">
          <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1">
              <h2 class="h6 mb-1">Letter frame</h2>
              <div class="small text-body-secondary">
                Header, footer and styles around every letter
              </div>
            </div>
            <span
              class="badge text-bg-secondary-subtle text-secondary-emphasis"
              data-id="hilos-channel-frame-source"
              >{{ sourceLabel() }}</span
            >
            <div class="d-flex gap-1">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                title="Edit frame"
                aria-label="Edit frame"
                data-id="hilos-channel-frame-edit"
                (click)="openEdit()"
              >
                <i class="bi bi-pencil" aria-hidden="true"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                title="Reset frame"
                aria-label="Reset frame"
                [disabled]="resetGone()"
                data-id="hilos-channel-frame-reset"
                (click)="openReset()"
              >
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="rounded-3 p-3 p-sm-4 bg-body-tertiary">
              @if (preview(); as html) {
                <iframe
                  class="w-100 border rounded-3 bg-body"
                  sandbox=""
                  title="Letter frame preview"
                  [srcdoc]="html"
                  data-id="hilos-channel-frame-preview"
                ></iframe>
              } @else {
                <hilos-hidden-mark />
              }
            </div>
            <div class="small text-body-secondary mt-2">
              Preview with <code>auth.password_reset</code> in English. Plain
              text goes without the frame.
            </div>
          </div>
        </section>
      }
      <hilos-modal
        [open]="editOpen()"
        (openChange)="$event ? undefined : closeEdit()"
        size="wide"
        aria-label="Edit · Letter frame"
        [copyText]="form().html"
        [confirmOnClose]="live().dirty"
        [busy]="saving()"
      >
        <h5
          hilosConflictHeader
          modalHeader
          title="Edit · Letter frame"
          [conflict]="live().conflict"
        ></h5>
        <hilos-action-error
          [action]="edit"
          detailsTitle="Couldn't save the frame"
        />
        <form (submit)="submitEdit($event)">
          <label class="form-label" for="hilos-channel-frame-html"
            >Frame HTML</label
          >
          @if (hidden()) {
            <hilos-hidden-mark />
          } @else {
            <textarea
              id="hilos-channel-frame-html"
              class="form-control font-monospace small"
              rows="14"
              [disabled]="saving()"
              data-id="hilos-channel-frame-html"
              data-autofocus
              [value]="form().html"
              (input)="patchHtml($event)"
            ></textarea>
          }
          <hilos-edit-notice
            [kind]="live().notice?.kind ?? null"
            [text]="notice()"
            dataId="hilos-channel-frame-notice"
          />
          @if (live().conflict) {
            <div class="mt-2" data-id="hilos-channel-frame-incoming">
              <div class="small text-body-secondary">Changed elsewhere</div>
              <hilos-hideable [value]="live().fields.html.incoming"
                ><ng-template let-value>
                  <pre class="small text-wrap text-break">{{ value }}</pre>
                </ng-template></hilos-hideable
              >
            </div>
          }
          <div class="small mt-2">
            <div class="text-body-secondary mb-1">Placeholders</div>
            <ul class="list-unstyled mb-0">
              <li>
                <code>[[:content]]</code> — the letter itself — required, once
              </li>
              <li><code>[[:lang]]</code> — reader's language</li>
              <li>
                <code>[[:dir]]</code> — ltr or rtl, by the reader's language
              </li>
            </ul>
            <div class="form-text">
              Any other name, or no <code>[[:content]]</code>, is refused on
              save. The frame carries no words of its own: every sentence
              belongs to a template.
            </div>
          </div>
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!canSave()"
            [saveLabel]="saveLabel()"
            (save)="submitEdit()"
            (acceptMine)="editor().keepMine()"
            (acceptTheirs)="editor().takeTheirs()"
          >
            <ng-template #cancelButton
              ><button
                type="button"
                class="btn btn-secondary"
                [disabled]="saving()"
                data-id="hilos-channel-frame-edit-cancel"
                (click)="requestClose()"
              >
                Cancel
              </button></ng-template
            >
            <ng-template #saveButton let-disabled="disabled" let-onSave="onSave"
              ><button
                hilosLoadingButton
                class="btn-primary"
                [loading]="edit.busy()"
                [disabled]="disabled"
                data-id="hilos-channel-frame-save"
                (click)="onSave()"
              >
                {{ saveLabel() }}
              </button></ng-template
            >
          </div>
        </ng-template>
      </hilos-modal>
      <hilos-modal
        [open]="resetOpen()"
        (openChange)="resetOpen.set($event)"
        title="Reset · Letter frame"
        [busy]="reset.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error
          [action]="reset"
          detailsTitle="Couldn't reset the frame"
        />
        <dl class="row mb-0">
          <dt class="col-4">Now</dt>
          <dd class="col-8 text-break" data-id="hilos-channel-frame-reset-now">
            <span
              class="badge text-bg-secondary-subtle text-secondary-emphasis"
              >{{ sourceLabel() }}</span
            >
          </dd>
          <dt class="col-4">Back to</dt>
          <dd class="col-8 mb-0" data-id="hilos-channel-frame-reset-back-to">
            the project frame, or the framework frame when the project has none
          </dd>
        </dl>
        <hilos-edit-notice
          [kind]="resetGone() ? 'updated' : null"
          text="Already reset elsewhere."
          dataId="hilos-channel-frame-reset-state"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="reset.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="reset.busy()"
            [disabled]="resetGone()"
            data-id="hilos-channel-frame-reset-confirm"
            (click)="submitReset()"
          >
            Reset
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsChannelFramePage {
  /** The project's connection, page scopes and tracked action lifecycle. */
  readonly context = input.required<HilosCommunicationsContext>()
  protected readonly page = HilosPages.COMMUNICATIONS_CHANNEL_FRAME
  protected readonly summary = signal<HilosChannelSummary | null>(null)
  protected readonly frame = signal<HilosLetterFrame | undefined>(undefined)
  private readonly sanitizer = inject(DomSanitizer)
  private readonly source = computed(() =>
    createHilosChannelFrame(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosCommunicationsActions(this.context()),
  )
  protected readonly editor = computed(() =>
    createHilosChannelFrameEdit(this.source(), this.actions()),
  )
  protected readonly edit = createHilosTrackedAction()
  protected readonly reset = createHilosTrackedAction()
  protected readonly editOpen = signal(false)
  protected readonly resetOpen = signal(false)
  protected readonly form = signal<HilosChannelFrameEditForm>({ html: '' })
  protected readonly live = signal(
    hilosRowEditIdle<HilosChannelFrameEditFields>({ html: '' }),
  )
  protected readonly notice = signal('')
  protected readonly saveLabel = signal('Save')
  protected readonly canSave = signal(false)
  protected readonly saving = signal(false)
  protected readonly hidden = computed(() => {
    const frame = this.frame()
    return frame !== undefined && typeof frame.html !== 'string'
  })
  protected readonly sourceLabel = computed(() => {
    const frame = this.frame()
    return frame
      ? { settings: 'Override', project: 'Project', default: 'Default' }[
          frame.source
        ]
      : ''
  })
  protected readonly resetGone = computed(
    () => this.frame()?.source !== 'settings',
  )
  protected readonly preview = computed(() => {
    const html = this.frame()?.previewHtml
    // Only the server echo enters this separate document. Its static, empty
    // sandbox grants no script, origin, form or navigation permissions.
    return typeof html === 'string'
      ? this.sanitizer.bypassSecurityTrustHtml(html)
      : undefined
  })

  constructor() {
    effect((onCleanup) => {
      const context = this.context()
      const source = this.source()
      const editor = this.editor()
      const channelSummary = createHilosChannelSummary(context)
      const currentSummary = computedSignal(() =>
        context.scopes.page()?.key === this.page ? channelSummary.get() : null,
      )
      const unbindName = bindPageInstanceName(
        context.scopes,
        HilosPages.COMMUNICATIONS_CHANNEL,
        computedSignal(() => currentSummary.get()?.label),
      )
      editor.start()
      const off = [
        mirrorHilosSignal(currentSummary, this.summary),
        mirrorHilosSignal(source, this.frame),
        mirrorHilosSignal(editor.opened, this.editOpen),
        mirrorHilosSignal(editor.form, this.form),
        mirrorHilosSignal(editor.state, this.live),
        mirrorHilosSignal(editor.noticeText, this.notice),
        mirrorHilosSignal(editor.saveLabel, this.saveLabel),
        mirrorHilosSignal(editor.saveAvailable, this.canSave),
        mirrorHilosSignal(editor.saving, this.saving),
      ]
      onCleanup(() => {
        unbindName()
        for (const stop of off) stop()
        editor.dispose()
      })
    })
  }

  protected openEdit(): void {
    this.edit.clearError()
    this.editor().open()
  }
  protected closeEdit(): void {
    this.editor().close()
  }
  protected patchHtml(event: Event): void {
    this.editor().patchForm({
      html: (event.target as HTMLTextAreaElement).value,
    })
  }
  protected submitEdit(event?: Event): void {
    event?.preventDefault()
    void this.editor().save(this.edit.run)
  }
  protected openReset(): void {
    this.reset.clearError()
    this.resetOpen.set(true)
  }
  protected async submitReset(): Promise<void> {
    if (this.reset.busy() || this.resetGone()) return
    if (
      await this.reset.run(
        this.actions().sendChannelReset('email', LETTER_FRAME_FIELD),
      )
    )
      this.resetOpen.set(false)
  }
}
