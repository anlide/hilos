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
// name it sent over the live table, closing the modal); a failure surfaces from the
// backend fail ack inside the modal. The context arrives via input and
// carries core signals, so — like HilosTable — an effect builds the selectors
// once it binds and mirrors them into Angular signals. The person's standing is
// one verdict (createHilosUserStanding, HIL-945): the badge beside the presence
// in the header shows the standing shown, and the access section draws the
// block, the freeze — a fact with no control — and the deletion from the same
// verdict. A window whose action takes something away — the merge, rights, the
// block, the deletion — first asks the server whether the administrator must
// confirm it is them, and opens on that step when it must
// (createHilosUserCardStepUp, HIL-1275). The takeover lives here too (HIL-1170,
// on the users list before): a section drawn while the installation allows
// impersonation, its button switched off with a reason on the person's own card
// and on whom the settings exclude, and a window — after the same confirmation
// step, operation `impersonate` — whose words follow the settings
// (hilosUserImpersonationSection). A success needs no word: the session rebinds
// and the strip rises. The buttons that only open a window stay live in a
// takeover that only looks; the confirmation in the window does not. Bootstrap
// classes only (styling-rules.md).
import {
  afterRenderEffect,
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core'
import {
  ACCOUNT_DELETION_TICK_MS,
  createHilosImpersonate,
  createHilosUserCardStepUp,
  createHilosUserLifecycle,
  focusInitial,
  HILOS_STEP_UP_COPY,
  createHilosUserStanding,
  HILOS_USER_IMPERSONATION_COPY,
  HILOS_USER_LIFECYCLE_COPY,
  hilosStandingBadge,
  hilosUserImpersonationSection,
  hilosUserFrozenRow,
  hilosUserLifecycleSections,
  hilosUserLifecyclePrompt,
  submitHilosUserLifecycle,
  type HilosUserLifecycle,
  type HilosUserLifecycleChoice,
  type HilosUserLifecyclePrompt,
  type HilosUserLifecycleSection,
  HilosPages,
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  createHilosUserRename,
  HILOS_ACCOUNT_MERGE_PASSWORD_COPY,
  hilosPasswordFateChoices,
  hiddenAsWord,
  HILOS_VIEW_MODE_COPY,
  isHiddenValue,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  sessionUserId,
  subscribeSignal,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  Hideable,
  HilosAccountMerge,
  HilosAccountStanding,
  HilosMergeCandidateIdentity,
  HilosMergeCandidateRow,
  HilosMergeCandidates,
  HilosPasswordFate,
  HilosStepUpOpenOutcome,
  HilosUserCardStepUp,
  HilosUserDetailRow,
  HilosUserImpersonationSection,
  HilosUserImpersonationSettings,
  HilosUserRename,
  HilosUsersContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { HilosStepUpStep } from '../../auth/HilosStepUpStep.js'
import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosAvatar } from '../../HilosAvatar.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/**
 * Move the focus into a window whose step changed under it; the modal itself
 * places focus only when it opens.
 *
 * @param body An element inside the window, drawn at the new step.
 */
function focusWindow(body: HTMLElement | undefined): void {
  const dialog = body?.closest<HTMLElement>('[role="dialog"]')
  if (dialog) {
    focusInitial(dialog)
  }
}

/**
 * The one field the modal edits: the display name — hidden for a viewer of the
 * admin view mode, and then the modal says so in place of the input.
 */
interface UserEditFields {
  name: Hideable<string>
}

/** The one line the modal says about the other side, for what the helper found. */
function noticeText(live: RowEditState<UserEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return `Changed elsewhere to "${hiddenAsWord(live.fields.name.incoming)}".`
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
    HilosAvatar,
    HilosEditNotice,
    HilosFormError,
    HilosModal,
    HilosStepUpStep,
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
            <hilos-avatar [name]="avatarName()" size="md" />
            <span class="h5 mb-0" data-id="hilos-user-name">{{
              hiddenAsWord(detail.name)
            }}</span>
            <span class="badge text-bg-secondary">{{ detail.presence }}</span>
            @if (standingBadge(); as badge) {
              <span
                class="badge"
                [class]="'text-bg-' + badge.tone"
                data-id="user-standing-badge"
              >
                <i class="bi me-1" [class]="badge.icon" aria-hidden="true"></i
                >{{ badge.label }}
              </span>
            }
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
        @for (section of lifecycleSections(); track section.key) {
          <section
            class="card mt-4"
            [attr.data-id]="'hilos-user-' + section.key"
          >
            <div class="card-body">
              <h2 class="h5">{{ section.title }}</h2>
              @for (row of section.rows; track row.key) {
                <div class="d-flex flex-wrap align-items-start gap-3 py-2">
                  <div
                    class="flex-grow-1"
                    [attr.data-id]="'hilos-user-' + row.key + '-state'"
                  >
                    <h3 class="h6 mb-1">
                      {{ row.title }}
                      <span class="badge text-bg-secondary">{{
                        row.state ? lifecycleCopy.yes : lifecycleCopy.no
                      }}</span>
                    </h3>
                    <p class="small text-body-secondary mb-0">{{ row.hint }}</p>
                  </div>
                  <div>
                    <button
                      hilosLoadingButton
                      class="btn-sm"
                      [class.btn-outline-danger]="
                        lifecycleCopy.confirmations[row.choice].danger
                      "
                      [class.btn-primary]="
                        !lifecycleCopy.confirmations[row.choice].danger
                      "
                      [opensWindow]="true"
                      [loading]="lifecycleOpening() === row.choice"
                      [disabled]="row.disabled"
                      [aria-describedby]="'hilos-user-' + row.key + '-reason'"
                      [attr.data-id]="'hilos-user-' + row.key + '-open'"
                      (click)="openLifecycle(row.choice)"
                    >
                      {{ lifecycleCopy[row.choice] }}
                    </button>
                    <div class="hilos-stack small text-body-secondary mt-1">
                      <span class="invisible" aria-hidden="true">{{
                        row.reasonSpace
                      }}</span>
                      <span
                        [id]="'hilos-user-' + row.key + '-reason'"
                        [attr.data-id]="'hilos-user-' + row.key + '-reason'"
                        >{{ row.reason }}</span
                      >
                    </div>
                  </div>
                </div>
                <!-- The freeze stands between the block and the deletion, and
                offers nothing to press: only the person's own acceptance lifts
                it. -->
                @if (row.key === 'block') {
                  @if (frozenRow(); as frozen) {
                    <div class="d-flex flex-wrap align-items-start gap-3 py-2">
                      <div
                        class="flex-grow-1"
                        data-id="hilos-user-frozen-state"
                      >
                        <h3 class="h6 mb-1">
                          {{ frozen.title }}
                          <span class="badge text-bg-secondary">{{
                            frozen.state ? lifecycleCopy.yes : lifecycleCopy.no
                          }}</span>
                        </h3>
                        @if (frozen.hint !== null) {
                          <p class="small text-body-secondary mb-0">
                            {{ frozen.hint }}
                          </p>
                        }
                        @if (frozen.lapsed.length > 0) {
                          <ul
                            class="list-unstyled small text-body-secondary mb-0"
                            data-id="hilos-user-frozen-lapsed"
                          >
                            @for (line of frozen.lapsed; track line) {
                              <li>{{ line }}</li>
                            }
                          </ul>
                        }
                      </div>
                    </div>
                  }
                }
              }
            </div>
          </section>
        }
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
                hilosLoadingButton
                class="btn-outline-danger"
                [opensWindow]="true"
                [loading]="mergeOpening()"
                data-id="hilos-user-merge-open"
                (click)="openMerge()"
              >
                Merge an account into this…
              </button>
            </div>
          </section>
        }
        @if (impersonation(); as section) {
          <section class="card mt-4">
            <div class="card-body">
              <h2 class="h5">{{ section.title }}</h2>
              <div class="d-flex flex-wrap align-items-start gap-3 py-2">
                <div class="flex-grow-1">
                  <h3 class="h6 mb-1">{{ section.rowTitle }}</h3>
                  <p class="small text-body-secondary mb-0">
                    {{ section.hint }}
                  </p>
                </div>
                <div>
                  <button
                    hilosLoadingButton
                    class="btn-sm btn-primary"
                    [opensWindow]="true"
                    [loading]="impersonateOpening()"
                    [disabled]="section.disabled"
                    aria-describedby="hilos-user-impersonate-reason"
                    data-id="hilos-user-impersonate-open"
                    (click)="openImpersonate()"
                  >
                    {{ impersonationCopy.open }}
                  </button>
                  <div class="hilos-stack small text-body-secondary mt-1">
                    <span class="invisible" aria-hidden="true">{{
                      section.reasonSpace
                    }}</span>
                    <span
                      id="hilos-user-impersonate-reason"
                      data-id="hilos-user-impersonate-reason"
                      >{{ section.reason }}</span
                    >
                  </div>
                </div>
              </div>
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
          @if (draftHidden()) {
            <div class="form-label">Display name</div>
            <div>{{ hiddenWord }}</div>
          } @else {
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
              [value]="draftText()"
              (input)="onDraftInput($event)"
            />
            <div class="form-text">
              Between {{ nameMin }} and {{ nameMax }} characters.
            </div>
          }
          <hilos-edit-notice
            [kind]="editNotice()"
            [text]="editNoticeText()"
            dataId="hilos-user-edit-notice"
          />
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!valid() || !dirty() || loading() || live().gone"
            [saveLabel]="saveLabel()"
            (save)="submit()"
            (acceptMine)="acceptMine()"
            (acceptTheirs)="acceptTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="loading()"
                data-id="hilos-user-cancel"
                (click)="requestClose()"
              >
                Cancel
              </button>
            </ng-template>
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
        [open]="lifecyclePrompt() !== null"
        (openChange)="onLifecycleOpenChange($event)"
        [title]="
          lifecycleProof() === 'skip'
            ? (lifecyclePrompt()?.title ?? '')
            : stepUpCopy.title
        "
        initialFocus="inner"
        [closeOnBackdrop]="!lifecycleAction.busy()"
        [closeOnEsc]="!lifecycleAction.busy()"
      >
        <div #lifecycleBody>
          <div class="visually-hidden" role="alert" aria-live="assertive">
            {{
              lifecycleProof() === 'skip'
                ? lifecycleAction.error()
                : lifecycleStepUpRefusal()
            }}
          </div>
          @if (lifecycleProof() === 'skip') {
            @for (
              paragraph of lifecyclePrompt()?.paragraphs ?? [];
              track paragraph
            ) {
              <p>{{ paragraph }}</p>
            }
            <div data-id="hilos-user-lifecycle-error">
              <hilos-action-error
                [action]="lifecycleAction"
                detailsTitle="Account change refused"
              />
            </div>
          } @else {
            <form
              id="hilos-user-lifecycle-proof"
              data-id="hilos-user-lifecycle-step-up"
              (submit)="$event.preventDefault(); confirmLifecycleStep()"
            >
              <hilos-step-up-step [controller]="lifecycleStepUp().step" />
            </form>
          }
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="lifecycleAction.busy()"
            data-id="hilos-user-lifecycle-cancel"
            (click)="requestClose()"
          >
            {{ lifecycleCopy.cancel }}
          </button>
          @if (lifecycleProof() === 'ask') {
            <button
              hilosLoadingButton
              class="btn-primary"
              type="submit"
              form="hilos-user-lifecycle-proof"
              [loading]="lifecycleStepUpBusy()"
              data-id="hilos-user-lifecycle-step-up-confirm"
            >
              {{ stepUpCopy.confirm }}
            </button>
          } @else if (lifecycleProof() === 'skip') {
            <button
              hilosLoadingButton
              [class.btn-danger]="lifecyclePrompt()?.danger"
              [class.btn-primary]="!lifecyclePrompt()?.danger"
              [loading]="lifecycleAction.loading()"
              [disabled]="
                lifecycleAction.busy() ||
                detail()?.id !== lifecyclePrompt()?.userId
              "
              data-id="hilos-user-lifecycle-confirm"
              (click)="submitLifecycle()"
            >
              {{ lifecyclePrompt()?.confirm }}
            </button>
          }
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="impersonateTarget() !== null"
        (openChange)="onImpersonateOpenChange($event)"
        [title]="
          impersonateProof() === 'skip'
            ? (impersonateTarget()?.section?.windowTitle ?? '')
            : stepUpCopy.title
        "
        initialFocus="inner"
        [closeOnBackdrop]="!impersonateAction.busy()"
        [closeOnEsc]="!impersonateAction.busy()"
      >
        <div #impersonateBody>
          <div class="visually-hidden" role="alert" aria-live="assertive">
            {{
              impersonateProof() === 'skip'
                ? impersonateAction.error()
                : impersonateStepUpRefusal()
            }}
          </div>
          @if (impersonateProof() !== 'skip') {
            <form
              id="hilos-user-impersonate-proof"
              data-id="hilos-user-impersonate-step-up"
              (submit)="$event.preventDefault(); confirmImpersonateStep()"
            >
              <hilos-step-up-step [controller]="impersonateStepUp().step" />
            </form>
          } @else if (impersonateTarget(); as target) {
            @for (paragraph of target.section.paragraphs; track paragraph) {
              <p>{{ paragraph }}</p>
            }
            <div class="alert alert-secondary small py-2">
              {{ target.section.note }}
            </div>
            <div data-id="hilos-user-impersonate-error">
              <hilos-action-error
                [action]="impersonateAction"
                detailsTitle="Couldn't impersonate this person"
              />
            </div>
          }
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="impersonateAction.busy()"
            data-id="hilos-user-impersonate-cancel"
            (click)="requestClose()"
          >
            {{ impersonationCopy.cancel }}
          </button>
          @if (impersonateProof() === 'ask') {
            <button
              hilosLoadingButton
              class="btn-primary"
              type="submit"
              form="hilos-user-impersonate-proof"
              [loading]="impersonateStepUpBusy()"
              data-id="hilos-user-impersonate-step-up-confirm"
            >
              {{ stepUpCopy.confirm }}
            </button>
          } @else if (impersonateProof() === 'skip') {
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="impersonateAction.loading()"
              [disabled]="
                impersonateAction.busy() ||
                detail()?.id !== impersonateTarget()?.userId
              "
              data-id="hilos-user-impersonate-confirm"
              (click)="submitImpersonate()"
            >
              {{ impersonationCopy.confirm }}
            </button>
          }
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="mergeOpen()"
        (openChange)="onMergeOpenChange($event)"
        [title]="
          mergeProof() !== 'skip'
            ? stepUpCopy.title
            : detail()
              ? 'Merge an account into ' + hiddenAsWord(detail()!.name)
              : 'Merge an account'
        "
        [confirmOnClose]="selectedCandidateId() !== null"
        [closeOnBackdrop]="!mergeAction.busy()"
        [closeOnEsc]="!mergeAction.busy()"
        initialFocus="inner"
        size="wide"
      >
        <div
          #mergeBody
          class="visually-hidden"
          role="alert"
          aria-live="assertive"
        >
          {{
            mergeProof() === 'skip' ? mergeAction.error() : mergeStepUpRefusal()
          }}
        </div>
        <hilos-action-error
          [action]="mergeAction"
          detailsTitle="Couldn't merge the accounts"
        />
        @if (mergeProof() !== 'skip') {
          <form
            id="hilos-user-merge-proof"
            data-id="hilos-user-merge-step-up"
            (submit)="$event.preventDefault(); confirmMergeStep()"
          >
            <hilos-step-up-step [controller]="mergeStepUp().step" />
          </form>
        } @else if (mergeStep() === 1) {
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
                    [attr.aria-label]="
                      isHiddenValue(row.name)
                        ? 'Merge #' + row.id
                        : 'Merge ' + row.name
                    "
                    [attr.data-id]="'hilos-user-merge-row-' + row.id"
                    [checked]="selectedCandidateId() === row.id"
                    [disabled]="row.id === currentUserId()"
                    (change)="chooseCandidate(row)"
                  />
                </ng-template>
                <ng-template hilosTableCell="name" let-row>
                  {{ hiddenAsWord(row.name) }}
                  <span class="text-body-secondary">#{{ row.id }}</span>
                  @if (row.id === currentUserId()) {
                    <span class="badge text-bg-secondary ms-2">you</span>
                  }
                </ng-template>
                <ng-template hilosTableCell="identities" let-row>
                  @if (isHiddenValue(row.identities)) {
                    <span>{{ hiddenWord }}</span>
                  } @else {
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
                  }
                </ng-template>
                <ng-template hilosTableCell="lastActivity" let-row>
                  {{ row.lastActivity ?? '—' }}
                </ng-template>
              </hilos-viewport-table>
            }
          </div>
        } @else if (mergeSummaryCandidate(); as candidate) {
          <p data-id="hilos-user-merge-summary">
            <strong
              >{{ hiddenAsWord(candidate.name) }} (#{{ candidate.id }})</strong
            >
            will be merged into
            <strong
              >{{ detail() ? hiddenAsWord(detail()!.name) : '' }} (#{{
                detail()?.id
              }})</strong
            >.
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
                {{ passwordCopy.legend }}
              </legend>
              @for (choice of passwordChoices(); track choice.value) {
                <div class="form-check">
                  <input
                    [id]="'hilos-user-merge-fate-' + choice.value + '-field'"
                    class="form-check-input"
                    type="radio"
                    name="hilos-user-merge-password-fate"
                    [value]="choice.value"
                    [attr.data-id]="'hilos-user-merge-fate-' + choice.value"
                    [attr.aria-describedby]="
                      choice.removes.length > 0
                        ? 'hilos-user-merge-fate-' + choice.value + '-removes'
                        : null
                    "
                    [checked]="passwordFate() === choice.value"
                    (change)="passwordFate.set(choice.value)"
                  />
                  <label
                    class="form-check-label"
                    [for]="'hilos-user-merge-fate-' + choice.value + '-field'"
                  >
                    {{ choice.label }}
                  </label>
                  @if (choice.removes.length > 0) {
                    <div
                      [id]="
                        'hilos-user-merge-fate-' + choice.value + '-removes'
                      "
                      [attr.data-id]="
                        'hilos-user-merge-fate-' + choice.value + '-removes'
                      "
                      class="form-text text-danger"
                    >
                      @for (removal of choice.removes; track removal) {
                        <div>{{ removal }}</div>
                      }
                    </div>
                  }
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
          @if (mergeProof() === 'ask') {
            <button
              hilosLoadingButton
              class="btn-primary"
              type="submit"
              form="hilos-user-merge-proof"
              [loading]="mergeStepUpBusy()"
              data-id="hilos-user-merge-step-up-confirm"
            >
              {{ stepUpCopy.confirm }}
            </button>
          } @else if (mergeProof() === 'refused') {
            <!-- A refused step offers Cancel alone. -->
          } @else if (mergeStep() === 1) {
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
  protected readonly passwordCopy = HILOS_ACCOUNT_MERGE_PASSWORD_COPY
  protected readonly hiddenWord = HILOS_VIEW_MODE_COPY.hidden
  protected readonly hiddenAsWord = hiddenAsWord
  protected readonly isHiddenValue = isHiddenValue

  // Mirrored from the core selectors, which derive from the context input.
  protected readonly detail = signal<HilosUserDetailRow | undefined>(undefined)
  protected readonly renameError = signal<string | null>(null)
  private lifecycle: HilosUserLifecycle | undefined
  protected readonly lifecycleAction = createHilosTrackedAction()
  protected readonly lifecyclePrompt = signal<HilosUserLifecyclePrompt | null>(
    null,
  )
  protected readonly lifecycleCopy = HILOS_USER_LIFECYCLE_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  // The confirmation step each window opens with (HIL-1275): `ask` draws it,
  // `refused` draws its refusal, `skip` the window's own content.
  protected readonly lifecycleStepUp = computed(() =>
    createHilosUserCardStepUp(this.context()),
  )
  protected readonly lifecycleStepUpBusy = signal(false)
  protected readonly lifecycleStepUpRefusal = signal<string | null>(null)
  protected readonly lifecycleProof = signal<HilosStepUpOpenOutcome>('skip')
  // The window whose button waits for the server's word; a second press sends nothing.
  protected readonly lifecycleOpening = signal<HilosUserLifecycleChoice | null>(
    null,
  )
  private readonly lifecycleBody =
    viewChild<ElementRef<HTMLElement>>('lifecycleBody')
  private readonly graceDays = signal<Hideable<number> | null>(null)
  private readonly lifecycleNow = signal(Date.now())
  private readonly standing = signal<HilosAccountStanding | null>(null)
  protected readonly standingBadge = computed(() => {
    const standing = this.standing()

    return standing === null ? null : hilosStandingBadge(standing.shown)
  })
  protected readonly frozenRow = computed(() =>
    hilosUserFrozenRow(this.standing()),
  )
  protected readonly lifecycleSections = computed<
    readonly HilosUserLifecycleSection[]
  >(() =>
    hilosUserLifecycleSections(
      this.detail(),
      this.currentUserId(),
      this.graceDays(),
      this.lifecycleNow(),
      this.standing(),
    ),
  )
  // The takeover (HIL-1170): the section reads the installation's settings the
  // page's first answer carried, the person's live standing and admin flag, and
  // who stands behind this session. The window keeps the words it opened with.
  protected readonly impersonationCopy = HILOS_USER_IMPERSONATION_COPY
  private readonly impersonate = computed(() =>
    createHilosImpersonate(this.context()),
  )
  protected readonly impersonateAction = createHilosTrackedAction()
  protected readonly impersonateStepUp = computed(() =>
    createHilosUserCardStepUp(this.context()),
  )
  protected readonly impersonateStepUpBusy = signal(false)
  protected readonly impersonateStepUpRefusal = signal<string | null>(null)
  protected readonly impersonateProof = signal<HilosStepUpOpenOutcome>('skip')
  protected readonly impersonateOpening = signal(false)
  private readonly impersonateBody =
    viewChild<ElementRef<HTMLElement>>('impersonateBody')
  private readonly impersonationSettings =
    signal<HilosUserImpersonationSettings | null>(null)
  protected readonly impersonation = computed(() =>
    hilosUserImpersonationSection(
      this.detail(),
      this.currentUserId(),
      this.impersonationSettings(),
      this.standing(),
    ),
  )
  protected readonly impersonateTarget = signal<{
    userId: number
    section: HilosUserImpersonationSection
  } | null>(null)
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
  protected readonly mergeStepUp = computed(() =>
    createHilosUserCardStepUp(this.context()),
  )
  protected readonly mergeStepUpBusy = signal(false)
  protected readonly mergeStepUpRefusal = signal<string | null>(null)
  protected readonly mergeProof = signal<HilosStepUpOpenOutcome>('skip')
  protected readonly mergeOpening = signal(false)
  private readonly mergeBody = viewChild<ElementRef<HTMLElement>>('mergeBody')
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
  protected readonly passwordChoices = computed(() =>
    hilosPasswordFateChoices(this.detail(), this.selectedCandidate()),
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
  // A hidden name stays the one hidden value, so the row-edit helper sees it
  // unchanged and the modal is never dirty; the input edits only a name.
  protected readonly draft = signal<Hideable<string>>('')
  protected readonly draftHidden = computed(() => isHiddenValue(this.draft()))
  protected readonly draftText = computed(() => {
    const draft = this.draft()

    return isHiddenValue(draft) ? '' : draft
  })
  // A hidden name draws the person icon, as a name without initials does.
  protected readonly avatarName = computed(() => {
    const name = this.detail()?.name ?? ''

    return isHiddenValue(name) ? '' : name
  })
  protected readonly loading = signal(false)
  // The name the rename in flight sent — what the success effect waits for;
  // null while nothing is in flight.
  private readonly sentName = signal<string | null>(null)
  protected readonly editBaseline = signal<RowEditBaseline<UserEditFields>>(
    openRowEdit<UserEditFields>({ name: '' }),
  )
  protected readonly valid = computed(() => {
    const draft = this.draft()
    if (isHiddenValue(draft)) {
      return false
    }
    const trimmed = draft.trim()

    return trimmed.length >= this.nameMin && trimmed.length <= this.nameMax
  })
  // The live row is the card's own detail row, projected onto the name; gone
  // once the card has no row any more.
  protected readonly live = computed(() => {
    const current = this.detail()
    const draft = this.draft()

    return resolveRowEdit(
      current ? { name: current.name } : undefined,
      this.editBaseline(),
      { name: isHiddenValue(draft) ? draft : draft.trim() },
    )
  })
  protected readonly dirty = computed(() => this.live().dirty)
  protected readonly editTitle = computed(() => {
    const current = this.detail()

    return current ? `Rename · ${hiddenAsWord(current.name)}` : 'Rename user'
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editNoticeText = computed(() => noticeText(this.live()))
  protected readonly saveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )

  protected async openLifecycle(
    choice: HilosUserLifecycleChoice,
  ): Promise<void> {
    const detail = this.detail()
    if (
      !detail ||
      this.lifecycleAction.busy() ||
      this.lifecycleOpening() !== null
    )
      return
    this.lifecycleAction.clearError()
    const prompt = hilosUserLifecyclePrompt(detail, choice, this.graceDays())
    this.lifecycleOpening.set(choice)
    this.lifecycleProof.set(await this.lifecycleStepUp().open(choice))
    this.lifecycleOpening.set(null)
    this.lifecyclePrompt.set(prompt)
  }

  /** Send the step's proof; the window's own content follows a success. */
  protected async confirmLifecycleStep(): Promise<void> {
    if (
      this.lifecycleProof() === 'ask' &&
      (await this.lifecycleStepUp().step.confirm()) &&
      this.lifecyclePrompt() !== null
    ) {
      this.lifecycleProof.set('skip')
    }
  }

  protected onLifecycleOpenChange(open: boolean): void {
    if (!open) this.lifecyclePrompt.set(null)
  }

  protected async submitLifecycle(): Promise<void> {
    const prompt = this.lifecyclePrompt()
    if (
      !prompt ||
      !this.lifecycle ||
      this.lifecycleAction.busy() ||
      this.detail()?.id !== prompt.userId
    )
      return
    if (
      await this.lifecycleAction.run(
        submitHilosUserLifecycle(this.lifecycle, prompt),
      )
    )
      this.lifecyclePrompt.set(null)
  }

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
      const lifecycle = createHilosUserLifecycle(context)
      this.lifecycle = lifecycle
      this.graceDays.set(lifecycle.graceDays.get())
      this.impersonationSettings.set(lifecycle.impersonation.get())
      const userStanding = createHilosUserStanding(context)
      userStanding.start()
      this.standing.set(userStanding.standing.get())
      this.mergeCandidatesController.set(mergeCandidates.controller)
      this.detail.set(detailSignal.get())
      this.renameError.set(rename.renameError.get())
      this.mergeRows.set(mergeCandidates.controller.rows.get())
      this.currentUserId.set(currentUserId.get())
      const subscriptions = [
        subscribeSignal(lifecycle.graceDays, (value) =>
          this.graceDays.set(value),
        ),
        subscribeSignal(lifecycle.impersonation, (value) =>
          this.impersonationSettings.set(value),
        ),
        subscribeSignal(userStanding.standing, (value) => {
          if (
            value?.deletionEffectiveAt !==
            untracked(this.standing)?.deletionEffectiveAt
          ) {
            this.lifecycleNow.set(Date.now())
          }
          this.standing.set(value)
        }),
        subscribeSignal(detailSignal, (value) => {
          this.lifecycleNow.set(Date.now())
          this.detail.set(value)
        }),
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
      const tick = setInterval(
        () => this.lifecycleNow.set(Date.now()),
        ACCOUNT_DELETION_TICK_MS,
      )
      onCleanup(() => {
        clearInterval(tick)
        mergeCandidates.dispose()
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
        userStanding.dispose()
      })
    })

    // Each window's step mirrors its busy flag and its refusal into Angular.
    const mirrorStepUp = (
      stepUp: () => HilosUserCardStepUp,
      busy: (value: boolean) => void,
      refusal: (value: string | null) => void,
    ): void => {
      effect((onCleanup) => {
        const step = stepUp().step
        busy(step.busy.get())
        refusal(step.refusal.get())
        onCleanup(subscribeSignal(step.busy, busy))
        onCleanup(subscribeSignal(step.refusal, refusal))
      })
    }
    mirrorStepUp(
      this.lifecycleStepUp,
      (value) => this.lifecycleStepUpBusy.set(value),
      (value) => this.lifecycleStepUpRefusal.set(value),
    )
    mirrorStepUp(
      this.mergeStepUp,
      (value) => this.mergeStepUpBusy.set(value),
      (value) => this.mergeStepUpRefusal.set(value),
    )
    mirrorStepUp(
      this.impersonateStepUp,
      (value) => this.impersonateStepUpBusy.set(value),
      (value) => this.impersonateStepUpRefusal.set(value),
    )
    // The step a window moves to takes the focus once it is drawn.
    afterRenderEffect(() => {
      this.lifecycleProof()
      focusWindow(this.lifecycleBody()?.nativeElement)
    })
    afterRenderEffect(() => {
      this.mergeProof()
      focusWindow(this.mergeBody()?.nativeElement)
    })
    afterRenderEffect(() => {
      this.impersonateProof()
      focusWindow(this.impersonateBody()?.nativeElement)
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
    // the live table) reaches the name it sent, which closes the modal. The draft
    // is not part of it: Take theirs while the rename flies rewrites the draft,
    // and the modal still waits for its own name. Track only the name; read the
    // form state untracked so the effect mirrors the Vue watch-on-name.
    effect(() => {
      const name = this.detail()?.name
      untracked(() => {
        if (this.loading() && name === this.sentName()) {
          this.loading.set(false)
          this.sentName.set(null)
          this.editing.set(false)
        }
      })
    })

    // A rejected rename releases the button, forgets the name it sent, and keeps
    // the modal open to retry.
    effect(() => {
      if (this.renameError() !== null) {
        this.loading.set(false)
        this.sentName.set(null)
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

  protected async openImpersonate(): Promise<void> {
    const detail = this.detail()
    const section = this.impersonation()
    if (
      !detail ||
      section === null ||
      this.impersonateAction.busy() ||
      this.impersonateOpening()
    )
      return
    this.impersonateAction.clearError()
    this.impersonateOpening.set(true)
    this.impersonateProof.set(
      await this.impersonateStepUp().open('impersonate'),
    )
    this.impersonateOpening.set(false)
    this.impersonateTarget.set({ userId: detail.id, section })
  }

  /** Send the step's proof; the window's own content follows a success. */
  protected async confirmImpersonateStep(): Promise<void> {
    if (
      this.impersonateProof() === 'ask' &&
      (await this.impersonateStepUp().step.confirm()) &&
      this.impersonateTarget() !== null
    ) {
      this.impersonateProof.set('skip')
    }
  }

  protected onImpersonateOpenChange(open: boolean): void {
    if (!open && !this.impersonateAction.busy()) {
      this.impersonateTarget.set(null)
    }
  }

  // Authoritative-backend: what the takeover changes — the strip, and this
  // session becoming the person — arrives with the rebound session, so a success
  // only closes the window; a refusal stays in it, and the driver toasts it.
  protected async submitImpersonate(): Promise<void> {
    const target = this.impersonateTarget()
    if (
      target === null ||
      this.impersonateAction.busy() ||
      this.detail()?.id !== target.userId
    )
      return
    if (
      await this.impersonateAction.run(this.impersonate().start(target.userId))
    )
      this.impersonateTarget.set(null)
  }

  protected openEdit(): void {
    this.rename?.clearRenameError()
    const name = this.detail()?.name ?? ''
    this.draft.set(name)
    this.editBaseline.set(openRowEdit<UserEditFields>({ name }))
    this.loading.set(false)
    this.sentName.set(null)
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
    this.sentName.set(null)
    this.rename?.clearRenameError()
  }

  protected submit(event?: Event): void {
    event?.preventDefault()
    const current = this.detail()
    const draft = this.draft()
    if (
      !current ||
      isHiddenValue(draft) ||
      !this.valid() ||
      this.loading() ||
      this.live().gone
    ) {
      return
    }
    // No change: close without a round-trip (also keeps the state-driven success
    // watch from waiting on a name that will never change).
    if (!this.live().dirty) {
      this.onEditOpenChange(false)

      return
    }

    const name = draft.trim()
    const sent = this.rename?.submitRename(current.id, name) ?? false
    this.loading.set(sent)
    this.sentName.set(sent ? name : null)
  }

  protected onDraftInput(event: Event): void {
    this.draft.set((event.target as HTMLInputElement).value)
  }

  protected identityTitle(identity: HilosMergeCandidateIdentity): string {
    return identity.provider ?? identity.type
  }

  protected async openMerge(): Promise<void> {
    const survivor = this.detail()
    const mergeCandidates = this.mergeCandidates
    if (!survivor || !mergeCandidates || this.mergeOpening()) {
      return
    }
    mergeCandidates.dispose()
    this.mergeAction.clearError()
    this.mergeStep.set(1)
    this.selectedCandidateId.set(null)
    this.selectedSnapshot.set(null)
    this.passwordFate.set(null)
    this.mergeOpening.set(true)
    const proof = await this.mergeStepUp().open('merge')
    this.mergeOpening.set(false)
    this.mergeProof.set(proof)
    this.mergeOpen.set(true)
    // Other accounts are shown only once the administrator stands confirmed.
    if (proof === 'skip') {
      mergeCandidates.start(survivor.id)
    }
  }

  /** Send the step's proof; the choice of an account follows a success. */
  protected async confirmMergeStep(): Promise<void> {
    const survivor = this.detail()
    if (
      survivor &&
      this.mergeProof() === 'ask' &&
      (await this.mergeStepUp().step.confirm()) &&
      this.mergeOpen()
    ) {
      this.mergeProof.set('skip')
      this.mergeCandidates?.start(survivor.id)
    }
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
