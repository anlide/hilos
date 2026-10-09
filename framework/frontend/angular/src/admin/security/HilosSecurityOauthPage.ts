// HilosSecurityOauthPage — the framework Hilos OAuth providers page
// (HilosPages.SECURITY_OAUTH, HIL-286): the providers table inside the admin shell,
// with the one return address every provider redirects back to above it. One row per
// provider the project declares (built from its provider directory, not a hardcoded
// list): whether it can sign anyone in, where its client id comes from, whether a
// secret is in force, and a link to its configuration page. The tables, the row
// view-models and the round-trips are the core headless's
// (createHilosOAuthProvidersTable / createHilosOAuthRedirect /
// createHilosSecurityOauthActions); this view owns only the markup, so a project
// mounts it by passing its HilosSecurityOauthContext. The context arrives via input
// and carries core signals, so an effect binds both tables once it is bound and
// mirrors the return-address row into an Angular signal. The address is edited in a
// modal — inline forms are forbidden (rules-and-violations.md section E) — as a
// tracked action: it redraws from the reactive table after the backend echo, never
// optimistically, and a refusal surfaces with the backend's domain phrase. The
// modal holds the address row in focus and merges against it through the shared
// row-edit helper (rowEdit.ts, conflict-resolution.md), saying what happened
// elsewhere on one line of room held in advance (HilosEditNotice). The ↺ resets
// the address to env only through a confirm dialog built like the settings orphan
// delete, holding the same row in focus. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HilosPages,
  createHilosOAuthProvidersTable,
  createHilosOAuthRedirect,
  createHilosSecurityOauthActions,
  resolveHilosPath,
  createHilosOauthRedirectEdit,
  hilosRowEditIdle,
} from '@hilos/core'
import type {
  HilosOauthRedirectEditFields,
  HilosOauthRedirectEditForm,
  HilosOAuthProviderRow,
  HilosOAuthRedirectRow,
  HilosSecurityOauthContext,
  OAuthValueSource,
  TableViewportRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The source badge label: where a value comes from. */
const SOURCE_LABEL: Record<OAuthValueSource, string> = {
  db: 'Set in admin',
  env: 'From env',
  default: 'Default',
}

/** The framework OAuth providers page: the return address and the providers table. */
@Component({
  selector: 'hilos-security-oauth-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
    HilosLink,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <section class="card mb-4" aria-labelledby="hilos-oauth-redirect-heading">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
              <h2 id="hilos-oauth-redirect-heading" class="h6 mb-1">
                Return address
              </h2>
              <p class="small text-body-secondary mb-2">
                Where every provider sends the browser back after sign-in.
                Register this address with each provider.
              </p>
              @if (redirectRow()?.setState) {
                <code data-id="hilos-oauth-redirect-value">
                  <hilos-hideable [value]="redirectRow()!.value" />
                </code>
              } @else {
                <span
                  class="text-body-secondary fst-italic"
                  data-id="hilos-oauth-redirect-value"
                >
                  Not set
                </span>
              }
              @if (redirectRow(); as row) {
                <span
                  class="badge text-bg-secondary-subtle text-secondary-emphasis ms-2"
                >
                  {{ sourceLabel(row.source) }}
                </span>
              }
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                title="Edit"
                aria-label="Edit return address"
                data-id="hilos-oauth-redirect-edit"
                (click)="openEdit()"
              >
                <i class="bi bi-pencil" aria-hidden="true"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                title="Reset return address to env"
                aria-label="Reset return address to env"
                [disabled]="redirectRow()?.source !== 'db'"
                data-id="hilos-oauth-redirect-reset"
                (click)="openReset()"
              >
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
              </button>
            </div>
          </div>
        </div>
      </section>

      <hilos-viewport-table [controller]="providers().controller">
        <ng-template hilosTableCell="label" let-row>
          <div class="fw-semibold">{{ row.label }}</div>
          <code class="small text-body-secondary">{{ row.providerKey }}</code>
        </ng-template>
        <ng-template hilosTableCell="configured" let-row>
          @if (row.configured) {
            <span class="badge text-bg-success-subtle text-success-emphasis">
              Configured
            </span>
          } @else {
            <span
              class="badge text-bg-warning-subtle text-warning-emphasis"
              [attr.title]="row.missingFields + ' required field(s) not set'"
            >
              {{ row.missingFields }} missing
            </span>
          }
        </ng-template>
        <ng-template hilosTableCell="clientIdSource" let-row>
          <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
            {{ sourceLabel(row.clientIdSource) }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="secretSet" let-row>
          <span class="text-body-secondary fst-italic">
            {{ row.secretSet ? 'Set' : 'Not set' }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          <a
            hilosLink
            [hilosLink]="providerPath(row)"
            class="btn btn-sm btn-outline-primary"
            [attr.aria-label]="'Configure ' + row.label"
            [attr.data-id]="'hilos-oauth-provider-open-' + row.providerKey"
          >
            Configure
          </a>
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="$event ? undefined : closeEdit()"
        [confirmOnClose]="live().dirty"
        [aria-label]="'Edit · Return address'"
      >
        <h5
          hilosConflictHeader
          modalHeader
          title="Edit · Return address"
          [conflict]="live().conflict"
        ></h5>
        <hilos-action-error [action]="edit" detailsTitle="Couldn't save" />
        <form (submit)="submitEdit($event)">
          @if (editHidden()) {
            <div class="form-label">Return address</div>
            <hilos-hidden-mark />
          } @else {
            <label class="form-label" for="hilos-oauth-redirect-input">
              Return address
            </label>
            <input
              id="hilos-oauth-redirect-input"
              type="url"
              class="form-control"
              placeholder="https://app.example/auth/callback"
              data-id="hilos-oauth-redirect-input"
              data-autofocus
              [value]="editValue()"
              (input)="onValueInput($event)"
            />
          }
          <hilos-edit-notice
            [kind]="editNotice()"
            [text]="editNoticeText()"
            dataId="hilos-oauth-redirect-edit-notice"
          />
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!canSave()"
            [saveLabel]="editSaveLabel()"
            (save)="submitEdit()"
            (acceptMine)="acceptMine()"
            (acceptTheirs)="acceptTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="edit.busy()"
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
                [loading]="edit.loading()"
                [disabled]="disabled"
                data-id="hilos-oauth-redirect-save"
                (click)="onSave()"
              >
                {{ editSaveLabel() }}
              </button>
            </ng-template>
          </div>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="resetOpen()"
        (openChange)="$event ? resetOpen.set(true) : closeReset()"
        title="Reset · Return address"
        [closeOnBackdrop]="!reset.busy()"
        [closeOnEsc]="!reset.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error
          [action]="reset"
          detailsTitle="Couldn't reset the return address"
        />
        @if (resetShown(); as row) {
          <dl class="row mb-0">
            <dt class="col-4">Now</dt>
            <dd
              class="col-8 text-break"
              data-id="hilos-oauth-redirect-reset-now"
            >
              @if (row.setState) {
                <hilos-hideable [value]="row.value" />
              } @else {
                Not set
              }
            </dd>
            <dt class="col-4">Back to</dt>
            <dd class="col-8" data-id="hilos-oauth-redirect-reset-default">
              the env value — empty when env has none
            </dd>
          </dl>
        }
        @if (resetGone()) {
          <p
            class="mb-0 mt-2 text-body-secondary"
            data-id="hilos-oauth-redirect-reset-gone"
          >
            Already reset elsewhere.
          </p>
        }
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
            [loading]="reset.loading()"
            [disabled]="reset.busy() || resetGone()"
            data-id="hilos-oauth-redirect-reset-confirm"
            (click)="submitReset()"
          >
            Reset
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosSecurityOauthPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosSecurityOauthContext>()

  protected readonly page = HilosPages.SECURITY_OAUTH

  protected readonly providers = computed(() =>
    createHilosOAuthProvidersTable(this.context()),
  )
  private readonly redirect = computed(() =>
    createHilosOAuthRedirect(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSecurityOauthActions(this.context()),
  )

  // The return-address table's rows, mirrored from its core controller: the handle
  // arrives through a computed, so hilosSignal cannot take it at field init.
  private readonly redirectRows = signal<
    readonly TableViewportRow<HilosOAuthRedirectRow>[]
  >([])
  /** The one return-address row, once its window has arrived. */
  protected readonly redirectRow = computed(
    () => this.redirectRows()[0]?.row ?? null,
  )

  // Edit dialog: the return address, as the core window has it; this view binds
  // the input through mirrors of the session's signals.
  protected readonly editor = computed(() =>
    createHilosOauthRedirectEdit(this.redirect().controller, this.actions()),
  )
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosOAuthRedirectRow | null>(null)
  protected readonly editForm = signal<HilosOauthRedirectEditForm>({
    hidden: false,
    text: '',
  })
  protected readonly live = signal(
    hilosRowEditIdle<HilosOauthRedirectEditFields>({ value: '' }),
  )
  protected readonly editNoticeText = signal('')
  protected readonly editSaveLabel = signal('Save')
  protected readonly canSave = signal(false)
  protected readonly edit = createHilosTrackedAction()
  protected readonly editHidden = computed(() => this.editForm().hidden)
  protected readonly editValue = computed(() => this.editForm().text)
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  // The live row the open reset dialog is about: the row the table holds in
  // focus, which the server follows wherever it goes; undefined once the row is
  // gone. Mirrored the way the rows are.
  protected readonly liveRow = signal<HilosOAuthRedirectRow | undefined>(
    undefined,
  )

  // Reset dialog: the address back to env, only on confirm. It reads the same live
  // row the edit dialog does — one dialog is open at a time, and the focus is one.
  protected readonly resetOpen = signal(false)
  protected readonly resetRow = signal<HilosOAuthRedirectRow | null>(null)
  protected readonly reset = createHilosTrackedAction()
  protected readonly resetShown = computed(
    () => this.liveRow() ?? this.resetRow(),
  )
  protected readonly resetGone = computed(() => {
    const row = this.liveRow()

    return row === undefined || row.source !== 'db'
  })

  constructor() {
    // Bind both server-windowed tables to the connection and request their first
    // windows once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const providers = this.providers()
      const redirect = this.redirect()
      const editor = this.editor()
      providers.start()
      redirect.start()
      editor.start()
      const off = [
        mirrorHilosSignal(redirect.controller.rows, this.redirectRows),
        mirrorHilosSignal(redirect.controller.focusedRow, this.liveRow),
        mirrorHilosSignal(editor.opened, this.editOpen),
        mirrorHilosSignal(editor.row, this.editRow),
        mirrorHilosSignal(editor.form, this.editForm),
        mirrorHilosSignal(editor.state, this.live),
        mirrorHilosSignal(editor.noticeText, this.editNoticeText),
        mirrorHilosSignal(editor.saveLabel, this.editSaveLabel),
        mirrorHilosSignal(editor.canSave, this.canSave),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        editor.dispose()
        providers.dispose()
        redirect.dispose()
      })
    })
  }

  /**
   * The words for where a value comes from.
   *
   * @param source Where the value comes from.
   * @returns The source badge label.
   */
  protected sourceLabel(source: OAuthValueSource): string {
    return SOURCE_LABEL[source]
  }

  /** The provider's configuration page path (its {providerId} route param is the key). */
  protected providerPath(row: HilosOAuthProviderRow): string {
    return resolveHilosPath(HilosPages.SECURITY_OAUTH_PROVIDER, {
      providerId: row.providerKey,
    })
  }

  protected openEdit(): void {
    const row = this.redirectRow()
    if (!row) {
      return
    }
    // The window takes the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    this.edit.clearError()
    this.editor().open(row.key)
  }

  protected closeEdit(): void {
    this.editor().close()
  }

  protected acceptMine(): void {
    this.editor().keepMine()
  }

  protected acceptTheirs(): void {
    this.editor().takeTheirs()
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with the entered value.
  protected submitEdit(event?: Event): void {
    event?.preventDefault()
    void this.editor().save(this.edit.run)
  }

  protected openReset(): void {
    const row = this.redirectRow()
    if (!row) {
      return
    }
    // Flush pending and take the row into focus; a row that is gone declines to
    // open.
    const fresh = this.redirect().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.reset.clearError()
    this.resetRow.set(fresh)
    this.resetOpen.set(true)
  }

  protected closeReset(): void {
    this.resetOpen.set(false)
    this.redirect().controller.releaseFocus()
  }

  protected async submitReset(): Promise<void> {
    if (!this.resetRow() || this.reset.busy() || this.resetGone()) {
      return
    }
    if (await this.reset.run(this.actions().sendRedirectReset())) {
      this.closeReset()
    }
  }

  protected onValueInput(event: Event): void {
    this.editor().patchForm({ text: (event.target as HTMLInputElement).value })
  }
}
