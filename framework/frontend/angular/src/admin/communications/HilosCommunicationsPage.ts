// HilosCommunicationsPage — the framework Hilos communications hub page
// (HilosPages.COMMUNICATIONS): the delivery-channels table inside the admin shell.
// One row per registered channel (built from the project's channel registry, not a
// hardcoded list), showing its enablement toggle, whether it is fully configured,
// its transport driver, and a link to its configuration page. The table, the row
// view-model, and the enablement round-trip are the core headless's
// (createHilosChannelsTable / createHilosCommunicationsActions); this view owns only
// the markup, so a project mounts it by passing its HilosCommunicationsContext.
// The toggle is a tracked action ("client action = loading + signal, never
// fire-forget"): it dispatches the shared set action with the `enabled` field, the
// outcome toasts, and the row redraws from the reactive table's snapshot signal —
// there is no new server->client signal. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import {
  CHANNEL_ENABLED_FIELD,
  HilosPages,
  createHilosChannelsTable,
  createHilosCommunicationsActions,
  resolveHilosPath,
} from '@hilos/core'
import type {
  HilosChannelRow,
  HilosCommunicationsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

const COLUMNS: HilosTableColumn[] = [
  { key: 'channel', label: 'Channel', sortable: true },
  { key: 'enabled', label: 'Enabled' },
  { key: 'configured', label: 'Configured' },
  { key: 'driver', label: 'Driver', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/** The framework communications hub page: the delivery-channels table. */
@Component({
  selector: 'hilos-communications-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, HilosLink],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table
        label="Delivery channels"
        [controller]="channels().controller"
        [columns]="columns"
        [searchable]="true"
        searchPlaceholder="Search channels…"
        emptyText="No delivery channels registered."
      >
        <ng-template #row let-row>
          <td>
            <div class="fw-semibold">{{ row.label }}</div>
            <code class="small text-body-secondary">{{ row.channel }}</code>
          </td>
          <td>
            <div class="form-check form-switch mb-0">
              <input
                [id]="'hilos-channel-enabled-' + row.channel"
                type="checkbox"
                class="form-check-input"
                role="switch"
                [checked]="row.enabled"
                [disabled]="toggle.busy()"
                [attr.aria-label]="'Enable ' + row.label"
                [attr.data-id]="'hilos-channel-enabled-' + row.channel"
                (change)="onToggle(row, $event)"
              />
            </div>
          </td>
          <td>
            @if (row.configured) {
              <span class="badge text-bg-success-subtle text-success-emphasis">
                Configured
              </span>
            } @else {
              <span
                class="badge text-bg-warning-subtle text-warning-emphasis"
                [attr.title]="row.missingFields + ' field(s) not set'"
              >
                {{ row.missingFields }} missing
              </span>
            }
          </td>
          <td>
            <code class="small">{{ row.driver }}</code>
          </td>
          <td class="text-end">
            <a
              hilosLink
              [hilosLink]="channelPath(row)"
              class="btn btn-sm btn-outline-primary"
              [attr.data-id]="'hilos-channel-configure-' + row.channel"
            >
              Configure
            </a>
          </td>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosCommunicationsContext>()

  protected readonly page = HilosPages.COMMUNICATIONS
  protected readonly columns = COLUMNS

  protected readonly channels = computed(() =>
    createHilosChannelsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosCommunicationsActions(this.context()),
  )

  // One tracked runner for the enablement toggles: the switch redraws from the
  // echoed row, so a single in-flight guard across rows is enough (and the busy
  // flag disables every switch while one write is settling).
  protected readonly toggle = createHilosTrackedAction()

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const channels = this.channels()
      channels.start()
      onCleanup(() => channels.dispose())
    })
  }

  /** The channel's configuration page path (its {channelId} route param is the name). */
  protected channelPath(row: HilosChannelRow): string {
    return resolveHilosPath(HilosPages.COMMUNICATIONS_CHANNEL, {
      channelId: row.channel,
    })
  }

  // Dispatch the enablement write as a tracked action; the toggled row redraws from
  // the table's snapshot delta, so nothing is set optimistically here.
  protected onToggle(row: HilosChannelRow, event: Event): void {
    const next = (event.target as HTMLInputElement).checked
    void this.toggle.run(
      this.actions().sendChannelSet(row.channel, CHANNEL_ENABLED_FIELD, next),
    )
  }
}
