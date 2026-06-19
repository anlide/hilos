// HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
// user's profile, presence, and inline rename, inside the admin shell. The detail
// selector and the rename action are the core headless's
// (createHilosUserDetail / createHilosUserRename); this view owns only the markup,
// so a project mounts it by passing its HilosUsersContext. Success is state-driven
// (the committed name reaches the draft over the live table); a failure surfaces
// from the backend fail ack. The context arrives via input and carries core
// signals, so — like HilosTable — an effect builds the selectors once it binds and
// mirrors them into Angular signals. Bootstrap classes only (styling-rules.md).
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
  createHilosUserDetail,
  createHilosUserRename,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosUserRename,
  HilosUserRow,
  HilosUsersContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { LoadingButton } from '../../LoadingButton.js'

/** The framework user-detail admin page: profile, presence, and inline rename. */
@Component({
  selector: 'hilos-user-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, LoadingButton],
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

            @if (editing()) {
              <form class="mt-3" (submit)="submit($event)">
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
                  [value]="draft()"
                  (input)="onDraftInput($event)"
                />
                <div class="form-text">
                  Between {{ nameMin }} and {{ nameMax }} characters.
                </div>
                @if (renameError(); as error) {
                  <div
                    class="alert alert-danger mt-2 mb-0"
                    data-id="hilos-user-rename-error"
                  >
                    {{ error }}
                  </div>
                }
                <div class="d-flex gap-2 mt-3">
                  <button
                    hilosLoadingButton
                    class="btn-primary"
                    type="submit"
                    [loading]="loading()"
                    [disabled]="!valid()"
                    data-id="hilos-user-save"
                  >
                    Save
                  </button>
                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-id="hilos-user-cancel"
                    (click)="cancelEdit()"
                  >
                    Cancel
                  </button>
                </div>
              </form>
            }
          </div>
        </div>
      } @else {
        <p class="text-body-secondary" data-id="hilos-user-empty">
          Loading user…
        </p>
      }
    </hilos-admin-page>
  `,
})
export class HilosUserPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USER
  protected readonly nameMin = 2
  protected readonly nameMax = 64

  // Mirrored from the core selectors, which derive from the context input.
  protected readonly detail = signal<HilosUserRow | undefined>(undefined)
  protected readonly renameError = signal<string | null>(null)
  private rename: HilosUserRename | undefined

  protected readonly editing = signal(false)
  protected readonly draft = signal('')
  protected readonly loading = signal(false)
  protected readonly valid = computed(() => {
    const trimmed = this.draft().trim()

    return trimmed.length >= this.nameMin && trimmed.length <= this.nameMax
  })

  constructor() {
    // The context arrives via input and carries core signals; build the detail
    // selector and rename surface once it binds, mirror their signals into
    // Angular, and drop the subscriptions if the context is replaced.
    effect((onCleanup) => {
      const context = this.context()
      const detailSignal = createHilosUserDetail(context)
      const rename = createHilosUserRename(context)
      this.rename = rename
      this.detail.set(detailSignal.get())
      this.renameError.set(rename.renameError.get())
      const subscriptions = [
        subscribeSignal(detailSignal, (value) => this.detail.set(value)),
        subscribeSignal(rename.renameError, (value) =>
          this.renameError.set(value),
        ),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })

    // Success is state-driven: the rename has landed once the committed name (over
    // the live table) reaches the submitted draft. Track only the name; read the
    // form state untracked so the effect mirrors the Vue watch-on-name.
    effect(() => {
      const name = this.detail()?.name
      untracked(() => {
        if (this.loading() && name === this.draft().trim()) {
          this.loading.set(false)
          this.editing.set(false)
        }
      })
    })

    // A rejected rename releases the button and keeps the form open to retry.
    effect(() => {
      if (this.renameError() !== null) {
        this.loading.set(false)
      }
    })
  }

  protected openEdit(): void {
    this.rename?.clearRenameError()
    this.draft.set(this.detail()?.name ?? '')
    this.loading.set(false)
    this.editing.set(true)
  }

  protected cancelEdit(): void {
    this.editing.set(false)
    this.loading.set(false)
    this.rename?.clearRenameError()
  }

  protected submit(event?: Event): void {
    event?.preventDefault()
    const current = this.detail()
    if (!current || !this.valid() || this.loading()) {
      return
    }
    // No change: close without a round-trip (also keeps the state-driven success
    // watch from waiting on a name that will never change).
    if (this.draft().trim() === current.name) {
      this.editing.set(false)

      return
    }

    this.loading.set(
      this.rename?.submitRename(current.id, this.draft().trim()) ?? false,
    )
  }

  protected onDraftInput(event: Event): void {
    this.draft.set((event.target as HTMLInputElement).value)
  }
}
