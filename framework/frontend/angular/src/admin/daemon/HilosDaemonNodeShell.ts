// Shared node entity block and page-owned body.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  inject,
  Injector,
  input,
  signal,
  viewChild,
  type TemplateRef,
} from '@angular/core'
import {
  daemonNodeLogPath,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  readHilosDaemonNodeHeading,
  readHilosDaemonNodeOverview,
  type HilosDaemonContext,
  type HilosDaemonNodeHeading,
} from '@hilos/core/admin/daemon'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosLink,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** The current node’s shared entity head and its page-owned body template. */
@Component({
  selector: 'hilos-daemon-node-shell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, NgTemplateOutlet],
  template: `
    <hilos-admin-page
      [page]="page()"
      headKind="entity"
      breadcrumbLabel="Breadcrumb"
      tabsLabel="Node pages"
      [headAction]="headAction()"
    >
      <ng-template #headVisual>
        @if (current(); as current) {
          <span
            class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
            data-id="hilos-daemon-node-visual"
          >
            <i
              [class]="
                current.heading.clustered && current.heading.state === 'silent'
                  ? 'bi bi-plug text-danger'
                  : 'bi bi-hdd-network'
              "
            ></i>
          </span>
        }
      </ng-template>
      <ng-template #headBadges>
        @if (current(); as current) {
          @let heading = current.heading;
          @if (heading.clustered) {
            @if (heading.state !== null) {
              <span
                [class]="'badge border ' + stateClass(heading.state)"
                data-id="hilos-daemon-node-state"
                >{{ formatDaemonNodeState(heading.state) }}</span
              >
            }
            <span
              class="badge bg-body-tertiary text-body border"
              data-id="hilos-daemon-node-role"
              >Role: {{ heading.role ?? '—' }}</span
            >
          } @else {
            <span
              class="badge bg-body-tertiary text-body border"
              data-id="hilos-daemon-node-solo"
              >Single installation</span
            >
          }
        }
      </ng-template>
      <ng-template #headDescription>
        @if (current()) {
          <p
            class="small text-body-secondary mb-0 d-flex flex-wrap gap-3"
            data-id="hilos-daemon-node-description"
          >
            @if (silentSince(); as since) {
              <span data-id="hilos-daemon-node-silent"
                >Silent since {{ since }}</span
              >
            }
            @if (logPath(); as path) {
              <a [hilosLink]="path" data-id="hilos-daemon-node-log"
                >Node log
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i
              ></a>
            }
          </p>
        }
      </ng-template>
      <ng-template #pageBody>
        @if (bodyInjector(); as injector) {
          <ng-container
            [ngTemplateOutlet]="body() ?? null"
            [ngTemplateOutletInjector]="injector"
          />
        }
      </ng-template>
    </hilos-admin-page>
  `,
})
export class HilosDaemonNodeShell {
  /** The project’s existing connection and page scopes. */
  readonly context = input.required<HilosDaemonContext>()
  /** The tab whose own answer is being drawn. */
  readonly page = input.required<string>()

  private readonly router = inject(HILOS_ROUTER)
  protected readonly body = contentChild<TemplateRef<unknown>>('pageBody')
  // Reprojection alone keeps the caller’s injector, outside HilosAdminPage.
  // Instantiate the caller’s template under that real shell’s providers so
  // tables and controls inherit its heading and view mode without copies.
  protected readonly bodyInjector = viewChild(HilosAdminPage, {
    read: Injector,
  })
  protected readonly current = signal<{
    nodeId: string
    heading: HilosDaemonNodeHeading
  } | null>(null)
  protected readonly formatDaemonNodeState = formatDaemonNodeState
  protected readonly headAction = computed(() =>
    this.current()?.heading.clustered
      ? {
          page: HilosPages.DAEMON,
          params: {},
          label: 'Change on the diagram',
        }
      : undefined,
  )
  protected readonly logPath = computed(() => {
    const current = this.current()
    return current
      ? daemonNodeLogPath(this.router.resolvePath, current.nodeId)
      : undefined
  })
  protected readonly silentSince = computed(() => {
    const heading = this.current()?.heading
    return heading?.clustered
      ? formatDaemonNodeSilentSince(heading, Date.now())
      : null
  })

  constructor() {
    effect((onCleanup) => {
      const { scopes } = this.context()
      const headingSource = readHilosDaemonNodeHeading(scopes)
      const overviewSource = readHilosDaemonNodeOverview(scopes)
      const current = computedSignal(() => {
        const scopePage = scopes.page()?.key
        const route = this.router.currentRoute.get()
        const heading = headingSource.get()
        if (
          scopePage !== HilosPages.DAEMON_NODE &&
          scopePage !== HilosPages.DAEMON_WORKERS &&
          scopePage !== HilosPages.DAEMON_AGENTS &&
          scopePage !== HilosPages.DAEMON_CRON &&
          scopePage !== HilosPages.DAEMON_HTTP_SERVER &&
          scopePage !== HilosPages.DAEMON_ENV &&
          scopePage !== HilosPages.DAEMON_ENV_ORPHANS
        )
          return null
        const nodeId = route.params['nodeId']
        if (
          route.page !== scopePage ||
          this.router.pageError.get() !== null ||
          typeof nodeId !== 'string' ||
          nodeId === '' ||
          heading === null
        )
          return null
        const overview = overviewSource.get()
        if (
          scopePage === HilosPages.DAEMON_NODE &&
          overview !== null &&
          overview.nodeId !== nodeId
        )
          return null
        return { nodeId, heading }
      })
      const unbindName = bindPageInstanceName(
        scopes,
        HilosPages.DAEMON_NODE,
        computedSignal(() => current.get()?.nodeId),
      )
      const stop = mirrorHilosSignal(current, this.current)
      onCleanup(() => {
        unbindName()
        stop()
        this.current.set(null)
      })
    })
  }

  /** The cluster verdict remains independent of the node’s declared role. */
  protected stateClass(state: string): string {
    switch (state) {
      case 'leader':
        return 'bg-warning-subtle text-warning-emphasis border-warning-subtle'
      case 'standby':
        return 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'
      case 'data':
        return 'bg-info-subtle text-info-emphasis border-info-subtle'
      case 'silent':
        return 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
      default:
        return 'bg-body-tertiary text-body'
    }
  }
}
