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
  createHilosModerationKind,
  createHilosModerationRules,
  createHilosModerationRuleAdd,
  createHilosModerationRuleEdit,
  createHilosModerationRuleDelete,
  HILOS_MODERATION_COPY,
  HILOS_MODERATION_RULE_EDIT_COPY,
  HILOS_MODERATION_RULE_DELETE_COPY,
  HilosModerationRuleRowKey,
  type HilosModerationRulesContext,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import {
  bindPageInstanceName,
  computedSignal,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
} from '@hilos/core/base'
import {
  HILOS_ROUTER,
  HilosAdminPage,
  HilosTableEmptyState,
  HilosTableCell,
  HilosViewportTable,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosModerationRuleAdd } from './HilosModerationRuleAdd.js'
import { HilosModerationRuleEdit } from './HilosModerationRuleEdit.js'
import { HilosModerationRuleDelete } from './HilosModerationRuleDelete.js'

/** The current kind's rules, backed by the core viewport and mutation windows. */
@Component({
  selector: 'hilos-moderation-kind-rules-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosTableEmptyState,
    HilosTableCell,
    HilosViewportTable,
    HilosModerationRuleAdd,
    HilosModerationRuleEdit,
    HilosModerationRuleDelete,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="entity" tabsLabel="Kind pages">
      <ng-template #headVisual>
        @if (row()) {
          <span
            class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
            data-id="hilos-moderation-kind-visual"
            ><i class="bi bi-shield-check"></i
          ></span>
        }
      </ng-template>
      <ng-template #headBadges>
        @if (row(); as kind) {
          <span
            class="badge border"
            [class]="
              kind.owner === 'framework'
                ? 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                : 'bg-info-subtle text-info-emphasis border-info-subtle'
            "
            data-id="hilos-moderation-kind-owner"
            >{{
              kind.owner === 'framework' ? copy.framework : copy.project
            }}</span
          >
        }
      </ng-template>
      <ng-template #headDescription>
        @if (row(); as kind) {
          <code
            class="text-body-secondary text-break"
            data-id="hilos-moderation-kind-key"
            >{{ kind.kind }}</code
          >
        }
      </ng-template>
      @if (unavailable()) {
        <ng-template #empty></ng-template>
        <hilos-table-empty-state
          [controller]="model().detail.controller"
          kind="unavailable"
          [fallback]="empty"
        />
      } @else if (row() && current()) {
        <hilos-viewport-table
          [controller]="model().rules.controller"
          data-id="hilos-moderation-rules-table"
        >
          <ng-template [hilosTableCell]="ruleKey" let-rule>{{
            rule.rule
          }}</ng-template>
          <ng-template [hilosTableCell]="actionsKey" let-rule>
            <div class="d-flex gap-1 justify-content-end">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                [title]="editCopy.open"
                [attr.aria-label]="editCopy.open"
                [attr.data-id]="'hilos-moderation-rule-edit-open-' + rule.id"
                (click)="model().edit.open('' + rule.id)"
              >
                <i class="bi bi-pencil" aria-hidden="true"></i
                ><span class="d-md-none ms-1">Edit</span>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                [title]="deleteCopy.open"
                [attr.aria-label]="deleteCopy.open"
                [attr.data-id]="'hilos-moderation-rule-delete-open-' + rule.id"
                (click)="model().remove.open('' + rule.id)"
              >
                <i class="bi bi-trash" aria-hidden="true"></i
                ><span class="d-md-none ms-1">{{ deleteCopy.confirm }}</span>
              </button>
            </div>
          </ng-template>
          <ng-template #empty>
            <div class="text-center py-4" role="status">
              <i
                class="bi bi-list-check fs-2 text-body-secondary"
                aria-hidden="true"
              ></i>
              <p class="fw-semibold mb-1">No rules of your own yet</p>
              <p class="text-body-secondary mb-0">
                {{ row()?.title }} is checked by its built-in rules below.
              </p>
            </div>
          </ng-template>
        </hilos-viewport-table>
        @if (builtIn()) {
          <div
            class="border rounded-3 p-3 mt-3 bg-body-tertiary"
            data-id="hilos-moderation-rules-built-in"
          >
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <h2 class="fw-semibold small text-body-secondary mb-0">
                Built-in rules
              </h2>
              <span
                class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
                >{{ copy.builtIn }}</span
              >
            </div>
            <p class="small text-body-secondary mb-2">
              They come with the kind and apply until you add a rule of your
              own. Your first rule replaces them all.
            </p>
            <ul class="small text-body-secondary mb-0 ps-3 text-break">
              @for (rule of row()?.defaultRules; track $index) {
                <li>{{ rule }}</li>
              }
            </ul>
          </div>
        }
      } @else {
        <div
          class="placeholder-glow border rounded-3 px-3 py-2 mb-4"
          role="status"
          aria-label="Loading moderation rules"
          data-id="hilos-moderation-rules-loading"
        >
          <span class="placeholder col-8 d-block mb-2"></span>
          <span class="placeholder col-6 d-block mb-2"></span>
          <span class="placeholder col-4 d-block"></span>
        </div>
      }
      <hilos-moderation-rule-add [add]="model().add" />
      <hilos-moderation-rule-edit [edit]="model().edit" />
      <hilos-moderation-rule-delete [remove]="model().remove" />
    </hilos-admin-page>
  `,
})
export class HilosModerationKindRulesPage {
  /** The project's connection, page scopes and tracked action lifecycle. */
  readonly context = input.required<HilosModerationRulesContext>()

  protected readonly page = HilosPages.MODERATION_KIND_RULES
  protected readonly copy = HILOS_MODERATION_COPY
  protected readonly editCopy = HILOS_MODERATION_RULE_EDIT_COPY
  protected readonly deleteCopy = HILOS_MODERATION_RULE_DELETE_COPY
  protected readonly ruleKey = HilosModerationRuleRowKey.rule
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  private readonly router = inject(HILOS_ROUTER)
  private readonly kind = computedSignal(
    () => this.router.currentRoute.get().params['kind'] ?? '',
  )
  protected readonly model = computed(() => {
    const context = this.context()
    const detail = createHilosModerationKind(context, this.kind, this.page)
    const rules = createHilosModerationRules(context, this.kind, {
      openAdd: () => add.open(),
    })
    const add = createHilosModerationRuleAdd(context, detail.row, rules.current)
    const edit = createHilosModerationRuleEdit(context, detail.row, rules)
    const remove = createHilosModerationRuleDelete(context, detail.row, rules)
    return { detail, rules, add, edit, remove }
  })
  protected readonly row = signal<HilosModerationKindRow | null>(null)
  protected readonly unavailable = signal(false)
  protected readonly current = signal(false)
  protected readonly builtIn = signal(false)

  constructor() {
    effect((onCleanup) => {
      const { detail, rules, add, edit, remove } = this.model()
      const unbindName = bindPageInstanceName(
        this.context().scopes,
        HilosPages.MODERATION_KIND,
        computedSignal(() => detail.row.get()?.title),
      )
      detail.start()
      rules.start()
      add.start()
      edit.start()
      remove.start()
      const off = [
        mirrorHilosSignal(detail.row, this.row),
        mirrorHilosSignal(detail.unavailable, this.unavailable),
        mirrorHilosSignal(rules.current, this.current),
        mirrorHilosSignal(
          computedSignal(
            () =>
              detail.row.get()?.ownRuleCount === 0 &&
              rules.current.get() &&
              rules.controller.frame.body.get() !== 'unavailable',
          ),
          this.builtIn,
        ),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        unbindName()
        add.dispose()
        edit.dispose()
        remove.dispose()
        detail.dispose()
        rules.dispose()
      })
    })
  }
}
