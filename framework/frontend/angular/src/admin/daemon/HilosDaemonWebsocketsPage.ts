// The addressed node's WebSocket count, carried by its page scope.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import { HILOS_ROUTER, mirrorHilosSignal } from '@hilos/angular/base'
import {
  DAEMON_NODE_STATE_SILENT,
  formatDaemonNumber,
  readHilosDaemonNodeHeading,
  readHilosDaemonWebsockets,
  type HilosDaemonContext,
  type HilosDaemonNodeHeading,
  type HilosDaemonWebsocketsData,
} from '@hilos/core/admin/daemon'
import { computedSignal, HilosPages } from '@hilos/core/base'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'

/** Render the count from this node page's single permitted answer. */
@Component({
  selector: 'hilos-daemon-websockets-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonNodeShell],
  template: `
    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <div class="row row-cols-1 row-cols-md-2 g-2 mb-3">
          <div class="col">
            <div
              class="border rounded-3 p-3 h-100"
              data-id="hilos-daemon-websockets-tile"
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-plug" aria-hidden="true"></i>
                <span class="small">WebSocket connections now</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1 text-break"
                data-id="hilos-daemon-websockets-count"
              >
                {{ count() }}
              </div>
              <!-- Every known caption holds its room in the same grid cell. -->
              <div class="hilos-stack small text-body-secondary">
                @for (message of notes; track message) {
                  <div class="invisible" aria-hidden="true" inert>
                    {{ message }}
                  </div>
                }
                <div data-id="hilos-daemon-websockets-note">{{ note() }}</div>
              </div>
            </div>
          </div>
        </div>
      </ng-template>
    </hilos-daemon-node-shell>
  `,
})
export class HilosDaemonWebsocketsPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosDaemonContext>()
  protected readonly page = HilosPages.DAEMON_WEBSOCKETS
  private readonly router = inject(HILOS_ROUTER)
  protected readonly current = signal<{
    heading: HilosDaemonNodeHeading
    websockets: HilosDaemonWebsocketsData | null
  } | null>(null)
  protected readonly data = computed(() => this.current()?.websockets)
  protected readonly count = computed(() =>
    this.data()?.dataNode ? '—' : formatDaemonNumber(this.data()?.connections),
  )
  protected readonly dataNodeNote = 'Data nodes do not accept browsers'
  protected readonly waitingNote = 'Waiting for the node report'
  protected readonly silentNote = 'At the time of the last report'
  protected readonly liveNote = 'Open on this node right now'
  protected readonly notes = [
    this.dataNodeNote,
    this.waitingNote,
    this.silentNote,
    this.liveNote,
  ]
  protected readonly note = computed(() =>
    this.data()?.dataNode
      ? this.dataNodeNote
      : this.data()?.connections == null
        ? this.waitingNote
        : this.current()?.heading.state === DAEMON_NODE_STATE_SILENT
          ? this.silentNote
          : this.liveNote,
  )

  constructor() {
    effect((onCleanup) => {
      const { scopes } = this.context()
      const websockets = readHilosDaemonWebsockets(scopes)
      const nodeHeading = readHilosDaemonNodeHeading(scopes)
      const source = computedSignal(() => {
        const route = this.router.currentRoute.get()
        const heading = nodeHeading.get()
        return scopes.page()?.key === HilosPages.DAEMON_WEBSOCKETS &&
          route.page === HilosPages.DAEMON_WEBSOCKETS &&
          this.router.pageError.get() === null &&
          typeof route.params['nodeId'] === 'string' &&
          route.params['nodeId'] !== '' &&
          heading !== null
          ? { heading, websockets: websockets.get() }
          : null
      })
      const stop = mirrorHilosSignal(source, this.current)
      onCleanup(() => {
        stop()
        this.current.set(null)
      })
    })
  }
}
