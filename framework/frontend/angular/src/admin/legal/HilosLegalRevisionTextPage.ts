import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalRevision,
  type HilosLegalAdminRevision,
} from '@hilos/core/admin/legal'
import { HilosPages, type HilosLegalContext } from '@hilos/core/base'
import { HilosLegalRevisionText, mirrorHilosSignal } from '@hilos/angular/base'
import { HilosLegalRevisionPageShell } from './HilosLegalRevisionPageShell.js'

/** The assembled clauses of the requested historical revision. */
@Component({
  selector: 'hilos-legal-revision-text-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalRevisionPageShell, HilosLegalRevisionText],
  template: `
    <hilos-legal-revision-page-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (details()?.clauses; as clauses) {
          <hilos-legal-revision-text [clauses]="clauses" />
        }
      </ng-template>
    </hilos-legal-revision-page-shell>
  `,
})
export class HilosLegalRevisionTextPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_REVISION_TEXT
  protected readonly details = signal<
    HilosLegalAdminRevision | null | undefined
  >(undefined)
  private readonly revision = computed(() =>
    createHilosLegalRevision(this.context()),
  )

  constructor() {
    effect((onCleanup) => {
      onCleanup(mirrorHilosSignal(this.revision(), this.details))
    })
  }
}
