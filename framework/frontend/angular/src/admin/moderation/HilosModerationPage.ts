import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import {
  createHilosModerationKindsTable,
  HILOS_MODERATION_COPY,
  HilosModerationKindRowKey,
  moderationKindPath,
  type HilosModerationContext,
} from '@hilos/core/admin/moderation'
import { HILOS_TABLE_ACTIONS_KEY, HilosPages } from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosHideable,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
} from '@hilos/angular/base'

/** The server-windowed list of published moderation kinds. */
@Component({
  selector: 'hilos-moderation-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="section">
      <hilos-viewport-table
        [controller]="table().controller"
        data-id="hilos-moderation-kinds-table"
      >
        <ng-template [hilosTableCell]="keys.title" let-row>
          <div class="fw-semibold">{{ row.title }}</div>
          <code
            class="small text-body-secondary"
            [attr.data-id]="'hilos-moderation-row-' + row.kind"
            >{{ row.kind }}</code
          >
        </ng-template>
        <ng-template [hilosTableCell]="keys.owner" let-row>
          <span
            class="badge border"
            [class]="
              row.owner === 'framework'
                ? 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                : 'bg-info-subtle text-info-emphasis border-info-subtle'
            "
          >
            {{ row.owner === 'framework' ? copy.framework : copy.project }}
          </span>
        </ng-template>
        <ng-template [hilosTableCell]="keys.model" let-row>
          <div>{{ row.input === 'image' ? 'Vision model' : 'Text model' }}</div>
          <hilos-hideable [value]="row.model">
            <ng-template let-model>
              <code class="small text-body-secondary">{{ model }}</code>
            </ng-template>
          </hilos-hideable>
        </ng-template>
        <ng-template [hilosTableCell]="keys.ownRuleCount" let-row>
          @if (row.ownRuleCount === 0) {
            <span
              class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              >{{ copy.builtIn }}</span
            >
          } @else {
            {{ row.ownRuleCount }}
            {{ row.ownRuleCount === 1 ? 'rule' : 'rules' }}
          }
        </ng-template>
        <ng-template [hilosTableCell]="actionsKey" let-row>
          <a
            [hilosLink]="kindPath(row.kind)"
            class="btn btn-sm btn-outline-primary"
            [attr.aria-label]="'Open ' + row.title"
            [attr.data-id]="'hilos-moderation-open-' + row.kind"
            >{{ copy.open }}</a
          >
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosModerationPage {
  /** The project's connection and page scopes. */
  readonly context = input.required<HilosModerationContext>()

  protected readonly page = HilosPages.MODERATION
  protected readonly keys = HilosModerationKindRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly copy = HILOS_MODERATION_COPY
  protected readonly kindPath = moderationKindPath
  protected readonly table = computed(() =>
    createHilosModerationKindsTable(this.context()),
  )

  constructor() {
    effect((onCleanup) => {
      const table = this.table()
      table.start()
      onCleanup(() => table.dispose())
    })
  }
}
