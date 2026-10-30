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
  hilosTimeZone,
  type HilosProfileSession as ProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core/base'

import { hilosSignal } from '../hilosSignal.js'
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
      <p class="small text-body-secondary">
        A session is a browser that signed in. Its open tabs are listed inside
        it. Ending a session takes effect at once.
      </p>
      @if (sessions().length === 0) {
        <div class="text-body-secondary">No sessions.</div>
      } @else {
        <div>
          @for (session of sessions(); track session.id) {
            <article class="border-bottom" data-id="profile-session-row">
              <div class="d-flex flex-wrap align-items-center gap-2 py-3">
                @if (session.impersonated) {
                  <i
                    class="bi bi-people-fill fs-5 text-warning-emphasis"
                    aria-hidden="true"
                  ></i>
                } @else {
                  <i
                    class="bi bi-window fs-5 text-body-secondary"
                    aria-hidden="true"
                  ></i>
                }
                <div class="flex-grow-1 text-break">
                  <h3 class="h6 mb-1">
                    Session #{{ session.id }}
                    @if (session.current) {
                      <span
                        class="badge text-bg-success ms-1"
                        data-id="profile-session-this"
                        >this one</span
                      >
                    }
                    @if (session.impersonated) {
                      <span
                        class="badge bg-warning-subtle text-warning-emphasis ms-1"
                        data-id="profile-session-impersonated"
                        >an administrator is working as you</span
                      >
                    }
                  </h3>
                  <div data-id="profile-session-device">
                    {{ session.deviceName ?? 'Unknown device' }}
                    @if (session.browserTimeZone !== null) {
                      <span class="text-body-secondary"
                        >· {{ session.browserTimeZone }}</span
                      >
                    }
                  </div>
                  <div class="small text-body-secondary">
                    created {{ date(session.createdAt) }} · last seen
                    {{
                      session.tabs.length > 0 ? 'now' : date(session.lastSeenAt)
                    }}
                    · expires {{ date(session.expiresAt) }}
                  </div>
                </div>
                <button
                  type="button"
                  class="btn btn-link btn-sm p-0 text-decoration-none"
                  [attr.aria-expanded]="expanded().has(session.id)"
                  data-id="profile-session-tabs"
                  (click)="toggleTabs(session.id)"
                >
                  <i
                    class="bi"
                    [class.bi-caret-down-fill]="expanded().has(session.id)"
                    [class.bi-caret-right-fill]="!expanded().has(session.id)"
                    aria-hidden="true"
                  ></i>
                  tabs: {{ session.tabs.length }}
                </button>
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
              @if (expanded().has(session.id)) {
                <div class="bg-body-tertiary rounded-2 mb-2 px-2 py-1">
                  @if (session.tabs.length === 0) {
                    <p class="text-body-secondary small mb-0">
                      No open tabs: the sign-in stays valid, but the browser is
                      closed right now.
                    </p>
                  } @else {
                    <ul class="list-unstyled mb-0">
                      @for (tab of session.tabs; track tab.acceptKey) {
                        <li
                          class="d-flex flex-wrap align-items-center gap-2 py-1"
                          data-id="profile-session-tab"
                        >
                          <i
                            class="bi bi-plug text-body-secondary"
                            aria-hidden="true"
                          ></i>
                          <code>{{ shortKey(tab.acceptKey) }}</code>
                          <span class="small text-body-secondary"
                            >opened {{ tabDate(tab.connectedAt) }}</span
                          >
                          @if (tab.current) {
                            <!-- A newline between the tags is dropped, so the
                                 boundary is its own text node, and only while
                                 this tab is shown. -->
                            {{ ' ' }}
                            <span
                              class="small text-body-secondary"
                              data-id="profile-session-tab-this"
                              >this tab</span
                            >
                          }
                        </li>
                      }
                    </ul>
                  }
                </div>
              }
            </article>
          }
        </div>
      }
      <button
        type="button"
        class="btn btn-outline-danger btn-sm mt-3"
        [disabled]="endableCount() === 0"
        data-id="profile-sessions-end-others"
        (click)="openEndOthers()"
      >
        Sign out everywhere else
      </button>

      <hilos-modal
        [open]="endingSessionId() !== null"
        (openChange)="$event ? null : endingSessionId.set(null)"
        [title]="endTitle()"
        initialFocus="dialog"
        [busy]="endAction.busy()"
      >
        <p>
          Tabs of this session lose the account at once: where an account is
          needed, the sign-in form takes the place of the content.
        </p>
        <p>Your other sessions are not touched.</p>
        <hilos-form-error
          [message]="endError()"
          dataId="profile-session-end-error"
          [announce]="true"
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
            [loading]="endAction.busy()"
            [disabled]="selectedSession() === undefined"
            data-id="profile-session-end-confirm"
            (click)="confirmEnd()"
          >
            End
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="endOthersOpen()"
        (openChange)="$event ? null : endOthersOpen.set(false)"
        title="Sign out everywhere else"
        initialFocus="dialog"
        [busy]="endOthersAction.busy()"
      >
        <p>
          Other sessions that will end:
          <strong data-id="profile-sessions-end-others-count">{{
            endableCount()
          }}</strong
          >.
        </p>
        <p>
          Their tabs lose the account at once: where an account is needed, the
          sign-in form takes the place of the content.
        </p>
        <p>This session stays signed in.</p>
        <hilos-form-error
          [message]="endOthersError()"
          dataId="profile-sessions-end-others-error"
          [announce]="true"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="endOthersAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="endOthersAction.busy()"
            [disabled]="endableCount() === 0"
            data-id="profile-sessions-end-others-confirm"
            (click)="confirmEndOthers()"
          >
            Sign out
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
  protected readonly endOthersOpen = signal(false)
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
  protected readonly endOthersError = computed(() =>
    this.endableCount() === 0
      ? 'Your other sessions have already ended'
      : this.endOthersAction.error(),
  )
  protected readonly endTitle = computed(
    () => `End session #${this.endingSessionId() ?? ''}`,
  )
  private readonly timeZone = hilosSignal(hilosTimeZone)

  protected date(value: number | null): string {
    this.timeZone()
    return formatProfileDateTime(value)
  }

  protected tabDate(connectedAt: number): string {
    this.timeZone()
    return formatProfileDateTime(connectedAt)
  }

  /**
   * Display form of a tab key.
   *
   * Eight characters or fewer stay whole. A longer key keeps four characters
   * at each end. The list identity stays the full accept key.
   */
  protected shortKey(acceptKey: string): string {
    if (acceptKey.length <= 8) {
      return acceptKey
    }
    return `${acceptKey.slice(0, 4)}…${acceptKey.slice(-4)}`
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

  protected openEndOthers(): void {
    this.endOthersAction.clearError()
    this.endOthersOpen.set(true)
  }

  protected async confirmEndOthers(): Promise<void> {
    if (this.endableCount() === 0) {
      return
    }
    if (await this.endOthersAction.run(this.actions().endOtherSessions())) {
      this.endOthersOpen.set(false)
    }
  }
}
