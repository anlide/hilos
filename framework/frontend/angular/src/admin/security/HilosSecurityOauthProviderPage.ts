// HilosSecurityOauthProviderPage — the framework Hilos OAuth provider page
// (HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): one provider's fields inside the
// admin shell. The route {providerId} names the provider; the fields and providers
// tables are global, so the core headless presets their provider filter from the
// route and the server narrows both windows to it (createHilosOAuthProviderFields /
// createHilosOAuthProviderSummary). A provider the project does not declare narrows
// them to nothing, and the page says "provider not found". Each field shows its
// effective value and where it comes from and can be edited or reset to env / the
// recipe — the ↺ asks first, in a confirm dialog built like the settings orphan
// delete; the client secret is write-only — shown as set / not set, never read back,
// and its dialog always opens empty: it replaces, it does not show. The provider's
// recipe is shown as reference, without actions. The context arrives via input and
// carries core signals, so an effect binds both tables once it is bound and mirrors
// the provider's own row into Angular signals. Writes are tracked actions
// (createHilosSecurityOauthActions): the value redraws from the reactive table after
// the backend echo, never optimistically, and a refusal surfaces with the backend's
// domain phrase. Editing happens in a modal — inline forms are forbidden
// (rules-and-violations.md section E) — and the modal holds its row in focus and
// merges against it through the shared row-edit helper (rowEdit.ts,
// conflict-resolution.md), saying what happened elsewhere on one line of room
// held in advance (HilosEditNotice). The secret never reads back, so its live
// value is always empty: there is nothing to merge, and the dialog only says when
// the row is gone. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  HilosPages,
  computedSignal,
  createHilosOAuthProviderFields,
  createHilosOAuthProviderSummary,
  createHilosSecurityOauthActions,
  createHilosOauthProviderFieldEdit,
  hilosRowEditIdle,
} from '@hilos/core'
import type {
  HilosOauthProviderFieldEditFields,
  HilosOAuthFieldRow,
  HilosOAuthProviderRow,
  HilosSecurityOauthContext,
  OAuthValueSource,
  TableViewportRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal, mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The source badge label: where a value comes from. */
const SOURCE_LABEL: Record<OAuthValueSource, string> = {
  db: 'Set in admin',
  env: 'From env',
  default: 'Default',
}

/** Human-readable effective value of a field that is not the secret. */
function displayValue(row: HilosOAuthFieldRow): string {
  return row.value === null || row.value === '' ? '—' : row.value
}

/** What the reset dialog shows as the value now; the secret never reads back. */
function resetNowText(row: HilosOAuthFieldRow): string {
  if (row.secret) {
    return row.setState ? 'Set' : 'Not set'
  }

  return displayValue(row)
}

/** The framework OAuth provider page: one provider's fields table and its recipe. */
@Component({
  selector: 'hilos-security-oauth-provider-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @if (notFound()) {
        <div
          class="alert alert-warning"
          role="status"
          data-id="hilos-oauth-provider-not-found"
        >
          Provider not found. This application declares no OAuth provider
          <code>{{ providerKey() }}</code
          >.
        </div>
      } @else {
        <p class="text-body-secondary mb-3">
          <span class="fw-semibold text-body">{{ provider()?.label }}</span>
          <code class="ms-2 small">{{ providerKey() }}</code>
        </p>

        <hilos-viewport-table [controller]="fields().controller">
          <ng-template hilosTableCell="field" let-row>
            <div class="fw-semibold">{{ row.label }}</div>
            <code class="small text-body-secondary">{{ row.field }}</code>
          </ng-template>
          <ng-template hilosTableCell="value" let-row>
            @if (row.secret) {
              <span
                class="text-body-secondary fst-italic"
                [attr.data-id]="'hilos-oauth-field-value-' + row.field"
              >
                {{ row.setState ? 'Set' : 'Not set' }}
              </span>
            } @else {
              <span [attr.data-id]="'hilos-oauth-field-value-' + row.field">
                {{ displayValue(row) }}
              </span>
            }
          </ng-template>
          <ng-template hilosTableCell="source" let-row>
            <span
              class="badge text-bg-secondary-subtle text-secondary-emphasis"
            >
              {{ sourceLabel(row.source) }}
            </span>
          </ng-template>
          <ng-template hilosTableCell="actions" let-row>
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              [title]="row.secret ? 'Replace' : 'Edit'"
              [attr.aria-label]="
                (row.secret ? 'Replace' : 'Edit') + ' ' + row.label
              "
              [attr.data-id]="'hilos-oauth-field-edit-' + row.field"
              (click)="openEdit(row)"
            >
              <i
                [class]="row.secret ? 'bi bi-key' : 'bi bi-pencil'"
                aria-hidden="true"
              ></i>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              [title]="'Reset ' + row.label + ' to env/default'"
              [attr.aria-label]="'Reset ' + row.label + ' to env/default'"
              [disabled]="row.source !== 'db'"
              [attr.data-id]="'hilos-oauth-field-reset-' + row.field"
              (click)="openReset(row)"
            >
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          </ng-template>
        </hilos-viewport-table>

        @if (provider(); as provider) {
          <section
            class="card mt-4"
            aria-labelledby="hilos-oauth-recipe-heading"
            data-id="hilos-oauth-recipe"
          >
            <div class="card-body">
              <h2 id="hilos-oauth-recipe-heading" class="h6 mb-1">Recipe</h2>
              <p class="small text-body-secondary mb-3">
                {{
                  provider.builtIn
                    ? 'Shipped by Hilos for this provider. It is not edited here.'
                    : 'Declared by this application for a provider Hilos ships no recipe for.'
                }}
              </p>
              <dl class="row small mb-0">
                <dt class="col-sm-4">Authorization endpoint</dt>
                <dd class="col-sm-8">
                  <code>{{ provider.authorizeUrl }}</code>
                </dd>
                <dt class="col-sm-4">Token endpoint</dt>
                <dd class="col-sm-8">
                  <code>{{ provider.tokenUrl }}</code>
                </dd>
                <dt class="col-sm-4">Userinfo endpoint</dt>
                <dd class="col-sm-8">
                  <code>{{ provider.userInfoUrl }}</code>
                </dd>
                <dt class="col-sm-4">Account id field</dt>
                <dd class="col-sm-8">
                  <code>{{ provider.subjectKey }}</code>
                </dd>
                <dt class="col-sm-4">Email field</dt>
                <dd class="col-sm-8">
                  <code>{{ provider.emailKey }}</code>
                </dd>
                <dt class="col-sm-4">Name field</dt>
                <dd class="col-sm-8 mb-0">
                  <code>{{ provider.nameKey }}</code>
                </dd>
              </dl>
            </div>
          </section>
        }
      }

      <hilos-modal
        [open]="editOpen()"
        (openChange)="$event ? undefined : closeEdit()"
        [confirmOnClose]="live().dirty"
        [aria-label]="editTitle()"
      >
        <h5
          hilosConflictHeader
          modalHeader
          [title]="editTitle()"
          [conflict]="live().conflict"
        ></h5>
        <hilos-action-error [action]="edit" detailsTitle="Couldn't save" />
        @if (editRow(); as row) {
          <form (submit)="submitEdit($event)">
            <label class="form-label" for="hilos-oauth-field-input">
              {{ row.label }}
            </label>
            <input
              id="hilos-oauth-field-input"
              [type]="row.secret ? 'password' : 'text'"
              [attr.autocomplete]="row.secret ? 'new-password' : 'off'"
              class="form-control"
              data-id="hilos-oauth-field-input"
              data-autofocus
              [value]="editValue()"
              (input)="onValueInput($event)"
            />
            @if (row.secret) {
              <p class="form-text mb-0">
                The current secret is never shown. What you enter replaces it.
              </p>
            }
            <hilos-edit-notice
              [kind]="editNotice()"
              [text]="editNoticeText()"
              dataId="hilos-oauth-field-edit-notice"
            />
          </form>
        }
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
                data-id="hilos-oauth-field-save"
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
        [title]="resetTitle()"
        [closeOnBackdrop]="!reset.busy()"
        [closeOnEsc]="!reset.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error
          [action]="reset"
          detailsTitle="Couldn't reset the field"
        />
        @if (resetShown(); as row) {
          <dl class="row mb-0">
            <dt class="col-4">Now</dt>
            <dd class="col-8 text-break" data-id="hilos-oauth-field-reset-now">
              {{ resetNowText(row) }}
            </dd>
            <dt class="col-4">Back to</dt>
            <dd class="col-8" data-id="hilos-oauth-field-reset-default">
              the env value, or the default when env has none
            </dd>
          </dl>
        }
        @if (resetStopsSignIn()) {
          @if (provider(); as owner) {
            <p
              class="mb-0 mt-2 text-body-secondary"
              data-id="hilos-oauth-field-reset-signin"
            >
              If env has none, sign-in with {{ owner.label }} stops being
              offered.
            </p>
          }
        }
        @if (resetGone()) {
          <p
            class="mb-0 mt-2 text-body-secondary"
            data-id="hilos-oauth-field-reset-gone"
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
            data-id="hilos-oauth-field-reset-confirm"
            (click)="submitReset()"
          >
            Reset
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosSecurityOauthProviderPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosSecurityOauthContext>()

  protected readonly page = HilosPages.SECURITY_OAUTH_PROVIDER

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  // The route provider, as a core signal both tables follow: navigating to another
  // provider sets their provider filter again and asks the server for its windows.
  private readonly providerSignal = computedSignal(() => {
    if (!this.router) {
      throw new Error(
        'HilosSecurityOauthProviderPage requires a provided router: { provide: HILOS_ROUTER, useValue: router }.',
      )
    }

    return (
      (this.router.currentRoute.get().params['providerId'] as
        | string
        | undefined) ?? ''
    )
  })
  protected readonly providerKey = hilosSignal(this.providerSignal)

  private readonly summary = computed(() =>
    createHilosOAuthProviderSummary(this.context(), this.providerSignal),
  )
  protected readonly fields = computed(() =>
    createHilosOAuthProviderFields(this.context(), this.providerSignal),
  )
  private readonly actions = computed(() =>
    createHilosSecurityOauthActions(this.context()),
  )

  // The route provider's own row and whether its window has arrived, mirrored from
  // the summary's core controller: the handle arrives through a computed, so
  // hilosSignal cannot take it at field init.
  private readonly summaryRows = signal<
    readonly TableViewportRow<HilosOAuthProviderRow>[]
  >([])
  private readonly summaryLoaded = signal(false)
  /** The route provider's own row, or null until it arrives (or when it is not declared). */
  protected readonly provider = computed(
    () => this.summaryRows()[0]?.row ?? null,
  )
  /** A provider the project does not declare: the window came back empty. */
  protected readonly notFound = computed(
    () => this.summaryLoaded() && this.summaryRows().length === 0,
  )

  // Edit dialog: one field of the provider, as the core window has it; this view
  // binds the input through mirrors of the session's signals. The secret's dialog
  // always opens empty and forgets what was typed when it closes.
  protected readonly editor = computed(() =>
    createHilosOauthProviderFieldEdit(this.fields().controller, this.actions()),
  )
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosOAuthFieldRow | null>(null)
  protected readonly editForm = signal<HilosOauthProviderFieldEditFields>({
    value: '',
  })
  protected readonly live = signal(
    hilosRowEditIdle<HilosOauthProviderFieldEditFields>({ value: '' }),
  )
  protected readonly editNoticeText = signal('')
  protected readonly editSaveLabel = signal('Save')
  protected readonly canSave = signal(false)
  protected readonly edit = createHilosTrackedAction()
  protected readonly editValue = computed(() => this.editForm().value)
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row
      ? `${row.secret ? 'Replace' : 'Edit'} · ${row.label}`
      : 'Edit field'
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  // The live row the open reset dialog is about: the row the table holds in
  // focus, which the server follows wherever it goes; undefined once the row is
  // gone. Mirrored the way the summary rows are.
  protected readonly liveRow = signal<HilosOAuthFieldRow | undefined>(undefined)

  // Reset dialog: one field back to env / the recipe, only on confirm. It reads
  // the same live row the edit dialog does — one dialog is open at a time, and the
  // focus is one.
  protected readonly resetOpen = signal(false)
  protected readonly resetRow = signal<HilosOAuthFieldRow | null>(null)
  protected readonly reset = createHilosTrackedAction()
  protected readonly resetShown = computed(
    () => this.liveRow() ?? this.resetRow(),
  )
  protected readonly resetGone = computed(() => {
    const row = this.liveRow()

    return row === undefined || row.source !== 'db'
  })
  protected readonly resetTitle = computed(() => {
    const row = this.resetRow()

    return row ? `Reset · ${row.label}` : 'Reset field'
  })
  // A provider without both its client id and its secret is not offered at
  // sign-in, so resetting either of them may take the provider off the sign-in page.
  protected readonly resetStopsSignIn = computed(() => {
    const field = this.resetRow()?.field

    return field === 'client_id' || field === 'client_secret'
  })

  constructor() {
    // Bind both tables to the connection and request their windows once the
    // context input is bound; unbind on destroy / swap.
    effect((onCleanup) => {
      const summary = this.summary()
      const fields = this.fields()
      const editor = this.editor()
      summary.start()
      fields.start()
      editor.start()
      const off = [
        mirrorHilosSignal(summary.controller.rows, this.summaryRows),
        mirrorHilosSignal(summary.controller.loaded, this.summaryLoaded),
        mirrorHilosSignal(fields.controller.focusedRow, this.liveRow),
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
        summary.dispose()
        fields.dispose()
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

  /** Human-readable effective value of a field that is not the secret. */
  protected displayValue(row: HilosOAuthFieldRow): string {
    return displayValue(row)
  }

  /** What the reset dialog shows as the value now; the secret never reads back. */
  protected resetNowText(row: HilosOAuthFieldRow): string {
    return resetNowText(row)
  }

  protected openReset(row: HilosOAuthFieldRow): void {
    // Flush pending and take the row into focus; a row that is gone declines to
    // open.
    const fresh = this.fields().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.reset.clearError()
    this.resetRow.set(fresh)
    this.resetOpen.set(true)
  }

  protected closeReset(): void {
    this.resetOpen.set(false)
    this.fields().controller.releaseFocus()
  }

  protected async submitReset(): Promise<void> {
    const row = this.resetRow()
    if (!row || this.reset.busy() || this.resetGone()) {
      return
    }
    if (
      await this.reset.run(
        this.actions().sendProviderReset(row.providerKey, row.field),
      )
    ) {
      this.closeReset()
    }
  }

  protected openEdit(row: HilosOAuthFieldRow): void {
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

  protected onValueInput(event: Event): void {
    this.editor().setForm({ value: (event.target as HTMLInputElement).value })
  }
}
