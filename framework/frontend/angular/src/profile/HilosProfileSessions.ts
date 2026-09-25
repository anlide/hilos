import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  signal,
} from '@angular/core'
import {
  endableProfileSessionCount,
  formatProfileDateTime,
  type HilosProfileSession as ProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

/** The shared session list, tab disclosure, and end-session controls. */
@Component({
  selector: 'hilos-profile-sessions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosModal, LoadingButton],
  template: `
    <section aria-label="Sessions" data-id="profile-sessions">
      <p>
        A session is a browser that signed in. Its open tabs are listed inside
        it. Ending a session takes effect at once.
      </p>
      <div class="alert alert-info small" role="note">
        The list of tabs is what is happening right now and can lag behind in a
        cluster. The list of sessions cannot: it comes from the database.
      </div>
      @if (sessions().length === 0) {
        <div class="text-body-secondary">No sessions.</div>
      } @else {
        <div class="d-flex flex-column gap-3">
          @for (session of sessions(); track session.id) {
            <article class="card" data-id="profile-session-row">
              <div class="card-body">
                <div
                  class="d-flex flex-wrap align-items-start justify-content-between gap-2"
                >
                  <div>
                    <h3 class="h6 mb-1">Session #{{ session.id }}</h3>
                    <div data-id="profile-session-device">
                      {{ session.deviceName ?? 'Unknown device' }}
                    </div>
                    @if (session.current) {
                      <span
                        class="badge text-bg-primary me-1"
                        data-id="profile-session-this"
                        >this one</span
                      >
                    }
                    @if (session.impersonated) {
                      <span
                        class="badge text-bg-warning"
                        data-id="profile-session-impersonated"
                        >an administrator is working as you</span
                      >
                    }
                  </div>
                  @if (!session.current && !session.impersonated) {
                    <button
                      type="button"
                      class="btn btn-outline-danger btn-sm"
                      data-id="profile-session-revoke"
                      (click)="openEnd(session.id)"
                    >
                      Revoke
                    </button>
                  }
                </div>
                <div class="small text-body-secondary mt-2">
                  created {{ date(session.createdAt) }} · last seen
                  {{
                    session.tabs.length > 0 ? 'now' : date(session.lastSeenAt)
                  }}
                  · expires {{ date(session.expiresAt) }}
                </div>
                <button
                  type="button"
                  class="btn btn-link btn-sm px-0 mt-2"
                  [attr.aria-expanded]="expanded().has(session.id)"
                  data-id="profile-session-tabs"
                  (click)="toggleTabs(session.id)"
                >
                  tabs: {{ session.tabs.length }}
                </button>
                @if (expanded().has(session.id)) {
                  <div class="mt-2">
                    @if (session.tabs.length === 0) {
                      <p class="text-body-secondary small mb-0">
                        No open tabs: the sign-in stays valid, but the browser
                        is closed right now.
                      </p>
                    } @else {
                      <ul class="list-group list-group-flush">
                        @for (tab of session.tabs; track tab.acceptKey) {
                          <li
                            class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2"
                            data-id="profile-session-tab"
                          >
                            <code>{{ shortKey(tab.acceptKey) }}</code>
                            <span class="small text-body-secondary">
                              opened {{ tabDate(tab.connectedAt) }}
                              @if (tab.current) {
                                <span
                                  class="badge text-bg-primary ms-1"
                                  data-id="profile-session-tab-this"
                                  >this tab</span
                                >
                              }
                            </span>
                          </li>
                        }
                      </ul>
                    }
                  </div>
                }
              </div>
            </article>
          }
        </div>
      }
      <button
        hilosLoadingButton
        class="btn-outline-danger mt-3"
        [loading]="endOthersAction.loading()"
        [disabled]="endableCount() === 0 || endOthersAction.busy()"
        data-id="profile-sessions-end-others"
        (click)="endOthers()"
      >
        Sign out everywhere else
      </button>

      <hilos-modal
        [open]="endingSessionId() !== null"
        (openChange)="$event ? null : endingSessionId.set(null)"
        [title]="endTitle()"
        initialFocus="dialog"
      >
        <p>
          Tabs of this session lose the account at once: where an account is
          needed, the sign-in form takes the place of the content.
        </p>
        <p>Your other sessions are not touched.</p>
        <hilos-form-error
          [message]="endError()"
          dataId="profile-session-end-error"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="endAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="endAction.loading()"
            [disabled]="selectedSession() === undefined || endAction.busy()"
            data-id="profile-session-end-confirm"
            (click)="confirmEnd()"
          >
            End
          </button>
        </ng-template>
      </hilos-modal>
    </section>
  `,
})
export class HilosProfileSessions {
  readonly sessions = input.required<readonly ProfileSession[]>()
  readonly actions = input.required<HilosProfileSessionActions>()

  protected readonly expanded = signal<ReadonlySet<number>>(new Set())
  protected readonly endingSessionId = signal<number | null>(null)
  protected readonly endAction = createHilosTrackedAction()
  protected readonly endOthersAction = createHilosTrackedAction()
  protected readonly selectedSession = computed(() =>
    this.sessions().find((session) => session.id === this.endingSessionId()),
  )
  protected readonly endableCount = computed(() =>
    endableProfileSessionCount(this.sessions()),
  )
  protected readonly endError = computed(() =>
    this.selectedSession() === undefined
      ? 'This session has already ended'
      : this.endAction.error(),
  )
  protected readonly endTitle = computed(
    () => `End session #${this.endingSessionId() ?? ''}`,
  )

  protected date(value: string | null): string {
    return formatProfileDateTime(value)
  }

  protected tabDate(connectedAt: number): string {
    return formatProfileDateTime(connectedAt * 1000)
  }

  protected shortKey(acceptKey: string): string {
    return acceptKey.slice(0, 8)
  }

  protected toggleTabs(sessionId: number): void {
    const next = new Set(this.expanded())
    if (next.has(sessionId)) {
      next.delete(sessionId)
    } else {
      next.add(sessionId)
    }
    this.expanded.set(next)
  }

  protected openEnd(sessionId: number): void {
    this.endAction.clearError()
    this.endingSessionId.set(sessionId)
  }

  protected async confirmEnd(): Promise<void> {
    const session = this.selectedSession()
    if (session !== undefined) {
      if (await this.endAction.run(this.actions().endSession(session.id))) {
        this.endingSessionId.set(null)
      }
    }
  }

  protected async endOthers(): Promise<void> {
    if (this.endableCount() > 0) {
      await this.endOthersAction.run(this.actions().endOtherSessions())
    }
  }
}
