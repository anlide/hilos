// HilosLegalReconsent — the "the terms have changed" screen (HIL-500), one
// component in three places: the window HilosLayout raises over the page on a
// sign-in while a document waits for a decision (variant "window"), the screen
// that takes the content's place once the deadline has passed and the account
// is frozen (variant "frozen"), and the administrator's read-only preview on a
// document's page (variant "preview"). One markup for the three, because two
// copies of it would drift apart exactly in the words.
//
// A section per document: its plate, the revision the person accepted, the list
// of what changed, and the way into the full text and the line-by-line
// comparison, both drawn inside the same body with "Back to the changes". "I do
// not accept" opens the refusal step in the body and sends nothing. The frozen
// variant keeps only "Accept" and lays the exits out as actions; under a
// takeover the acceptance is not offered at all. The state lives in the core
// store; the component draws it and reports what was pressed. Bootstrap classes
// only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
} from '@angular/core'
import {
  describeHilosLegalReconsentAccepted,
  describeHilosLegalReconsentAddress,
  describeHilosLegalReconsentChange,
  describeHilosLegalReconsentPlate,
  describeHilosLegalReconsentRefusal,
  HILOS_PAGE_ROUTES,
  hilosLegalDocumentLabel,
  hilosLegalReconsentSections,
  HilosPages,
  keepMyAccount,
  LEGAL_RECONSENT_COPY,
  signOut,
  type HilosLegalReconsentContent,
  type HilosLegalReconsentPerson,
  type HilosLegalReconsentPreview,
  type HilosLegalReconsentVariant,
  type HilosLegalReconsentView,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosLink } from '../HilosLink.js'
import { LoadingButton } from '../LoadingButton.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'
import { HilosLegalChanges } from './HilosLegalChanges.js'
import { HilosLegalRevisionText } from './HilosLegalRevisionText.js'

/** A counter that makes every screen's heading id unique on the page. */
let nextHeadingId = 0

/** The "the terms have changed" screen: window, freeze screen or admin preview. */
@Component({
  selector: 'hilos-legal-reconsent',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosFormError,
    HilosLegalChanges,
    HilosLegalRevisionText,
    HilosLink,
    LoadingButton,
  ],
  template: `
    <section
      data-id="legal-reconsent"
      [attr.data-variant]="variant()"
      [attr.aria-labelledby]="headingId"
    >
      @if (variant() !== 'preview' && person(); as who) {
        <div
          class="d-flex flex-wrap align-items-center gap-2 border rounded px-3 py-2 mb-3 small"
          data-id="legal-reconsent-person"
        >
          <i
            class="bi bi-person-circle text-body-secondary"
            aria-hidden="true"
          ></i>
          <div class="lh-sm text-break">
            <div class="fw-semibold">{{ who.name }}</div>
            @if (address(); as addr) {
              <div
                class="text-body-secondary small"
                data-id="legal-reconsent-address"
              >
                {{ addr }}
              </div>
            }
          </div>
          @if (!who.impersonated) {
            <button
              hilosLoadingButton
              class="btn-link btn-sm p-0 ms-auto"
              data-id="legal-reconsent-not-you"
              [loading]="signOutAction.busy()"
              (click)="onSignOut()"
            >
              {{ copy.notYou }}
            </button>
          }
        </div>
      }
      <h2 [id]="headingId" class="h5 mb-3" data-id="legal-reconsent-heading">
        {{ variant() === 'frozen' ? copy.frozenHeading : copy.heading }}
      </h2>
      <div class="visually-hidden" role="status" aria-live="polite">
        {{ error() ?? (loading() ? 'Loading terms…' : '') }}
      </div>
      @if (loading()) {
        <div
          class="placeholder-glow mb-3"
          data-id="legal-reconsent-loading"
          aria-busy="true"
        >
          <span class="placeholder col-12" aria-hidden="true"></span>
          <span class="placeholder col-9" aria-hidden="true"></span>
          <span class="placeholder col-10" aria-hidden="true"></span>
        </div>
      }
      @if (firstRevision()) {
        <p
          class="text-body-secondary mb-0"
          data-id="legal-reconsent-preview-first"
        >
          {{ copy.previewFirst }}
        </p>
      } @else if (content() !== null && view().kind === 'changes') {
        @for (row of rows(); track row.section.document) {
          <section
            class="mb-3"
            data-id="legal-reconsent-document"
            [attr.data-document]="row.section.document"
          >
            <h3 class="h6 mb-2">{{ label(row.section.document) }}</h3>
            @if (row.plate; as plate) {
              <div
                class="d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small"
                [class]="
                  'bg-' +
                  plate.tone +
                  '-subtle text-' +
                  plate.tone +
                  '-emphasis'
                "
                data-id="legal-reconsent-badge"
                [attr.data-tone]="plate.tone"
              >
                <i [class]="'bi ' + plate.icon" aria-hidden="true"></i>
                <strong>{{ plate.text }}</strong>
                @if (plate.detail !== null) {
                  <span>{{ plate.detail }}</span>
                }
              </div>
            }
            <p class="small text-body-secondary mb-2">{{ row.accepted }}</p>
            <ul class="list-unstyled mb-2">
              @for (change of row.changes; track change.clauseKey) {
                <li
                  class="d-flex gap-2 py-2 border-bottom text-break"
                  data-id="legal-reconsent-change"
                  [attr.data-clause]="change.clauseKey"
                >
                  <i
                    [class]="'bi text-body-secondary mt-1 ' + change.icon"
                    aria-hidden="true"
                  ></i>
                  <div class="flex-grow-1">
                    <div class="small fw-semibold">
                      {{ change.statement }}
                      <span
                        class="badge text-bg-light border ms-1"
                        data-id="legal-reconsent-change-kind"
                        >{{ change.kind }}</span
                      >
                    </div>
                    @if (change.before !== null) {
                      <div class="small text-body-secondary">
                        {{ change.before }}
                      </div>
                    }
                  </div>
                </li>
              }
            </ul>
            <div class="small">
              <button
                type="button"
                class="btn btn-link btn-sm p-0"
                data-id="legal-reconsent-full-text"
                [attr.data-document]="row.section.document"
                (click)="
                  show.emit({ kind: 'text', document: row.section.document })
                "
              >
                {{ copy.fullText }}
              </button>
              @if (row.section.held !== null) {
                ·
                <button
                  type="button"
                  class="btn btn-link btn-sm p-0"
                  data-id="legal-reconsent-compare"
                  [attr.data-document]="row.section.document"
                  (click)="
                    show.emit({
                      kind: 'compare',
                      document: row.section.document,
                    })
                  "
                >
                  {{ copy.compare }}
                </button>
              }
            </div>
          </section>
        }
        @if (variant() === 'frozen') {
          <section
            class="border rounded px-3 py-2 mb-3"
            data-id="legal-reconsent-exits"
          >
            <h3 class="h6">{{ copy.freezeKeeps }}</h3>
            <div class="d-flex flex-wrap gap-2">
              <a
                [hilosLink]="dataHref"
                class="btn btn-sm btn-outline-secondary"
                data-id="legal-reconsent-data-link"
                ><i class="bi bi-download me-1" aria-hidden="true"></i
                >{{ copy.dataLink }}</a
              >
              @if (deletionScheduled() && !impersonated()) {
                <button
                  hilosLoadingButton
                  class="btn-sm btn-outline-secondary"
                  data-id="legal-reconsent-keep-account"
                  [loading]="keepAction.busy()"
                  (click)="onKeepAccount()"
                >
                  {{ copy.keepAccount }}
                </button>
              }
              <button
                hilosLoadingButton
                class="btn-sm btn-outline-secondary"
                data-id="legal-reconsent-sign-out"
                [loading]="signOutAction.busy()"
                (click)="onSignOut()"
              >
                {{ copy.signOut }}
              </button>
            </div>
          </section>
        }
        @if (variant() === 'frozen' && impersonated() && person(); as who) {
          <p
            class="small text-body-secondary"
            data-id="legal-reconsent-impersonated"
          >
            {{ copy.onlyPerson.replace('{name}', who.name) }}
          </p>
        }
      } @else if (viewed() !== null && view().kind === 'text') {
        <div>
          <h3 class="h6">{{ label(viewed()!.document) }}</h3>
          <hilos-legal-revision-text [clauses]="viewedClauses()" />
        </div>
      } @else if (viewed() !== null && view().kind === 'compare') {
        <div>
          <h3 class="h6">{{ label(viewed()!.document) }}</h3>
          <hilos-legal-changes
            [changes]="viewedChanges()"
            [fromLabel]="viewed()!.held?.revisionId ?? ''"
            [toLabel]="viewed()!.current.revisionId"
          />
        </div>
      } @else if (content() !== null && view().kind === 'refuse') {
        <div data-id="legal-reconsent-refuse-step">
          <p>{{ refusal() }}</p>
          <div class="d-flex flex-wrap gap-3 small mb-3">
            <a
              [hilosLink]="dataHref"
              data-id="legal-reconsent-data-link"
              (click)="later.emit()"
              >{{ copy.dataLink }}</a
            >
            <a
              [hilosLink]="profileHref"
              class="link-danger"
              data-id="legal-reconsent-delete-link"
              (click)="later.emit()"
              >{{ copy.deleteLink }}</a
            >
          </div>
        </div>
      }
      @if (viewed() !== null) {
        <button
          type="button"
          class="btn btn-link btn-sm px-0 mb-3"
          data-id="legal-reconsent-back"
          (click)="show.emit({ kind: 'changes' })"
        >
          {{ copy.backToChanges }}
        </button>
      }
      <hilos-form-error [message]="error()" dataId="legal-reconsent-error" />
      @if (!firstRevision()) {
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-2">
          @if (error() !== null && content() === null) {
            <button
              type="button"
              class="btn btn-outline-primary"
              data-id="legal-reconsent-retry"
              (click)="retry.emit()"
            >
              {{ copy.retry }}
            </button>
          }
          @if (view().kind === 'refuse') {
            <button
              type="button"
              class="btn btn-outline-secondary"
              data-id="legal-reconsent-back"
              (click)="show.emit({ kind: 'changes' })"
            >
              {{ copy.back }}
            </button>
            <button
              type="button"
              class="btn btn-secondary"
              data-id="legal-reconsent-close"
              (click)="later.emit()"
            >
              {{ copy.close }}
            </button>
          } @else {
            @if (variant() !== 'frozen') {
              <button
                type="button"
                class="btn btn-outline-danger"
                data-id="legal-reconsent-refuse"
                [disabled]="variant() === 'preview' || content() === null"
                (click)="show.emit({ kind: 'refuse' })"
              >
                {{ copy.refuse }}
              </button>
              <button
                type="button"
                class="btn btn-outline-secondary"
                data-id="legal-reconsent-later"
                [disabled]="variant() === 'preview'"
                (click)="later.emit()"
              >
                {{ copy.later }}
              </button>
            }
            @if (acceptOffered()) {
              <button
                hilosLoadingButton
                class="btn-primary"
                data-id="legal-reconsent-accept"
                [loading]="busy()"
                [disabled]="acceptDisabled()"
                (click)="accept.emit()"
              >
                {{ copy.accept }}
              </button>
            }
          }
        </div>
      }
    </section>
  `,
})
export class HilosLegalReconsent {
  /** Where the screen stands. */
  readonly variant = input.required<HilosLegalReconsentVariant>()
  /** What was read, or null while it is read or after it failed. */
  readonly content = input.required<
    HilosLegalReconsentContent | HilosLegalReconsentPreview | null
  >()
  /** What the body shows. */
  readonly view = input.required<HilosLegalReconsentView>()
  /** Whether the content is being read. */
  readonly loading = input(false)
  /** The refusal to show, or null. */
  readonly error = input<string | null>(null)
  /** Whether the acceptance is in flight. */
  readonly busy = input(false)
  /** The person the screen speaks to; null in the preview. */
  readonly person = input<HilosLegalReconsentPerson | null>(null)
  /** Whether the person's deletion is scheduled, which offers "Keep my account" on the freeze. */
  readonly deletionScheduled = input(false)
  /** The moment the days are counted from, LOCAL epoch ms. */
  readonly now = input(Date.now())

  readonly accept = output<void>()
  readonly later = output<void>()
  readonly retry = output<void>()
  readonly show = output<HilosLegalReconsentView>()

  protected readonly copy = LEGAL_RECONSENT_COPY
  protected readonly headingId = `hilos-legal-reconsent-${nextHeadingId++}`
  protected readonly dataHref =
    HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA] ?? '/'
  protected readonly profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE] ?? '/'
  protected readonly signOutAction = createHilosTrackedAction()
  protected readonly keepAction = createHilosTrackedAction()
  protected readonly label = hilosLegalDocumentLabel

  private readonly sections = computed(() => {
    const content = this.content()
    return content === null ? [] : hilosLegalReconsentSections(content)
  })
  protected readonly rows = computed(() =>
    this.sections().map((section) => ({
      section,
      plate: describeHilosLegalReconsentPlate(
        section,
        this.variant(),
        this.now(),
      ),
      accepted: describeHilosLegalReconsentAccepted(section),
      changes: section.changes.map(describeHilosLegalReconsentChange),
    })),
  )
  protected readonly firstRevision = computed(
    () =>
      this.variant() === 'preview' &&
      this.sections().length === 1 &&
      this.sections()[0]!.held === null,
  )
  protected readonly impersonated = computed(
    () => this.person()?.impersonated === true,
  )
  protected readonly viewed = computed(() => {
    const view = this.view()
    if (view.kind !== 'text' && view.kind !== 'compare') return null
    return (
      this.sections().find((item) => item.document === view.document) ?? null
    )
  })
  protected readonly viewedClauses = computed(() => [
    ...(this.viewed()?.clauses ?? []),
  ])
  protected readonly viewedChanges = computed(() => [
    ...(this.viewed()?.changes ?? []),
  ])
  protected readonly refusal = computed(() => {
    const content = this.content()
    return content !== null && 'refusal' in content
      ? describeHilosLegalReconsentRefusal(
          content as HilosLegalReconsentContent,
        )
      : this.copy.refuseRemind
  })
  protected readonly acceptOffered = computed(
    () => this.variant() !== 'frozen' || !this.impersonated(),
  )
  protected readonly acceptDisabled = computed(
    () =>
      this.variant() === 'preview' ||
      this.content() === null ||
      this.sections().length === 0 ||
      this.loading() ||
      this.busy(),
  )
  protected readonly address = computed(() =>
    describeHilosLegalReconsentAddress(this.content()),
  )

  /** Sign out through the tracked driver; a second press while busy is dropped. */
  protected onSignOut(): void {
    if (this.signOutAction.busy()) return
    void this.signOutAction.run(signOut())
  }

  /** Call the scheduled deletion off through the tracked driver. */
  protected onKeepAccount(): void {
    if (this.keepAction.busy()) return
    void this.keepAction.run(keepMyAccount())
  }
}
