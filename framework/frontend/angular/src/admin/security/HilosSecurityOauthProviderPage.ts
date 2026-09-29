// HilosSecurityOauthProviderPage — the framework Hilos OAuth provider page
// (HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): one provider's fields inside the
// admin shell. The route {providerId} names the provider; the fields and providers
// tables are global, so the core headless presets their provider filter from the
// route and the server narrows both windows to it (createHilosOAuthProviderFields /
// createHilosOAuthProviderSummary). A provider the project does not declare narrows
// them to nothing, and the page says "provider not found". Each field shows its
// effective value and where it comes from and can be edited or reset to env / the
// recipe; the client secret is write-only — shown as set / not set, never read back,
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
  untracked,
} from '@angular/core'
import {
  HilosPages,
  computedSignal,
  createHilosOAuthProviderFields,
  createHilosOAuthProviderSummary,
  createHilosSecurityOauthActions,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  subscribeSignal,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosOAuthFieldRow,
  HilosOAuthProviderRow,
  HilosSecurityOauthContext,
  OAuthValueSource,
  RowEditBaseline,
  RowEditNoticeKind,
  RowEditStep,
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
import { hilosSignal } from '../../hilosSignal.js'
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

/** The one field the dialog edits; the secret reads as empty. */
interface FieldEditFields {
  value: string
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosOAuthFieldRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveRow ? `Changed elsewhere to "${displayValue(liveRow)}".` : ''
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
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
              [disabled]="row.source !== 'db' || reset.busy()"
              [attr.data-id]="'hilos-oauth-field-reset-' + row.field"
              (click)="resetField(row)"
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
        (openChange)="$event ? editOpen.set(true) : closeEdit()"
        [confirmOnClose]="live().dirty"
        [ariaLabel]="editTitle()"
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
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="edit.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!live().dirty || edit.busy() || live().gone"
            [saveLabel]="editSaveLabel()"
            (save)="submitEdit()"
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

  protected readonly reset = createHilosTrackedAction()

  // Edit dialog: one field of the provider. The secret's dialog always opens empty.
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosOAuthFieldRow | null>(null)
  protected readonly editValue = signal('')
  protected readonly editBaseline = signal<RowEditBaseline<FieldEditFields>>(
    openRowEdit<FieldEditFields>({ value: '' }),
  )
  protected readonly edit = createHilosTrackedAction()
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row
      ? `${row.secret ? 'Replace' : 'Edit'} · ${row.label}`
      : 'Edit field'
  })
  // The live row the open dialog is about: the row the table holds in focus, which
  // the server follows wherever it goes; undefined once the row is gone. Mirrored
  // the way the summary rows are.
  protected readonly liveRow = signal<HilosOAuthFieldRow | undefined>(undefined)
  protected readonly live = computed(() => {
    const row = this.liveRow()

    // An empty value reads as '' the way the input shows it.
    return resolveRowEdit(
      row ? { value: row.value ?? '' } : undefined,
      this.editBaseline(),
      { value: this.editValue() },
    )
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editNoticeText = computed(() =>
    noticeText(this.editNotice(), this.liveRow()),
  )
  protected readonly editSaveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )

  constructor() {
    // Bind both tables to the connection and request their windows once the
    // context input is bound; unbind on destroy / swap.
    effect((onCleanup) => {
      const summary = this.summary()
      const fields = this.fields()
      summary.start()
      fields.start()
      this.summaryRows.set(summary.controller.rows.get())
      this.summaryLoaded.set(summary.controller.loaded.get())
      this.liveRow.set(fields.controller.focusedRow.get())
      const unsubscribes = [
        subscribeSignal(fields.controller.focusedRow, (row) => {
          this.liveRow.set(row)
        }),
        subscribeSignal(summary.controller.rows, (next) => {
          this.summaryRows.set(next)
        }),
        subscribeSignal(summary.controller.loaded, (next) => {
          this.summaryLoaded.set(next)
        }),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
        summary.dispose()
        fields.dispose()
      })
    })
    // The helper hands a step whenever the other side moved the value while the
    // person left it alone, or both arrived at the same one; the dialog applies
    // it at once.
    effect(() => {
      const settle = this.live().settle
      const open = this.editOpen()
      untracked(() => {
        if (open && settle) {
          this.applyStep(settle)
        }
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

  protected resetField(row: HilosOAuthFieldRow): void {
    void this.reset.run(
      this.actions().sendProviderReset(row.providerKey, row.field),
    )
  }

  protected openEdit(row: HilosOAuthFieldRow): void {
    // Flush pending and take the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    const fresh = this.fields().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.edit.clearError()
    this.editRow.set(fresh)
    // The secret never reads back: its value is null and its dialog opens empty.
    const value = fresh.value ?? ''
    this.editValue.set(value)
    this.editBaseline.set(openRowEdit<FieldEditFields>({ value }))
    this.editOpen.set(true)
  }

  protected closeEdit(): void {
    this.editOpen.set(false)
    // The secret typed into the dialog is not kept once it is closed.
    this.editValue.set('')
    this.fields().controller.releaseFocus()
  }

  // Put a step of the helper into the dialog: the snapshot moves, and a value
  // the step takes lands in the input.
  private applyStep(step: RowEditStep<FieldEditFields>): void {
    this.editBaseline.set(step.baseline)
    if (step.take.value !== undefined) {
      this.editValue.set(step.take.value)
    }
  }

  protected acceptMine(): void {
    this.editBaseline.set(keepMineRowEdit(this.live(), this.editBaseline()))
  }

  protected acceptTheirs(): void {
    this.applyStep(takeTheirsRowEdit(this.live(), this.editBaseline()))
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy() || this.live().gone || this.live().conflict) {
      return
    }
    // Nothing to save (for the secret: nothing typed) closes without a request.
    if (!this.live().dirty) {
      this.closeEdit()

      return
    }
    if (
      await this.edit.run(
        this.actions().sendProviderSet(
          row.providerKey,
          row.field,
          this.editValue(),
        ),
      )
    ) {
      this.closeEdit()
    }
  }

  protected onValueInput(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).value)
  }
}
