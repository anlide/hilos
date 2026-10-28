<script setup lang="ts">
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_SECTION,
  readHilosChangeLogTable,
  changeLogTableColumnCounts,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTabLinks, hilosTimeZone } from '@hilos/core/base'
import { computed, inject } from 'vue'

import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosChangeLogTableShell from './HilosChangeLogTableShell.vue'

const props = defineProps<{ context: HilosChangeLogReceiptContext }>()
const pageTable = useSignal(
  props.context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
)
const table = computed(() => readHilosChangeLogTable(pageTable.value))
const router = inject(hilosRouterKey)!
const timeZone = useSignal(hilosTimeZone)
function lastChange(at: string | null): string {
  void timeZone.value
  return formatChangeLogTableLastChange(at)
}
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const tabs = computed(() =>
  hilosTabLinks(
    identity.value?.pageTabs ?? [],
    route.value.params,
    router.resolvePath,
  ),
)
const pagePath = (page: string): string | undefined =>
  tabs.value.find((tab) => tab.page === page)?.to
</script>

<template>
  <HilosChangeLogTableShell
    :context="context"
    :page="HilosPages.CHANGE_LOG_TABLE"
  >
    <template v-if="table">
      <p v-if="!table.journaled" class="alert alert-secondary small py-2">
        {{ copy.untracked }}
      </p>
      <div class="row row-cols-1 row-cols-sm-3 g-3 mb-3">
        <div
          v-if="
            table.journaled && pagePath(HilosPages.CHANGE_LOG_TABLE_HISTORY)
          "
          class="col"
        >
          <HilosLink
            :to="pagePath(HilosPages.CHANGE_LOG_TABLE_HISTORY)!"
            class="card h-100 text-decoration-none link-body-emphasis"
            data-id="hilos-change-log-table-link-history"
          >
            <div class="card-body p-3">
              <h2
                class="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-activity" aria-hidden="true"></i>Changes in 24
                hours
              </h2>
              <div class="fs-4 lh-1 mb-1">
                {{ formatChangeLogTableChanges(table.changesLast24h) }}
              </div>
              <div class="small text-body-secondary">
                Last change: {{ lastChange(table.lastChangeAt) }}
              </div>
            </div>
          </HilosLink>
        </div>
        <div
          v-if="
            table.journaled && pagePath(HilosPages.CHANGE_LOG_TABLE_COLUMNS)
          "
          class="col"
        >
          <HilosLink
            :to="pagePath(HilosPages.CHANGE_LOG_TABLE_COLUMNS)!"
            class="card h-100 text-decoration-none link-body-emphasis"
            data-id="hilos-change-log-table-link-columns"
          >
            <div class="card-body p-3">
              <h2
                class="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-layout-three-columns" aria-hidden="true"></i
                >Columns
              </h2>
              <div class="fs-4 lh-1 mb-1">{{ table.columns.length }}</div>
              <div class="small text-body-secondary">
                {{
                  formatChangeLogColumnCounts(changeLogTableColumnCounts(table))
                }}
              </div>
            </div>
          </HilosLink>
        </div>
        <div v-if="pagePath(HilosPages.CHANGE_LOG_TABLE_TRIGGERS)" class="col">
          <HilosLink
            :to="pagePath(HilosPages.CHANGE_LOG_TABLE_TRIGGERS)!"
            class="card h-100 text-decoration-none link-body-emphasis"
            data-id="hilos-change-log-table-link-triggers"
          >
            <div class="card-body p-3">
              <h2
                class="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-lightning-charge" aria-hidden="true"></i
                >Triggers
              </h2>
              <div class="fs-4 lh-1 mb-1">{{ table.triggers.length }}</div>
              <div class="small text-body-secondary">
                Service {{ table.triggers.length }}
              </div>
            </div>
          </HilosLink>
        </div>
      </div>
    </template>
  </HilosChangeLogTableShell>
</template>
