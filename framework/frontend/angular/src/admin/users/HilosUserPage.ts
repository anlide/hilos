// HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
// user's profile, presence, and rename, inside the admin shell. Editing happens
// in a modal — inline forms are forbidden (rules-and-violations.md section E,
// conflict-resolution.md); the modal hosts the rename form. The detail selector
// and the rename action are the core headless's (createHilosUserDetail /
// createHilosUserRename); this view owns only the markup, so a project mounts it
// by passing its HilosUsersContext. The modal merges against the live row
// through the shared row-edit helper (rowEdit.ts, conflict-resolution.md) and
// says what happened elsewhere on one line of room held in advance
// (HilosEditNotice). Success is state-driven (the committed name reaches the
// draft over the live table, closing the modal); a failure surfaces from the
// backend fail ack inside the modal. The context arrives via input and
// carries core signals, so — like HilosTable — an effect builds the selectors
// once it binds and mirrors them into Angular signals. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  untracked,
} from '@angular/core'
import {
  HilosPages,
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  createHilosUserRename,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  sessionUserId,
  subscribeSignal,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosAccountMerge,
  HilosMergeCandidateIdentity,
  HilosMergeCandidateRow,
  HilosMergeCandidates,
  HilosPasswordFate,
  HilosUserDetailRow,
  HilosUserRename,
  HilosUsersContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The one field the modal edits: the display name. */
interface UserEditFields {
  name: string
}

/** The one line the modal says about the other side, for what the helper found. */
function noticeText(live: RowEditState<UserEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return `Changed elsewhere to "${live.fields.name.incoming}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

/** The framework user-detail admin page: profile, presence, and a modal rename. */
@Component({
  selector: 'hilos-user-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosFormError,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @if (detail(); as detail) {
        <div class="card" data-id="hilos-user-detail">
          <div class="card-header d-flex align-items-center gap-2">
            <span
              class="rounded-circle flex-shrink-0"
              [class]="
                detail.presence === 'online' ? 'bg-success' : 'bg-secondary'
              "
              style="width: 10px; height: 10px"
              aria-hidden="true"
            ></span>
            <span class="h5 mb-0" data-id="hilos-user-name">{{
              detail.name
            }}</span>
            <span class="badge text-bg-secondary">{{ detail.presence }}</span>
            <button
              type="button"
              class="btn btn-outline-primary btn-sm ms-auto"
              data-id="hilos-user-edit"
              (click)="openEdit()"
            >
              Edit
            </button>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-3">User ID</dt>
              <dd class="col-sm-9" data-id="hilos-user-id">{{ detail.id }}</dd>
              <dt class="col-sm-3">Online sessions</dt>
              <dd class="col-sm-9" data-id="hilos-user-sessions">
                {{ detail.onlineSessionCount }}
              </dd>
              @if (detail.lastActivity) {
                <dt class="col-sm-3">Last activity</dt>
                <dd class="col-sm-9" data-id="hilos-user-last-activity">
                  {{ detail.lastActivity }}
                </dd>
              }
            </dl>
          </div>
        </div>
        @if (context().accountMerge) {
          <section
            class="card border-danger mt-4"
            data-id="hilos-user-merge-zone"
          >
            <div class="card-body">
              <h2 class="h5">Merge another account into this one</h2>
              <p class="mb-3">
                Its sign-in methods and messages move here; the other account is
                closed for good.
              </p>
              <button
                type="button"
                class="btn btn-outline-danger"
                data-id="hilos-user-merge-open"
                (click)="openMerge()"
              >
                Merge an account into this…
              </button>
            </div>
          </section>
        }
      } @else {
        <p class="text-body-secondary" data-id="hilos-user-empty">
          Loading user…
        </p>
      }

      <hilos-modal
        [open]="editing()"
        (openChange)="onEditOpenChange($event)"
        [confirmOnClose]="dirty()"
      >
        <h5
          hilosConflictHeader
          modalHeader
          [title]="editTitle()"
          [conflict]="live().conflict"
        ></h5>
        <!-- The refusal is announced from here and not from the row that shows
        it: a role arriving together with its text is not announced at all
        (accessibility.md). The region lives inside the dialog because the dialog
        is aria-modal, which hides the page under it from a screen reader. -->
        <div
          class="visually-hidden"
          role="alert"
          aria-live="assertive"
          data-id="hilos-user-live-assertive"
        >
          {{ renameError() }}
        </div>
        <hilos-form-error
          [message]="renameError()"
          dataId="hilos-user-rename-error"
        />
        <form (submit)="submit($event)">
          <label class="form-label" for="hilos-user-name-field">
            Display name
          </label>
          <input
            id="hilos-user-name-field"
            type="text"
            class="form-control"
            [attr.minlength]="nameMin"
            [attr.maxlength]="nameMax"
            data-id="hilos-user-name-input"
            data-autofocus
            [value]="draft()"
            (input)="onDraftInput($event)"
          />
          <div class="form-text">
            Between {{ nameMin }} and {{ nameMax }} characters.
          </div>
          <hilos-edit-notice
            [kind]="editNotice()"
            [text]="editNoticeText()"
            dataId="hilos-user-edit-notice"
          />
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="loading()"
            data-id="hilos-user-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!valid() || !dirty() || loading() || live().gone"
            [mergeable]="false"
            [saveLabel]="saveLabel()"
            (save)="submit()"
            (acceptMine)="acceptMine()"
            (acceptTheirs)="acceptTheirs()"
          >
            <ng-template
              #saveButton
              let-disabled="disabled"
              let-onSave="onSave"
            >
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="loading()"
                [disabled]="disabled"
                data-id="hilos-user-save"
                (click)="onSave()"
              >
                {{ saveLabel() }}
              </button>
            </ng-template>
          </div>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="mergeOpen()"
        (openChange)="onMergeOpenChange($event)"
        [title]="
          detail()
            ? 'Merge an account into ' + detail()!.name
            : 'Merge an account'
        "
        [confirmOnClose]="selectedCandidateId() !== null"
        [closeOnBackdrop]="!mergeAction.busy()"
        [closeOnEsc]="!mergeAction.busy()"
        initialFocus="inner"
        size="wide"
      >
        <div class="visually-hidden" role="alert" aria-live="assertive">
          {{ mergeAction.error() }}
        </div>
        <hilos-action-error [action]="mergeAction" />
        @if (mergeStep() === 1) {
          <div role="radiogroup" aria-label="Account to merge">
            @if (mergeCandidatesController(); as controller) {
              <hilos-viewport-table
                [controller]="controller"
                [autofocusSearch]="true"
              >
                <ng-template hilosTableCell="actions" let-row>
                  <input
                    type="radio"
                    class="form-check-input"
                    [attr.aria-label]="'Merge ' + row.name"
                    [attr.data-id]="'hilos-user-merge-row-' + row.id"
                    [checked]="selectedCandidateId() === row.id"
                    [disabled]="row.id === currentUserId()"
                    (change)="chooseCandidate(row)"
                  />
                </ng-template>
                <ng-template hilosTableCell="name" let-row>
                  {{ row.name }}
                  <span class="text-body-secondary">#{{ row.id }}</span>
                  @if (row.id === currentUserId()) {
                    <span class="badge text-bg-secondary ms-2">you</span>
                  }
                </ng-template>
                <ng-template hilosTableCell="identities" let-row>
                  <ul class="list-unstyled mb-0">
                    @for (
                      identity of row.identities;
                      track identity.type + ':' + identity.identifier
                    ) {
                      <li>
                        <span class="fw-medium">{{
                          identityTitle(identity)
                        }}</span>
                        @if (identity.type !== 'passkey') {
                          · {{ identity.identifier }}
                        }
                        @if (identity.verified) {
                          <span aria-hidden="true"> ✓</span>
                          <span class="visually-hidden"> Verified</span>
                        }
                      </li>
                    }
                  </ul>
                </ng-template>
                <ng-template hilosTableCell="lastActivity" let-row>
                  {{ row.lastActivity ?? '—' }}
                </ng-template>
              </hilos-viewport-table>
            }
          </div>
        } @else if (mergeSummaryCandidate(); as candidate) {
          <p data-id="hilos-user-merge-summary">
            <strong>{{ candidate.name }} (#{{ candidate.id }})</strong>
            will be merged into
            <strong>{{ detail()?.name }} (#{{ detail()?.id }})</strong>.
          </p>
          <ul>
            <li>
              Its sign-in methods and everything it wrote move to the survivor.
            </li>
            <li>
              The other account is closed for good; it cannot sign in and its
              open tabs sign out.
            </li>
            <li>This cannot be undone.</li>
          </ul>
          @if (mergeGone()) {
            <p class="text-danger" data-id="hilos-user-merge-gone">
              No longer available
            </p>
          }
          @if (passwordChoiceRequired()) {
            <fieldset class="mb-3">
              <legend class="h6">
                Both accounts have a password. Which one stays?
              </legend>
              @for (choice of passwordChoices; track choice.value) {
                <div class="form-check">
                  <input
                    [id]="'hilos-user-merge-fate-' + choice.value + '-field'"
                    class="form-check-input"
                    type="radio"
                    name="hilos-user-merge-password-fate"
                    [value]="choice.value"
                    [attr.data-id]="'hilos-user-merge-fate-' + choice.value"
                    [checked]="passwordFate() === choice.value"
                    (change)="passwordFate.set(choice.value)"
                  />
                  <label
                    class="form-check-label"
                    [for]="'hilos-user-merge-fate-' + choice.value + '-field'"
                  >
                    {{ choice.label }}
                  </label>
                </div>
              }
            </fieldset>
          }
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="mergeAction.busy()"
            data-id="hilos-user-merge-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          @if (mergeStep() === 1) {
            <button
              type="button"
              class="btn btn-primary"
              [disabled]="selectedCandidate() === null"
              data-id="hilos-user-merge-next"
              (click)="nextMergeStep()"
            >
              Next
            </button>
          } @else {
            <button
              type="button"
              class="btn btn-secondary"
              [disabled]="mergeAction.busy()"
              data-id="hilos-user-merge-back"
              (click)="previousMergeStep()"
            >
              Back
            </button>
            <button
              hilosLoadingButton
              class="btn-danger"
              [loading]="mergeAction.loading()"
              [disabled]="mergeDisabled()"
              data-id="hilos-user-merge-confirm"
              (click)="submitMerge()"
            >
              Merge
            </button>
          }
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosUserPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USER
  protected readonly nameMin = 2
  protected readonly nameMax = 64
  protected readonly passwordChoices: readonly {
    value: HilosPasswordFate
    label: string
  }[] = [
    { value: 'survivor', label: 'The survivor password' },
    { value: 'loser', label: 'The other account password' },
    { value: 'none', label: 'Neither password; set a new one in Profile' },
  ]

  // Mirrored from the core selectors, which derive from the context input.
  protected readonly detail = signal<HilosUserDetailRow | undefined>(undefined)
  protected readonly renameError = signal<string | null>(null)
  private rename: HilosUserRename | undefined
  private mergeCandidates: HilosMergeCandidates | undefined
  private accountMerge: HilosAccountMerge | undefined

  protected readonly mergeCandidatesController = signal<
    TableViewportController<HilosMergeCandidateRow> | undefined
  >(undefined)
  protected readonly mergeRows = signal<
    readonly TableViewportRow<HilosMergeCandidateRow>[]
  >([])
  protected readonly currentUserId = signal<number | null>(null)
  protected readonly mergeOpen = signal(false)
  protected readonly mergeStep = signal<1 | 2>(1)
  protected readonly selectedCandidateId = signal<number | null>(null)
  protected readonly selectedSnapshot = signal<HilosMergeCandidateRow | null>(
    null,
  )
  protected readonly passwordFate = signal<HilosPasswordFate | null>(null)
  protected readonly mergeAction = createHilosTrackedAction()
  protected readonly selectedCandidate = computed(() => {
    const selected = this.mergeRows().find(
      (entry) => entry.row?.id === this.selectedCandidateId(),
    )

    return selected?.pending === 'remove' || selected?.placeholder
      ? null
      : (selected?.row ?? null)
  })
  protected readonly mergeSummaryCandidate = computed(
    () => this.selectedCandidate() ?? this.selectedSnapshot(),
  )
  protected readonly passwordChoiceRequired = computed(
    () =>
      this.detail()?.hasPassword === true &&
      this.selectedCandidate()?.hasPassword === true,
  )
  protected readonly mergeGone = computed(
    () => this.mergeStep() === 2 && this.selectedCandidate() === null,
  )
  protected readonly mergeDisabled = computed(
    () =>
      this.mergeAction.busy() ||
      this.mergeGone() ||
      this.selectedCandidate() === null ||
      (this.passwordChoiceRequired() && this.passwordFate() === null),
  )

  protected readonly editing = signal(false)
  protected readonly draft = signal('')
  protected readonly loading = signal(false)
  protected readonly editBaseline = signal<RowEditBaseline<UserEditFields>>(
    openRowEdit<UserEditFields>({ name: '' }),
  )
  protected readonly valid = computed(() => {
    const trimmed = this.draft().trim()

    return trimmed.length >= this.nameMin && trimmed.length <= this.nameMax
  })
  // The live row is the card's own detail row, projected onto the name; gone
  // once the card has no row any more.
  protected readonly live = computed(() => {
    const current = this.detail()

    return resolveRowEdit(
      current ? { name: current.name } : undefined,
      this.editBaseline(),
      { name: this.draft().trim() },
    )
  })
  protected readonly dirty = computed(() => this.live().dirty)
  protected readonly editTitle = computed(() => {
    const current = this.detail()

    return current ? `Rename · ${current.name}` : 'Rename user'
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editNoticeText = computed(() => noticeText(this.live()))
  protected readonly saveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )

  constructor() {
    // The context arrives via input and carries core signals; build the detail
    // selector and rename surface once it binds, mirror their signals into
    // Angular, and drop the subscriptions if the context is replaced.
    effect((onCleanup) => {
      const context = this.context()
      const detailSignal = createHilosUserDetail(context)
      const rename = createHilosUserRename(context)
      const mergeCandidates = createHilosMergeCandidates(context)
      const currentUserId = sessionUserId(context.scopes)
      this.rename = rename
      this.mergeCandidates = mergeCandidates
      this.accountMerge = createHilosAccountMerge(context)
      this.mergeCandidatesController.set(mergeCandidates.controller)
      this.detail.set(detailSignal.get())
      this.renameError.set(rename.renameError.get())
      this.mergeRows.set(mergeCandidates.controller.rows.get())
      this.currentUserId.set(currentUserId.get())
      const subscriptions = [
        subscribeSignal(detailSignal, (value) => this.detail.set(value)),
        subscribeSignal(rename.renameError, (value) =>
          this.renameError.set(value),
        ),
        subscribeSignal(mergeCandidates.controller.rows, (value) =>
          this.mergeRows.set(value),
        ),
        subscribeSignal(currentUserId, (value) =>
          this.currentUserId.set(value),
        ),
      ]
      onCleanup(() => {
        mergeCandidates.dispose()
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })

    effect(() => {
      const rows = this.mergeRows()
      untracked(() => {
        if (
          this.mergeStep() === 1 &&
          this.selectedCandidateId() !== null &&
          !rows.some(
            (entry) =>
              entry.row?.id === this.selectedCandidateId() &&
              entry.pending !== 'remove' &&
              !entry.placeholder,
          )
        ) {
          this.selectedCandidateId.set(null)
        }
      })
    })

    // Success is state-driven: the rename has landed once the committed name (over
    // the live table) reaches the submitted draft, which closes the modal. Track
    // only the name; read the form state untracked so the effect mirrors the Vue
    // watch-on-name.
    effect(() => {
      const name = this.detail()?.name
      untracked(() => {
        if (this.loading() && name === this.draft().trim()) {
          this.loading.set(false)
          this.editing.set(false)
        }
      })
    })

    // A rejected rename releases the button and keeps the modal open to retry.
    effect(() => {
      if (this.renameError() !== null) {
        this.loading.set(false)
      }
    })

    // The helper hands a step whenever the other side moved the name while the
    // person left it alone, or both arrived at the same one; the modal applies
    // it at once.
    effect(() => {
      const settle = this.live().settle
      const editing = this.editing()
      untracked(() => {
        if (editing && settle) {
          this.applyStep(settle)
        }
      })
    })
  }

  protected openEdit(): void {
    this.rename?.clearRenameError()
    const name = this.detail()?.name ?? ''
    this.draft.set(name)
    this.editBaseline.set(openRowEdit<UserEditFields>({ name }))
    this.loading.set(false)
    this.editing.set(true)
  }

  // Put a step of the helper into the modal: the snapshot moves, and a name
  // the step takes lands in the input.
  private applyStep(step: RowEditStep<UserEditFields>): void {
    this.editBaseline.set(step.baseline)
    if (step.take.name !== undefined) {
      this.draft.set(step.take.name)
    }
  }

  protected acceptMine(): void {
    this.editBaseline.set(keepMineRowEdit(this.live(), this.editBaseline()))
  }

  protected acceptTheirs(): void {
    this.applyStep(takeTheirsRowEdit(this.live(), this.editBaseline()))
  }

  // The modal's close path (Cancel / Esc / backdrop, through the discard guard).
  protected onEditOpenChange(open: boolean): void {
    if (open) {
      return
    }
    this.editing.set(false)
    this.loading.set(false)
    this.rename?.clearRenameError()
  }

  protected submit(event?: Event): void {
    event?.preventDefault()
    const current = this.detail()
    if (!current || !this.valid() || this.loading() || this.live().gone) {
      return
    }
    // No change: close without a round-trip (also keeps the state-driven success
    // watch from waiting on a name that will never change).
    if (!this.live().dirty) {
      this.onEditOpenChange(false)

      return
    }

    this.loading.set(
      this.rename?.submitRename(current.id, this.draft().trim()) ?? false,
    )
  }

  protected onDraftInput(event: Event): void {
    this.draft.set((event.target as HTMLInputElement).value)
  }

  protected identityTitle(identity: HilosMergeCandidateIdentity): string {
    return identity.provider ?? identity.type
  }

  protected openMerge(): void {
    const survivor = this.detail()
    if (!survivor || !this.mergeCandidates) {
      return
    }
    this.mergeCandidates.dispose()
    this.mergeAction.clearError()
    this.mergeStep.set(1)
    this.selectedCandidateId.set(null)
    this.selectedSnapshot.set(null)
    this.passwordFate.set(null)
    this.mergeOpen.set(true)
    this.mergeCandidates.start(survivor.id)
  }

  protected onMergeOpenChange(open: boolean): void {
    this.mergeOpen.set(open)
    if (!open) {
      this.mergeCandidates?.dispose()
    }
  }

  protected chooseCandidate(row: HilosMergeCandidateRow): void {
    if (row.id === this.currentUserId()) {
      return
    }
    this.selectedCandidateId.set(row.id)
    this.passwordFate.set(null)
    this.mergeAction.clearError()
  }

  protected nextMergeStep(): void {
    const candidate = this.selectedCandidate()
    if (!candidate) {
      return
    }
    this.selectedSnapshot.set(candidate)
    this.mergeStep.set(2)
  }

  protected previousMergeStep(): void {
    this.mergeStep.set(1)
    if (!this.selectedCandidate()) {
      this.selectedCandidateId.set(null)
      this.selectedSnapshot.set(null)
    }
    this.mergeAction.clearError()
  }

  protected async submitMerge(): Promise<void> {
    const survivor = this.detail()
    const loser = this.selectedCandidate()
    if (!survivor || !loser || !this.accountMerge || this.mergeDisabled()) {
      return
    }
    const fate = this.passwordChoiceRequired()
      ? (this.passwordFate() ?? undefined)
      : undefined
    if (
      await this.mergeAction.run(
        this.accountMerge.merge(survivor.id, loser.id, fate),
      )
    ) {
      this.onMergeOpenChange(false)
    }
  }
}
