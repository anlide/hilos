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
// (rules-and-violations.md section E). Bootstrap classes only (styling-rules.md).
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
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosOAuthFieldRow,
  HilosOAuthProviderRow,
  HilosSecurityOauthContext,
  OAuthValueSource,
  TableViewportRow,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
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

/** The framework OAuth provider page: one provider's fields table and its recipe. */
@Component({
  selector: 'hilos-security-oauth-provider-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
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
        (openChange)="editOpen.set($event)"
        [title]="editTitle()"
        (cancel)="closeEdit()"
      >
        <hilos-action-error [action]="edit" />
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
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="edit.loading()"
            [disabled]="edit.busy()"
            data-id="hilos-oauth-field-save"
            (click)="submitEdit()"
          >
            Save
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

  protected readonly reset = createHilosTrackedAction()

  // Edit dialog: one field of the provider. The secret's dialog always opens empty.
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosOAuthFieldRow | null>(null)
  protected readonly editValue = signal('')
  protected readonly edit = createHilosTrackedAction()
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row
      ? `${row.secret ? 'Replace' : 'Edit'} · ${row.label}`
      : 'Edit field'
  })

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
      const unsubscribes = [
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
    return row.value === null || row.value === '' ? '—' : row.value
  }

  protected resetField(row: HilosOAuthFieldRow): void {
    void this.reset.run(
      this.actions().sendProviderReset(row.providerKey, row.field),
    )
  }

  protected openEdit(row: HilosOAuthFieldRow): void {
    this.edit.clearError()
    this.editRow.set(row)
    this.editValue.set(row.secret ? '' : (row.value ?? ''))
    this.editOpen.set(true)
  }

  protected closeEdit(): void {
    this.editOpen.set(false)
    // The secret typed into the dialog is not kept once it is closed.
    this.editValue.set('')
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy()) {
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
