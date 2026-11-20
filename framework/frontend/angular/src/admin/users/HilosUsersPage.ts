// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic, the row view-model, and the
// frame the table declares — its columns, search, and empty words — are the core
// headless's (createHilosUsersTable / HilosUserRow); this view owns only the cell
// markup, so a project mounts it by passing its HilosUsersContext. The framework
// owns every cell except the trailing actions cell, which a project fills through
// an `<ng-template #rowActions let-row>` (e.g. a link to the detail page); the
// takeover lives on the person's card since HIL-1170. The bar's "Past deadline
// on" filter narrows the list to the people past one legal document's deadline
// and lives in the address too (`/hilos/users/terms`, HIL-945): the legal
// section's root links its count there, and changing the filter rewrites the
// address. An account folded into another one wears a gray "Merged" badge
// beside its name (HIL-1292). The presence badge says one of three words
// (HIL-1566) and holds the room of the widest one, so a presence changing live
// neither moves the row nor spills out of its column. An account being erased
// wears "Being deleted" beside its name (a merged one stays Merged), and every
// account of its circle carries a caption above its row bar: the summary, then
// the parts not done, the owners awaited, and the refusals, three entries a
// line and "+N more" (hilosUsersErasureCaption, HIL-1962). A running merge also
// carries a caption above its row bar with summary, expected parts, waits and
// refusal (HIL-2011); erasure takes priority over merge. A bar about other
// work gets no caption. Bootstrap classes only (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { HilosPages } from '@hilos/core/base'
import {
  HILOS_PRESENCE_WIDEST_LABEL,
  HILOS_USER_ERASURE_COPY,
  HILOS_USERS_MERGE_COPY,
  createHilosUsersTable,
  hilosPresenceBadge,
  hilosStandingBadge,
} from '@hilos/core/admin/users'
import type {
  HilosUserRow,
  HilosUsersContext,
  HilosUsersErasureCaption,
  HilosUsersMergeCaption,
} from '@hilos/core/admin/users'

import {
  HilosAdminPage,
  HilosTableCell,
  HilosTableText,
  HilosViewportTable,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** The context a HilosUsersPage `#rowActions` template receives. */
export interface UsersRowActionsContext {
  /** The resolved user row (the template's implicit `let-row`). */
  $implicit: HilosUserRow
}

/** The framework users admin page: the searchable, sortable users table. */
@Component({
  selector: 'hilos-users-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosTableCell,
    HilosTableText,
    HilosViewportTable,
    NgTemplateOutlet,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="users().controller">
        <ng-template hilosTableCell="id" let-row>{{ row.id }}</ng-template>
        <ng-template hilosTableCell="name" let-row>
          <div class="d-flex align-items-start gap-1">
            <div class="flex-grow-1">
              <hilos-table-text [value]="row.name" />
            </div>
            @if (row.merged && mergedBadge !== null) {
              <span
                class="badge flex-shrink-0"
                [class]="'text-bg-' + mergedBadge.tone"
                data-id="hilos-users-merged"
                ><i
                  class="bi me-1"
                  [class]="mergedBadge.icon"
                  aria-hidden="true"
                ></i
                >{{ mergedBadge.label }}</span
              >
            } @else if (erasureOf(row.id) && erasingBadge !== null) {
              <span
                class="badge flex-shrink-0"
                [class]="'text-bg-' + erasingBadge.tone"
                data-id="hilos-users-erasing"
                ><i
                  class="bi me-1"
                  [class]="erasingBadge.icon"
                  aria-hidden="true"
                ></i
                >{{ erasingBadge.label }}</span
              >
            }
          </div>
        </ng-template>
        <ng-template hilosTableCell="presence" let-row>
          @let badge = presenceBadge(row.presence);
          <span class="hilos-stack d-inline-grid">
            <span class="badge invisible" aria-hidden="true">{{
              widestPresence
            }}</span>
            <span class="badge" [class]="'text-bg-' + badge.tone">{{
              badge.label
            }}</span>
          </span>
        </ng-template>
        <ng-template hilosTableCell="openSessionCount" let-row>{{
          row.openSessionCount ?? '—'
        }}</ng-template>
        <ng-template hilosTableCell="lastActivity" let-row>{{
          row.lastActivity ?? '—'
        }}</ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (rowActions(); as tpl) {
            <ng-container
              [ngTemplateOutlet]="tpl"
              [ngTemplateOutletContext]="{ $implicit: row }"
            />
          }
        </ng-template>
        <ng-template #rowProgress let-rowKey="rowKey">
          @if (erasureOf(rowKey); as caption) {
            <div data-id="hilos-users-erasure-summary">
              {{ caption.summary }}
            </div>
            @if (caption.expected.shown.length > 0) {
              <div data-id="hilos-users-erasure-expected">
                {{ erasureCopy.expected }}:
                @for (
                  part of caption.expected.shown;
                  track part.key;
                  let index = $index
                ) {
                  <ng-container
                    >{{ index > 0 ? '; ' : ''
                    }}<code>{{ part.participant }}</code
                    >{{
                      part.account !== null ? ' · #' + part.account : ''
                    }}</ng-container
                  >
                }
                @if (caption.expected.more !== null) {
                  <ng-container>{{
                    '; ' + caption.expected.more
                  }}</ng-container>
                }
              </div>
            }
            @if (caption.waits.shown.length > 0) {
              <div data-id="hilos-users-erasure-waits">
                {{ erasureCopy.waits }}:
                @for (
                  wait of caption.waits.shown;
                  track wait.key;
                  let index = $index
                ) {
                  <ng-container
                    >{{ index > 0 ? '; ' : ''
                    }}<code>{{ wait.participant }}</code
                    >{{
                      wait.account !== null ? ' · #' + wait.account : ''
                    }}</ng-container
                  >
                }
                @if (caption.waits.more !== null) {
                  <ng-container>{{ '; ' + caption.waits.more }}</ng-container>
                }
              </div>
            }
            @if (caption.refusals.shown.length > 0) {
              <div data-id="hilos-users-erasure-refusals">
                {{ erasureCopy.refusals }}:
                @for (
                  refusal of caption.refusals.shown;
                  track refusal.key;
                  let index = $index
                ) {
                  <ng-container
                    >{{ index > 0 ? '; ' : ''
                    }}{{
                      refusal.account !== null
                        ? '#' + refusal.account + ' · '
                        : ''
                    }}{{ refusal.text }}</ng-container
                  >
                }
                @if (caption.refusals.more !== null) {
                  <ng-container>{{
                    '; ' + caption.refusals.more
                  }}</ng-container>
                }
              </div>
            }
          } @else if (mergeOf(rowKey); as caption) {
            <div data-id="hilos-users-merge-summary">
              {{ caption.summary }}
            </div>
            @if (caption.expected.shown.length > 0) {
              <div data-id="hilos-users-merge-expected">
                {{ mergeCopy.expected }}:
                @for (
                  part of caption.expected.shown;
                  track part.key;
                  let index = $index
                ) {
                  <ng-container
                    >{{ index > 0 ? '; ' : ''
                    }}<code>{{ part.participant }}</code
                    >{{
                      part.account !== null ? ' · #' + part.account : ''
                    }}</ng-container
                  >
                }
                @if (caption.expected.more !== null) {
                  <ng-container>{{
                    '; ' + caption.expected.more
                  }}</ng-container>
                }
              </div>
            }
            @if (caption.waits.shown.length > 0) {
              <div data-id="hilos-users-merge-waits">
                {{ mergeCopy.waits }}:
                @for (
                  wait of caption.waits.shown;
                  track wait.key;
                  let index = $index
                ) {
                  <ng-container
                    >{{ index > 0 ? '; ' : ''
                    }}<code>{{ wait.participant }}</code
                    >{{
                      wait.account !== null ? ' · #' + wait.account : ''
                    }}</ng-container
                  >
                }
                @if (caption.waits.more !== null) {
                  <ng-container>{{ '; ' + caption.waits.more }}</ng-container>
                }
              </div>
            }
            @if (caption.refusal !== null) {
              <div data-id="hilos-users-merge-refusal">
                {{ caption.refusal }}
              </div>
            }
          }
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosUsersPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USERS
  // The two badges the list draws: the merged standing and the erasing standing,
  // read off the row's merge slot and the live erasure bars rather than off a
  // standing the list does not carry.
  protected readonly mergedBadge = hilosStandingBadge('merged')
  protected readonly erasingBadge = hilosStandingBadge('erasing')
  protected readonly erasureCopy = HILOS_USER_ERASURE_COPY
  protected readonly erasures = signal<
    ReadonlyMap<string, HilosUsersErasureCaption>
  >(new Map())
  protected readonly mergeCopy = HILOS_USERS_MERGE_COPY
  protected readonly mergeCaptions = signal<
    ReadonlyMap<string, HilosUsersMergeCaption>
  >(new Map())
  // The presence badge: the word and tone of a row's presence, drawn over an
  // invisible twin of the widest word.
  protected readonly presenceBadge = hilosPresenceBadge
  protected readonly widestPresence = HILOS_PRESENCE_WIDEST_LABEL
  // The lapsed filter is read from the address the page opened on and written
  // back as it changes; mounted without a navigator, the list opens whole and
  // leaves the address alone.
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  protected readonly users = computed(() =>
    createHilosUsersTable(this.context(), this.router ?? undefined),
  )
  protected readonly rowActions =
    contentChild<TemplateRef<UsersRowActionsContext>>('rowActions')

  protected erasureOf(
    rowKey: string | number,
  ): HilosUsersErasureCaption | undefined {
    return this.erasures().get(String(rowKey))
  }

  protected mergeOf(
    rowKey: string | number,
  ): HilosUsersMergeCaption | undefined {
    return this.mergeCaptions().get(String(rowKey))
  }

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const users = this.users()
      users.start()
      onCleanup(mirrorHilosSignal(users.erasures, this.erasures))
      onCleanup(mirrorHilosSignal(users.mergeCaptions, this.mergeCaptions))
      onCleanup(() => users.dispose())
    })
  }
}
