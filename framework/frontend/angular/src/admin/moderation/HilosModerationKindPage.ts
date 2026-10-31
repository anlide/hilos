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
  HILOS_MODERATION_COPY,
  type HilosModerationContext,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import {
  HILOS_ROUTER,
  HilosAdminPage,
  HilosHideable,
  HilosTableEmptyState,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** Read-only Overview of the moderation kind named by the route. */
@Component({
  selector: 'hilos-moderation-kind-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosHideable, HilosTableEmptyState],
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
      @if (row(); as kind) {
        <div
          class="border rounded-3 px-3 py-2 mb-4"
          data-id="hilos-moderation-kind-overview"
        >
          <dl class="row small mb-0 g-0">
            <dt class="col-sm-3 fw-normal text-body-secondary py-1">
              Checked by
            </dt>
            <dd
              class="col-sm-9 mb-0 py-1 text-break"
              data-id="hilos-moderation-kind-model"
            >
              {{ kind.input === 'image' ? 'Vision model' : 'Text model' }} ·
              <hilos-hideable [value]="kind.model"
                ><ng-template let-model
                  ><code>{{ model }}</code></ng-template
                ></hilos-hideable
              >
              <span class="text-body-secondary">
                — profile
                <code data-id="hilos-moderation-kind-profile">{{
                  kind.profile
                }}</code></span
              >
            </dd>
            <dt class="col-sm-3 fw-normal text-body-secondary py-1">
              Can refuse for
            </dt>
            <dd
              class="col-sm-9 mb-0 py-1 d-flex flex-wrap gap-1"
              data-id="hilos-moderation-kind-reasons"
            >
              @for (reason of kind.reasons; track reason) {
                <span
                  class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle text-wrap text-break mw-100"
                  >{{ reason }}</span
                >
              }
            </dd>
            <dt class="col-sm-3 fw-normal text-body-secondary py-1">
              Rules in force
            </dt>
            <dd
              class="col-sm-9 mb-0 py-1"
              data-id="hilos-moderation-kind-rules"
            >
              @if (kind.ownRuleCount === 0) {
                <span
                  class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
                  >{{ copy.builtIn }}</span
                >
                <span class="text-body-secondary">
                  until you add a rule of your own</span
                >
              } @else {
                {{ kind.ownRuleCount }}
                {{ kind.ownRuleCount === 1 ? 'rule' : 'rules' }} of your own
              }
            </dd>
          </dl>
        </div>
      } @else if (unavailable()) {
        <ng-template #empty></ng-template>
        <hilos-table-empty-state
          [controller]="detail().controller"
          kind="unavailable"
          [fallback]="empty"
        />
      } @else {
        <div
          class="placeholder-glow border rounded-3 px-3 py-2 mb-4"
          role="status"
          aria-label="Loading moderation kind"
          data-id="hilos-moderation-kind-loading"
        >
          <span class="placeholder col-8 d-block mb-2"></span>
          <span class="placeholder col-6 d-block mb-2"></span>
          <span class="placeholder col-4 d-block"></span>
        </div>
      }
    </hilos-admin-page>
  `,
})
export class HilosModerationKindPage {
  /** The project's connection and page scopes. */
  readonly context = input.required<HilosModerationContext>()

  protected readonly page = HilosPages.MODERATION_KIND
  protected readonly copy = HILOS_MODERATION_COPY
  private readonly router = inject(HILOS_ROUTER)
  private readonly kind = computedSignal(
    () => this.router.currentRoute.get().params['kind'] ?? '',
  )
  protected readonly detail = computed(() =>
    createHilosModerationKind(this.context(), this.kind),
  )
  protected readonly row = signal<HilosModerationKindRow | null>(null)
  protected readonly unavailable = signal(false)

  constructor() {
    effect((onCleanup) => {
      const detail = this.detail()
      const unbindName = bindPageInstanceName(
        this.context().scopes,
        this.page,
        computedSignal(() => detail.row.get()?.title),
      )
      detail.start()
      const off = [
        mirrorHilosSignal(detail.row, this.row),
        mirrorHilosSignal(detail.unavailable, this.unavailable),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        unbindName()
        detail.dispose()
      })
    })
  }
}
