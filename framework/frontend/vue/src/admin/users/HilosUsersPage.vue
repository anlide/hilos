<!-- HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
users table inside the admin shell. All table logic, the row view-model, and what
the table declares about its frame — columns, search, empty state — are the core
headless's (createHilosUsersTable / HilosUserRow); this view owns only the markup,
so a project mounts it by passing its HilosUsersContext. The framework owns every
cell except the trailing actions cell, which a project fills through the
`#row-actions` slot (e.g. a link to the detail page); the takeover lives on the
person's card since HIL-1170. The bar's "Past deadline on" filter narrows the
list to the people past one legal document's deadline and lives in the address
too (`/hilos/users/terms`, HIL-945): the legal section's root links its count
there, and changing the filter rewrites the address. An account folded into
another one wears "Merged" instead of the Blocked and You badges beside its
name; the person's id remains available below it. The presence badge says one of
three words (HIL-1566) and holds the room of the widest one, so a presence changing
live neither moves the row nor spills out of its column. An account being erased
wears "Being deleted" instead of the Blocked and You badges (a merged one stays
"Merged"), and every account of its circle carries a caption above its row bar:
the summary, then the parts not done, the owners awaited and the refusals, three
entries a line (hilosUsersErasureCaption, HIL-1956). A bar about other work gets
no caption.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosUsersTable,
  HILOS_PRESENCE_WIDEST_LABEL,
  HILOS_USER_ERASURE_COPY,
  HILOS_USERS_LIST_COPY,
  hilosPresenceBadge,
  hilosStandingBadge,
  type HilosUserRow,
  type HilosUsersContext,
} from '@hilos/core/admin/users'
import { HilosPages } from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosTableText from '../../HilosTableText.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

defineSlots<{
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  'row-actions'(props: { row: HilosUserRow }): unknown
}>()

// The lapsed filter is read from the address the page opened on and written
// back as it changes; mounted without a navigator, the list opens whole and
// leaves the address alone.
const router = inject(hilosRouterKey, undefined)
const users = createHilosUsersTable(props.context, router, { idInName: true })
const usersTable = users.controller
const currentUserId = useSignal(users.currentUserId)
const erasures = useSignal(users.erasures)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => users.start())
onUnmounted(() => users.dispose())

// The list reads its flags directly, without composing a card's standing.
const mergedBadge = hilosStandingBadge('merged')
const blockedBadge = hilosStandingBadge('blocked')
const erasingBadge = hilosStandingBadge('erasing')
</script>

<template>
  <HilosAdminPage :page="HilosPages.USERS" head-kind="section">
    <HilosViewportTable :controller="usersTable">
      <template #cell-name="{ row }">
        <div class="d-flex align-items-start gap-1">
          <div class="flex-grow-1">
            <HilosTableText :value="row.name" />
          </div>
          <span
            v-if="row.merged && mergedBadge !== null"
            class="badge flex-shrink-0"
            :class="`text-bg-${mergedBadge.tone}`"
            data-id="hilos-users-merged"
            ><i class="bi me-1" :class="mergedBadge.icon" aria-hidden="true"></i
            >{{ mergedBadge.label }}</span
          >
          <span
            v-else-if="
              !row.merged &&
              erasures.has(String(row.id)) &&
              erasingBadge !== null
            "
            class="badge flex-shrink-0"
            :class="`text-bg-${erasingBadge.tone}`"
            data-id="hilos-users-erasing"
            ><i
              class="bi me-1"
              :class="erasingBadge.icon"
              aria-hidden="true"
            ></i
            >{{ erasingBadge.label }}</span
          >
          <template v-else-if="!row.merged">
            <span
              v-if="row.block && blockedBadge !== null"
              class="badge flex-shrink-0"
              :class="`text-bg-${blockedBadge.tone}`"
              ><i
                class="bi me-1"
                :class="blockedBadge.icon"
                aria-hidden="true"
              ></i
              >{{ blockedBadge.label }}</span
            >
            <span
              v-if="row.id === currentUserId"
              class="badge bg-body-secondary text-body-emphasis border flex-shrink-0"
              >{{ HILOS_USERS_LIST_COPY.you }}</span
            >
          </template>
        </div>
        <div class="small text-body-secondary">#{{ row.id }}</div>
      </template>
      <template #cell-presence="{ row }">
        <span class="hilos-stack d-inline-grid">
          <span class="badge invisible" aria-hidden="true">{{
            HILOS_PRESENCE_WIDEST_LABEL
          }}</span>
          <span
            class="badge"
            :class="`text-bg-${hilosPresenceBadge(row.presence).tone}`"
            >{{ hilosPresenceBadge(row.presence).label }}</span
          >
        </span>
      </template>
      <template #cell-openSessionCount="{ row }">{{
        row.openSessionCount ?? '—'
      }}</template>
      <template #cell-lastActivity="{ row }">{{
        row.lastActivity ?? '—'
      }}</template>
      <template #cell-actions="{ row }">
        <slot name="row-actions" :row="row" />
      </template>
      <template #row-progress="{ rowKey }">
        <template
          v-for="(caption, captionIndex) in [erasures.get(rowKey)]"
          :key="captionIndex"
        >
          <template v-if="caption !== undefined">
            <div data-id="hilos-users-erasure-summary">
              {{ caption.summary }}
            </div>
            <div
              v-if="caption.expected.shown.length > 0"
              data-id="hilos-users-erasure-expected"
            >
              {{ HILOS_USER_ERASURE_COPY.expected }}:
              <template
                v-for="(part, index) in caption.expected.shown"
                :key="part.key"
                ><template v-if="index > 0">; </template
                ><code>{{ part.participant }}</code
                ><template v-if="part.account !== null">
                  · #{{ part.account }}</template
                ></template
              ><template v-if="caption.expected.more !== null"
                >; {{ caption.expected.more }}</template
              >
            </div>
            <div
              v-if="caption.waits.shown.length > 0"
              data-id="hilos-users-erasure-waits"
            >
              {{ HILOS_USER_ERASURE_COPY.waits }}:
              <template
                v-for="(wait, index) in caption.waits.shown"
                :key="wait.key"
                ><template v-if="index > 0">; </template
                ><code>{{ wait.participant }}</code
                ><template v-if="wait.account !== null">
                  · #{{ wait.account }}</template
                ></template
              ><template v-if="caption.waits.more !== null"
                >; {{ caption.waits.more }}</template
              >
            </div>
            <div
              v-if="caption.refusals.shown.length > 0"
              data-id="hilos-users-erasure-refusals"
            >
              {{ HILOS_USER_ERASURE_COPY.refusals }}:
              <template
                v-for="(refusal, index) in caption.refusals.shown"
                :key="refusal.key"
                ><template v-if="index > 0">; </template
                ><template v-if="refusal.account !== null"
                  >#{{ refusal.account }} · </template
                >{{ refusal.text }}</template
              ><template v-if="caption.refusals.more !== null"
                >; {{ caption.refusals.more }}</template
              >
            </div>
          </template>
        </template>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
