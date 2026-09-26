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
// optimistically, and a refusal surfaces with the backend's domain phrase.
// Bootstrap classes only (styling-rules.md).
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
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosOAuthProviderRow,
  HilosOAuthRedirectRow,
  HilosSecurityOauthContext,
  OAuthValueSource,
  TableViewportRow,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
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
    HilosLink,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
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
                  {{ redirectRow()?.value }}
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
                [disabled]="redirectRow()?.source !== 'db' || reset.busy()"
                data-id="hilos-oauth-redirect-reset"
                (click)="resetRedirect()"
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
        (openChange)="editOpen.set($event)"
        [title]="'Edit · Return address'"
      >
        <hilos-action-error [action]="edit" detailsTitle="Couldn't save" />
        <form (submit)="submitEdit($event)">
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
        </form>
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
            data-id="hilos-oauth-redirect-save"
            (click)="submitEdit()"
          >
            Save
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

  protected readonly reset = createHilosTrackedAction()

  // Edit dialog: the return address.
  protected readonly editOpen = signal(false)
  protected readonly editValue = signal('')
  protected readonly edit = createHilosTrackedAction()

  constructor() {
    // Bind both server-windowed tables to the connection and request their first
    // windows once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const providers = this.providers()
      const redirect = this.redirect()
      providers.start()
      redirect.start()
      this.redirectRows.set(redirect.controller.rows.get())
      const unsubscribe = subscribeSignal(redirect.controller.rows, (rows) =>
        this.redirectRows.set(rows),
      )
      onCleanup(() => {
        unsubscribe()
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

  protected resetRedirect(): void {
    void this.reset.run(this.actions().sendRedirectReset())
  }

  protected openEdit(): void {
    this.edit.clearError()
    this.editValue.set(this.redirectRow()?.value ?? '')
    this.editOpen.set(true)
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    if (this.edit.busy()) {
      return
    }
    if (await this.edit.run(this.actions().sendRedirectSet(this.editValue()))) {
      this.editOpen.set(false)
    }
  }

  protected onValueInput(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).value)
  }
}
