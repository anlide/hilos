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
  computedSignal,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  createHilosLegalRevision,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
  type HilosLegalAdminRevision,
} from '@hilos/core/admin/legal'
import {
  HilosLink,
  HilosLegalChanges,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosLegalRevisionPageShell } from './HilosLegalRevisionPageShell.js'

/** The selected revision's changes and live exact acceptance count. */
@Component({
  selector: 'hilos-legal-revision-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalRevisionPageShell, HilosLink, HilosLegalChanges],
  template: `
    <hilos-legal-revision-page-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (details(); as data) {
          @if (data.declared && data.revision) {
            <section class="mb-4">
              <h2 class="h5">Changes from the previous revision</h2>
              @if (data.changes !== null && data.predecessorId !== null) {
                <hilos-legal-changes
                  [changes]="data.changes"
                  [fromLabel]="data.predecessorId"
                  [toLabel]="data.revisionId"
                />
              } @else {
                <p class="text-body-secondary">
                  This is the first revision; there is nothing to compare it
                  with.
                </p>
              }
            </section>
          }
          <section>
            <h2 class="h5">Who accepted this revision</h2>
            <p data-id="legal-revision-accepted">{{ accepted() }}</p>
            <a
              [hilosLink]="acceptancesPath"
              class="btn btn-outline-secondary"
              data-id="legal-open-acceptances"
              >Open acceptances</a
            >
          </section>
        }
      </ng-template>
    </hilos-legal-revision-page-shell>
  `,
})
export class HilosLegalRevisionPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_REVISION
  protected readonly acceptancesPath = resolveHilosPath(
    HilosPages.LEGAL_ACCEPTANCES,
  )
  protected readonly details = signal<
    HilosLegalAdminRevision | null | undefined
  >(undefined)
  protected readonly accepted = signal('Loading acceptance count…')
  private readonly router = inject(HILOS_ROUTER)
  private readonly documentKey = computedSignal(() =>
    String(this.router.currentRoute.get().params['documentKey'] ?? ''),
  )
  private readonly revisionId = computedSignal(() =>
    String(this.router.currentRoute.get().params['revisionId'] ?? ''),
  )
  private readonly revision = computed(() =>
    createHilosLegalRevision(this.context()),
  )
  private readonly revisions = computed(() =>
    createHilosLegalRevisionsTable(
      this.context(),
      this.documentKey,
      HilosPages.LEGAL_REVISION,
      this.revisionId,
    ),
  )

  constructor() {
    effect((onCleanup) => {
      const revision = this.revision()
      const revisions = this.revisions()
      const summary = createHilosLegalRevisionAcceptanceSummary(
        revisions.controller,
        this.revisionId,
      )
      const off = [
        mirrorHilosSignal(revision, this.details),
        mirrorHilosSignal(summary, this.accepted),
      ]
      revisions.start()
      onCleanup(() => {
        for (const stop of off) stop()
        revisions.dispose()
        revisions.controller.dispose()
      })
    })
  }
}
