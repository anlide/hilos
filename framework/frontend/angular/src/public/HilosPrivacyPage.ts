// The public Privacy page reads the policy and the reader's standing from one
// page answer. The project supplies an optional introduction. The browser erase
// stays available without a policy or a socket; its result belongs to the erase,
// independently of legal refreshes and of the new guest session.
// The browser values are erased synchronously before forgetting the in-memory
// protected-mode pass and dispatching the server erase. Nothing starts on render.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  effect,
  inject,
  PLATFORM_ID,
  signal,
} from '@angular/core'
import { isPlatformBrowser } from '@angular/common'
import {
  BROWSER_ERASE_ACTION,
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
  createHilosLegalPrivacyStore,
  describeHilosLegalPrivacyReader,
  formatHilosLegalDate,
  hilosLegalReconsentPerson,
  LEGAL_PRIVACY_COPY,
  LEGAL_RECONSENT_COPY,
  sessionAccountStanding,
  subscribeSignal,
  type HilosLegalContext,
  type HilosLegalPrivacy,
  type HilosLegalPrivacyStore,
  type HilosLegalAgreement,
  type HilosAccountStanding,
  type HilosBrowserValue,
} from '@hilos/core/base'

import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalDocumentReader } from '../legal/HilosLegalDocumentReader.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'
import { hilosSignal } from '../hilosSignal.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'
import { HilosPrivacyEraseModal } from './HilosPrivacyEraseModal.js'

/**
 * The public policy reader, its text and the browser erase with its outcome.
 */
@Component({
  selector: 'hilos-privacy-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosPrivacyEraseModal,
    HilosStaticPage,
    HilosFormError,
    HilosModal,
    LoadingButton,
    HilosLegalChanges,
    HilosLegalDocumentReader,
    HilosLegalRevisionText,
  ],
  template: `
    <hilos-static-page title="Privacy">
      @if (line().state !== 'unpublished') {
        <div
          class="mb-4"
          data-id="privacy-reader"
          [attr.data-state]="line().state"
        >
          <hilos-legal-document-reader
            [line]="line()"
            [copy]="copy"
            [accepting]="accepting()"
            [refusal]="comparing() ? null : refusal()"
            (accept)="onAccept()"
            (compare)="comparing.set(true)"
          />
        </div>
      }

      <ng-content />

      @if (privacy() === undefined) {
        <p class="text-body-secondary" data-id="privacy-loading">
          {{ copy.loading }}
        </p>
      } @else if (privacy() === null) {
        <p class="text-body-secondary" data-id="privacy-none">
          {{ copy.nonePublished }}
        </p>
      } @else if (privacy(); as section) {
        <div data-id="privacy-text">
          <hilos-legal-revision-text [clauses]="section.clauses" />
        </div>
      }

      <section class="border rounded p-3 mt-4 mb-3">
        <h2 class="h6 text-uppercase text-body-secondary mb-2">
          Erase everything stored on this device
        </h2>

        @if (swept() === null) {
          <p class="small text-body-secondary">
            Signs you out and clears every cookie and local value this site has
            kept &mdash; as if you had never opened it.
          </p>
          <!-- Never disabled, in any state, including with the socket down: a
        control that is off and cannot say why is the defect HIL-661 exists to
        fix, and the browser half runs offline just as well. -->
          <button
            type="button"
            class="btn btn-outline-danger btn-sm"
            data-id="privacy-erase"
            (click)="confirming.set(true)"
          >
            <i class="bi bi-eraser me-1" aria-hidden="true"></i>
            Erase and sign out
          </button>
        }
        <div role="status" aria-live="polite">
          @if (swept(); as done) {
            <div
              data-id="privacy-erase-done"
              class="alert mb-0"
              [class.alert-success]="erase.error() === null"
              [class.alert-warning]="erase.error() !== null"
            >
              <p class="small mb-2">
                {{ done.length }} of the things this browser kept were erased.
              </p>
              <ul class="small mb-2 ps-3">
                @for (label of done; track label) {
                  <li>{{ label }}</li>
                }
              </ul>
              @if (erase.error(); as reason) {
                <p class="small mb-2" data-id="privacy-erase-partial">
                  The values above are gone, but this browser's session could
                  not be ended: {{ reason }}
                </p>
              } @else {
                <p class="small mb-2">
                  This browser's session was ended and a new one begun, so the
                  sign-in cookie is not the one it arrived with. Other tabs come
                  back on the new one, signed out.
                </p>
              }
              <p class="small mb-0">
                Nothing about your account changed. This is not account
                deletion.
              </p>
            </div>
          }
        </div>
      </section>

      <hilos-privacy-erase-modal
        [open]="confirming()"
        (openChange)="confirming.set($event)"
        [labels]="labels()"
        [busy]="erase.busy()"
        (confirm)="onConfirm()"
      />
      <hilos-modal
        [open]="changes() !== null"
        [busy]="accepting()"
        [title]="changesTitle()"
        initialFocus="dialog"
        size="wide"
        (openChange)="closeChanges($event)"
      >
        @if (changes(); as shown) {
          <div data-id="privacy-changes-modal">
            <hilos-legal-changes
              [changes]="shown.changes"
              [fromLabel]="publishedOf(shown.fromRevisionId)"
              [toLabel]="publishedOf(shown.toRevisionId)"
            />
            @if (line().canAccept) {
              <div class="mt-3">
                <hilos-form-error
                  [message]="refusal()"
                  dataId="privacy-changes-refusal"
                  [announce]="true"
                />
              </div>
            }
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose"
          ><button
            type="button"
            class="btn btn-secondary"
            data-id="privacy-changes-close"
            [disabled]="accepting()"
            (click)="requestClose()"
          >
            {{ reconsentCopy.close }}
          </button>
          @if (line().canAccept) {
            <button
              hilosLoadingButton
              class="btn-primary"
              data-id="privacy-changes-accept"
              [loading]="accepting()"
              (click)="onAccept()"
            >
              {{ reconsentCopy.accept }}
            </button>
          }
        </ng-template>
      </hilos-modal>
    </hilos-static-page>
  `,
})
export class HilosPrivacyPage {
  /** The connection, scopes and action lifecycle for public reading and browser erase. */
  readonly context = input.required<HilosLegalContext>()

  private readonly platformId = inject(PLATFORM_ID)
  protected readonly copy = LEGAL_PRIVACY_COPY
  protected readonly reconsentCopy = LEGAL_RECONSENT_COPY
  protected readonly store = computed<HilosLegalPrivacyStore>(() =>
    createHilosLegalPrivacyStore(this.context()),
  )
  protected readonly privacy = signal<HilosLegalPrivacy | null | undefined>(
    undefined,
  )
  protected readonly agreement = signal<HilosLegalAgreement | null | undefined>(
    undefined,
  )
  protected readonly accepting = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly standing = signal<HilosAccountStanding | null>(null)
  protected readonly person = hilosSignal(hilosLegalReconsentPerson)
  /** Whether the comparison of the held revision with the one in force is open. */
  protected readonly comparing = signal(false)

  protected readonly line = computed(() =>
    describeHilosLegalPrivacyReader(this.privacy(), this.agreement(), {
      person: this.person(),
      frozen: this.standing()?.frozen === true,
      now: Date.now(),
    }),
  )
  protected readonly changes = computed(() =>
    this.comparing() && this.line().canCompare
      ? (this.privacy()?.changes ?? null)
      : null,
  )
  protected readonly changesTitle = computed(() => {
    const changes = this.changes()
    return changes === null
      ? ''
      : this.copy.changesTitle
          .replace('{from}', this.publishedOf(changes.fromRevisionId))
          .replace('{to}', this.publishedOf(changes.toRevisionId))
  })
  constructor() {
    effect((onCleanup) => {
      if (!isPlatformBrowser(this.platformId)) return
      this.comparing.set(false)
      const store = this.store()
      const standing = sessionAccountStanding(this.context().scopes)
      const stop = store.start()
      this.privacy.set(store.privacy.get())
      this.agreement.set(store.agreement.get())
      this.accepting.set(store.accepting.get())
      this.refusal.set(store.refusal.get())
      this.standing.set(standing.get())
      const off = [
        subscribeSignal(store.privacy, (value) => this.privacy.set(value)),
        subscribeSignal(store.agreement, (value) => this.agreement.set(value)),
        subscribeSignal(store.accepting, (value) => this.accepting.set(value)),
        subscribeSignal(store.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(standing, (value) => this.standing.set(value)),
      ]
      onCleanup(() => {
        for (const unsubscribe of off) unsubscribe()
        stop()
      })
    })
    // A comparison that has nothing left to show - accepted, or the revision moved - is closed, not parked.
    effect(() => {
      if (this.changes() === null) this.comparing.set(false)
    })
  }

  /**
   * The publication day of a revision the page lists, or its id when it lists none.
   *
   * @param revisionId The revision.
   */
  protected publishedOf(revisionId: string): string {
    const revision = this.privacy()?.revisions.find(
      (item) => item.revisionId === revisionId,
    )

    return revision ? formatHilosLegalDate(revision.publishedOn) : revisionId
  }

  protected async onAccept(): Promise<void> {
    if (this.line().canAccept && (await this.store().accept()))
      this.comparing.set(false)
  }

  protected closeChanges(open: boolean): void {
    if (!open) this.comparing.set(false)
  }

  /** The project's own declarations, swept beside the framework's. */
  readonly values = input<readonly HilosBrowserValue[]>([])

  /** Everything this browser is declared to hold: the framework's list plus the project's. */
  protected readonly registry = computed(() => [
    ...HILOS_BROWSER_VALUES,
    ...this.values(),
  ])

  /** One line per registry entry, in declaration order, as the confirmation shows them. */
  protected readonly labels = computed(() =>
    this.registry().map((value) => value.label),
  )

  /** Whether the confirmation is open. */
  protected readonly confirming = signal(false)

  /** What the erase did, once it has run; null while the block is still an offer. */
  protected readonly swept = signal<string[] | null>(null)

  protected readonly erase = createHilosTrackedAction()

  /** Runs the erase: the browser half first, the server half second. */
  protected async onConfirm(): Promise<void> {
    if (this.erase.busy()) {
      return
    }

    const connection = this.context().connection

    // 1. The browser half, first and synchronous. It cannot fail: a storage that
    // is absent or refuses is reported as nothing swept rather than as an error.
    const erased = eraseBrowserValues(this.registry(), {
      sessionCookieName: connection.sessionCookieName,
    })

    // 2. The pass the connection also holds IN MEMORY and re-presents on every
    // reconnect. Erasing only its mirror in storage would buy the admission back
    // on the socket that comes next.
    connection.forgetProtectedModePass()

    // 3. The server half. The confirmation stays open with its button loading
    // until this settles, and only then does the block become the outcome —
    // either way, success or refusal. Drawing the outcome before the reply would
    // state that this browser's session was ended while the question was still on
    // the wire, and on a timeout it would state it wrongly for thirty seconds.
    await this.erase.run(
      this.context().actions.dispatch(BROWSER_ERASE_ACTION, {}),
    )
    this.confirming.set(false)
    this.swept.set(erased)
  }
}
