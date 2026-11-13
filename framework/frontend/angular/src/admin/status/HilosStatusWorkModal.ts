// Creation has no live identity; editing delegates its merge to the core window.
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  formatMoment,
  hilosTimeZone,
  hilosToasts,
  type ActionError,
  type DateTimePickerController,
  type DateTimePickerSnapshot,
} from '@hilos/core/base'
import type {
  HilosStatusWorkCreate,
  HilosStatusWorkEdit,
  HilosStatusWorkForm,
  HilosStatusWorksData,
  HilosStatusWorksState,
} from '@hilos/core/admin/status'
import {
  ConflictActions,
  HilosDateTimePicker,
  HilosEditNotice,
  HilosFormError,
  HilosModal,
  LoadingButton,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

let workFormSequence = 0

/**
 * A new announcement or a captured live row in one form; the window is edited
 * in its DateTimePicker.
 */
@Component({
  selector: 'hilos-status-work-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ConflictActions,
    HilosDateTimePicker,
    HilosEditNotice,
    HilosFormError,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="opened()"
      [title]="title()"
      [busy]="busy()"
      [confirmOnClose]="dirty()"
      initialFocus="inner"
      (openChange)="controller().close()"
    >
      @if (opened()) {
        <form
          [id]="formId"
          [attr.aria-label]="title()"
          [attr.aria-describedby]="hintId"
          data-id="hilos-status-work-form"
          (submit)="onSubmit($event)"
          (keydown)="onEnter($event)"
        >
          <hilos-form-error
            [message]="error()"
            [errorType]="failure()?.errorType ?? null"
            [errorDetail]="failure()?.errorDetail ?? null"
            [detailsTitle]="title()"
            [copyText]="copyText()"
            dataId="hilos-status-work-error"
            [announce]="true"
          />
          <div class="mb-2">
            <label [for]="formId + '-title'" class="form-label">Title</label>
            <input
              [id]="formId + '-title'"
              [value]="form().title"
              class="form-control form-control-sm"
              data-id="hilos-status-work-title"
              data-autofocus
              (input)="patchText('title', $event)"
            />
          </div>
          <div #windowField class="mb-1">
            @if (windowPicker(); as picker) {
              <hilos-date-time-picker
                [id]="formId + '-window'"
                [picker]="picker"
                [label]="'Window, ' + timeZone()"
                [describedBy]="windowHintId"
                dataId="hilos-status-work-window"
              />
            }
          </div>
          <div [id]="windowHintId" class="form-text mt-0 mb-2">
            The window cannot overlap another announcement, even if the
            components differ. Simultaneous work is one announcement with
            several components. Adjacent windows are fine: one ends at 07:00,
            the next starts at 07:00.
          </div>
          <fieldset class="mb-2">
            <legend class="form-label fs-6 mb-1">Affects</legend>
            @for (
              component of data()?.components ?? [];
              track component.componentKey
            ) {
              <div class="form-check form-check-inline">
                <input
                  [id]="formId + '-component-' + component.componentKey"
                  [checked]="
                    form().componentKeys.includes(component.componentKey)
                  "
                  type="checkbox"
                  class="form-check-input"
                  [attr.data-id]="
                    'hilos-status-work-component-' + component.componentKey
                  "
                  (change)="toggleComponent(component.componentKey, $event)"
                />
                <label
                  [for]="formId + '-component-' + component.componentKey"
                  class="form-check-label"
                  >{{ component.name }}</label
                >
              </div>
            }
          </fieldset>
          <div class="mb-2">
            <label [for]="formId + '-description'" class="form-label"
              >What visitors read</label
            >
            <textarea
              [id]="formId + '-description'"
              [value]="form().description"
              class="form-control form-control-sm"
              rows="2"
              data-id="hilos-status-work-description"
              (input)="patchText('description', $event)"
            ></textarea>
          </div>
          <p [id]="hintId" class="small text-body-secondary mb-0">
            This is an advance announcement. It does not close the service; the
            freeze in Maintenance starts the actual work.
          </p>
          <hilos-edit-notice
            [kind]="noticeKind()"
            [text]="noticeText()"
            dataId="hilos-status-work-notice"
          />
        </form>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <div
          hilosConflictActions
          [conflict]="editState()?.conflict ?? false"
          [disableSave]="!busy() && !saveAvailable()"
          (save)="submit()"
          (acceptMine)="editor()?.keepMine()"
          (acceptTheirs)="editor()?.takeTheirs()"
        >
          <ng-template #cancelButton>
            <button
              type="button"
              class="btn btn-secondary"
              [disabled]="busy()"
              data-id="hilos-status-work-close"
              (click)="requestClose()"
            >
              Cancel
            </button>
          </ng-template>
          <ng-template #saveButton let-disabled="disabled">
            <button
              hilosLoadingButton
              [attr.form]="formId"
              type="submit"
              class="btn-primary"
              [loading]="busy()"
              [disabled]="disabled"
              data-id="hilos-status-work-save"
            >
              {{ busy() && editor() ? 'Save' : saveLabel() }}
            </button>
          </ng-template>
        </div>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosStatusWorkModal {
  /** A page-owned core creation or row-edit window. */
  readonly controller = input.required<
    HilosStatusWorkCreate | HilosStatusWorkEdit
  >()
  /** The component catalog from the complete page answer. */
  readonly state = input.required<HilosStatusWorksState>()

  protected readonly formId = `hilos-status-work-form-${workFormSequence++}`
  protected readonly hintId = `${this.formId}-hint`
  protected readonly windowHintId = `${this.formId}-window-hint`
  protected readonly opened = signal(false)
  protected readonly form = signal<HilosStatusWorkForm>({
    title: '',
    description: '',
    componentKeys: [],
    startsAt: '',
    endsAt: '',
  })
  protected readonly dirty = signal(false)
  protected readonly busy = signal(false)
  protected readonly canSubmit = signal(false)
  protected readonly saveAvailable = signal(false)
  protected readonly saveLabel = signal('Schedule maintenance')
  protected readonly timeZone = signal('UTC')
  protected readonly data = signal<HilosStatusWorksData>(null)
  protected readonly error = signal<string | null>(null)
  protected readonly failure = signal<ActionError | null>(null)
  protected readonly blockedReason = signal<string | null>(null)
  protected readonly editState = signal<ReturnType<
    HilosStatusWorkEdit['state']['get']
  > | null>(null)
  protected readonly windowPicker = signal<DateTimePickerController<
    'datetime',
    'range'
  > | null>(null)
  private readonly windowState = signal<DateTimePickerSnapshot<
    'datetime',
    'range'
  > | null>(null)
  private readonly windowField =
    viewChild<ElementRef<HTMLElement>>('windowField')
  private readonly editNotice = signal('')
  private readonly readerTimeZone = hilosSignal(hilosTimeZone)
  protected readonly editor = computed(() => {
    const controller = this.controller()
    return 'state' in controller ? controller : null
  })
  protected readonly title = computed(() =>
    this.editor() ? 'Change scheduled maintenance' : 'Schedule maintenance',
  )
  protected readonly noticeKind = computed(() =>
    this.blockedReason() ? 'deleted' : (this.editState()?.notice?.kind ?? null),
  )
  protected readonly copyText = computed(() => {
    const failure = this.failure()
    return failure?.errorDetail
      ? [failure.errorType, failure.errorDetail].filter(Boolean).join('\n')
      : (this.error() ?? '')
  })
  protected readonly noticeText = computed(() => {
    const blocked = this.blockedReason()
    if (blocked) return blocked
    const edit = this.editState()
    if (!edit?.conflict) return this.editNotice()
    const labels: Record<keyof HilosStatusWorkForm, string> = {
      title: 'Title',
      description: 'What visitors read',
      componentKeys: 'Affects',
      startsAt: 'Starts',
      endsAt: 'Ends',
    }
    return [
      this.editNotice(),
      ...(edit.notice?.fields ?? []).map((field) => {
        const incoming = edit.fields[field].incoming
        const momentField = field === 'startsAt' || field === 'endsAt'
        const theirs = momentField
          ? incoming === null
            ? '—'
            : `${formatMoment(incoming as number) ?? '—'} (${this.readerTimeZone()})`
          : field === 'componentKeys'
            ? this.componentNames(JSON.parse(incoming as string) as string[])
            : (incoming ?? '')
        const mine =
          field === 'componentKeys'
            ? this.componentNames(this.form().componentKeys)
            : momentField
              ? this.windowText(field === 'startsAt' ? 'start' : 'end')
              : this.form()[field]
        return `${labels[field]}: Keep mine: ${mine}; Take theirs: ${theirs}`
      }),
    ].join('\n')
  })

  constructor() {
    effect((onCleanup) => {
      const controller = this.controller()
      const editor = this.editor()
      const creator = 'submitAvailable' in controller ? controller : null
      const stops = [
        mirrorHilosSignal(controller.opened, this.opened),
        mirrorHilosSignal(controller.form, this.form),
        mirrorHilosSignal(controller.dirty, this.dirty),
        mirrorHilosSignal(controller.timeZone, this.timeZone),
        mirrorHilosSignal(controller.windowPicker, this.windowPicker),
        mirrorHilosSignal(
          { get: () => controller.windowPicker.get()?.state.get() ?? null },
          this.windowState,
        ),
        mirrorHilosSignal(editor?.saving ?? controller.busy, this.busy),
        mirrorHilosSignal(controller.error, this.error),
        mirrorHilosSignal(controller.failure, this.failure),
        mirrorHilosSignal(controller.blockedReason, this.blockedReason),
        mirrorHilosSignal(this.state().data, this.data),
        mirrorHilosSignal(
          { get: () => editor?.state.get() ?? null },
          this.editState,
        ),
        mirrorHilosSignal(
          { get: () => editor?.noticeText.get() ?? '' },
          this.editNotice,
        ),
        mirrorHilosSignal(
          {
            get: () =>
              editor?.saveAvailable.get() ?? creator!.submitAvailable.get(),
          },
          this.saveAvailable,
        ),
        mirrorHilosSignal(
          { get: () => editor?.canSave.get() ?? creator!.canSubmit.get() },
          this.canSubmit,
        ),
        mirrorHilosSignal(
          { get: () => editor?.saveLabel.get() ?? 'Schedule maintenance' },
          this.saveLabel,
        ),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
      })
    })
  }

  /**
   * The draft of one window edge as its parts show it, in the captured zone.
   *
   * @param edge The edge a conflicting moment field names.
   */
  private windowText(edge: 'start' | 'end'): string {
    const parts = this.windowState()?.[edge]?.parts ?? []
    const shown = parts.every((part) => part.text === '')
      ? '—'
      : parts
          .map((part) => part.prefix + (part.text || part.placeholder))
          .join('')
    return `${shown} (${this.timeZone()})`
  }

  private componentNames(keys: readonly string[]): string {
    return keys
      .map(
        (key) =>
          this.data()?.components.find((item) => item.componentKey === key)
            ?.name ?? key,
      )
      .join(', ')
  }

  /** Text fields only; the window is edited in its picker. */
  protected patchText(field: 'title' | 'description', event: Event): void {
    this.controller().patchForm({
      [field]: (event.target as HTMLInputElement).value,
    })
  }

  protected toggleComponent(key: string, event: Event): void {
    this.controller().patchForm({
      componentKeys: (event.target as HTMLInputElement).checked
        ? [...this.form().componentKeys, key]
        : this.form().componentKeys.filter((value) => value !== key),
    })
  }

  protected onSubmit(event: Event): void {
    event.preventDefault()
    void this.submit()
  }

  /**
   * Enter inside the window field while its calendar is open belongs to the
   * calendar; elsewhere Enter follows the Save verdict; buttons activate
   * themselves.
   *
   * @param event The keydown that reached the form.
   */
  protected onEnter(event: Event): void {
    const key = event as KeyboardEvent
    if (key.key !== 'Enter') return
    if (
      key.target instanceof Element &&
      key.target.closest('textarea, button, a')
    )
      return
    key.preventDefault()
    if (
      this.controller().windowPicker.get()?.calendar.state.get().open &&
      key.target instanceof Node &&
      this.windowField()?.nativeElement.contains(key.target)
    )
      return
    void this.submit()
  }

  /** Only this opening's correlated owner acknowledgement reports success. */
  protected async submit(): Promise<void> {
    if (!this.canSubmit()) return
    const controller = this.controller()
    if (!(await controller.submit())) return
    const message = controller.success.get()
    if (message !== null) hilosToasts.push(message, { severity: 'success' })
  }
}
