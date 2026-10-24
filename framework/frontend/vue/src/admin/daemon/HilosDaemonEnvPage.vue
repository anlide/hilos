<!-- Environment and .env file inspection of one Daemon node. The page scope
carries the complete parsed environment and silence indicator; server viewports
supply the catalog-key and orphan rows. -->
<script setup lang="ts">
import {
  createHilosDaemonEnvKeysTable,
  createHilosDaemonEnvOrphansTable,
  daemonEnvSourceBadge,
  daemonEnvSummary,
  DAEMON_ENV_FILTER_ATTENTION,
  DAEMON_ENV_LADDER,
  DAEMON_ENV_PER_NODE_HINT,
  DAEMON_ENV_SENSITIVE_HINT,
  formatDaemonEnvDeclaredBy,
  formatDaemonEnvDrift,
  formatDaemonEnvDriftNotice,
  formatDaemonEnvRequired,
  formatDaemonEnvSimilarTo,
  formatDaemonEnvValue,
  readHilosDaemonEnvPage,
  type HilosDaemonEnvContext,
} from '@hilos/core/admin/daemon'
import { HilosPages, subscribeSignal } from '@hilos/core/base'
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  shallowRef,
  watch,
} from 'vue'

import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeShell from './HilosDaemonNodeShell.vue'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosDaemonEnvContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonEnvPage requires a provided Hilos router.')
}

const route = useSignal(router.currentRoute)
const nodeId = computed(
  () => (route.value.params.nodeId as string | undefined) ?? '',
)

const keys = shallowRef(
  createHilosDaemonEnvKeysTable(props.context, nodeId.value),
)
const orphans = shallowRef(
  createHilosDaemonEnvOrphansTable(props.context, nodeId.value),
)

const page = useSignal(readHilosDaemonEnvPage(props.context.scopes))
const nodeSilent = computed(() => page.value.nodeSilent)
const summary = computed(() =>
  page.value.environment ? daemonEnvSummary(page.value.environment.keys) : null,
)

const keysFilter = shallowRef(keys.value.controller.filter.get())
let unwatchFilter: (() => void) | undefined

function bindFilterWatch(): void {
  unwatchFilter?.()
  keysFilter.value = keys.value.controller.filter.get()
  unwatchFilter = subscribeSignal(keys.value.controller.filter, (val) => {
    keysFilter.value = val
  })
}
bindFilterWatch()

let mounted = false
watch(
  nodeId,
  (nextNodeId) => {
    keys.value.dispose()
    orphans.value.dispose()
    keys.value = createHilosDaemonEnvKeysTable(props.context, nextNodeId)
    orphans.value = createHilosDaemonEnvOrphansTable(props.context, nextNodeId)
    bindFilterWatch()
    if (mounted) {
      keys.value.start()
      orphans.value.start()
    }
  },
  { flush: 'sync' },
)

onMounted(() => {
  mounted = true
  keys.value.start()
  orphans.value.start()
})

onUnmounted(() => {
  mounted = false
  unwatchFilter?.()
  keys.value.dispose()
  orphans.value.dispose()
})
</script>

<template>
  <HilosDaemonNodeShell :context="context" :page="HilosPages.DAEMON_ENV">
    <p
      v-if="nodeSilent"
      class="small text-body-secondary"
      data-id="hilos-daemon-env-silent"
    >
      This node is silent. Only the node itself reads its environment, so
      nothing is shown until it answers again.
    </p>

    <div
      v-if="!nodeSilent && summary !== null"
      class="row row-cols-2 row-cols-md-4 g-2 mb-3"
    >
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-list-ul" aria-hidden="true"></i
            ><span class="small">Keys in catalog</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            data-id="hilos-daemon-env-keys"
          >
            {{ summary.keyCount }}
          </div>
          <div class="small text-body-secondary">
            Declared by the framework and the project
          </div>
        </div>
      </div>
      <div class="col">
        <div
          class="border rounded-3 p-3 h-100"
          :class="{ 'border-danger': summary.missingRequired.length > 0 }"
        >
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i
            ><span class="small">Required without a value</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            :class="{ 'text-danger': summary.missingRequired.length > 0 }"
            data-id="hilos-daemon-env-missing"
          >
            {{ summary.missingRequired.length }}
          </div>
          <div class="small text-body-secondary">
            {{
              summary.missingRequired.length > 0
                ? 'The node will not start with this set'
                : 'All present'
            }}
          </div>
        </div>
      </div>
      <div class="col">
        <div
          class="border rounded-3 p-3 h-100"
          :class="{ 'border-warning': summary.fromExample.length > 0 }"
        >
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-file-earmark-text" aria-hidden="true"></i
            ><span class="small">From the sample</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            :class="{ 'text-warning': summary.fromExample.length > 0 }"
            data-id="hilos-daemon-env-example"
          >
            {{ summary.fromExample.length }}
          </div>
          <div class="small text-body-secondary">
            {{
              summary.fromExample.length > 0
                ? '.env.example instead of a setting'
                : 'The sample is not read'
            }}
          </div>
        </div>
      </div>
      <div class="col">
        <div
          class="border rounded-3 p-3 h-100"
          :class="{ 'border-warning': summary.drifted.length > 0 }"
        >
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i
            ><span class="small">Drifted from disk</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            :class="{ 'text-warning': summary.drifted.length > 0 }"
            data-id="hilos-daemon-env-drift"
          >
            {{ summary.drifted.length }}
          </div>
          <div class="small text-body-secondary">
            {{
              summary.drifted.length > 0
                ? 'The file was edited, the process was not restarted'
                : 'The file and the process agree'
            }}
          </div>
        </div>
      </div>
    </div>

    <template v-if="!nodeSilent && summary !== null">
      <div
        v-if="summary.missingRequired.length > 0"
        class="alert alert-danger d-flex align-items-start gap-3 py-2 small"
        data-id="hilos-daemon-env-alarm-missing"
      >
        <i
          class="bi bi-exclamation-octagon fs-5 flex-shrink-0"
          aria-hidden="true"
        ></i>
        <div>
          <template v-for="(k, index) in summary.missingRequired" :key="k"
            ><code>{{ k }}</code
            ><template v-if="index < summary.missingRequired.length - 1"
              >,
            </template></template
          >
          — the catalog
          {{
            summary.missingRequired.length > 1
              ? 'declares them required, and they have no value'
              : 'declares it required, and it has no value'
          }}
          in the launch environment or in the files. The node's next start — a
          planned restart, a move, a crash — will be refused.
        </div>
      </div>

      <div
        v-if="summary.fromExample.length > 0"
        class="alert alert-warning d-flex align-items-start gap-3 py-2 small"
        data-id="hilos-daemon-env-alarm-example"
      >
        <i
          class="bi bi-file-earmark-text fs-5 flex-shrink-0"
          aria-hidden="true"
        ></i>
        <div>
          {{
            summary.fromExample.length > 1 ? 'The values of' : 'The value of'
          }}
          <template v-for="(k, index) in summary.fromExample" :key="k"
            ><code>{{ k }}</code
            ><template v-if="index < summary.fromExample.length - 1"
              >,
            </template></template
          >
          came from <code>.env.example</code>.
        </div>
      </div>

      <template v-for="row in summary.drifted" :key="row.key">
        <div
          v-if="formatDaemonEnvDriftNotice(row) !== null"
          class="alert alert-warning d-flex align-items-start gap-3 py-2 small"
          :data-id="`hilos-daemon-env-alarm-drift-${row.key}`"
        >
          <i
            class="bi bi-arrow-repeat fs-5 flex-shrink-0"
            aria-hidden="true"
          ></i>
          <div>
            In the process <code>{{ row.key }}</code> is
            <code>{{ formatDaemonEnvDriftNotice(row)?.process }}</code
            >; on disk it is already
            <code>{{ formatDaemonEnvDriftNotice(row)?.disk }}</code
            >. A live daemon does not pick up a file edit: it applies when the
            node restarts.
          </div>
        </div>
      </template>
    </template>

    <HilosViewportTable :key="nodeId" :controller="keys.controller">
      <template #cell-key="{ row }">
        <code class="fw-semibold">{{ row.key }}</code>
        <template v-if="row.perNode">
          <i
            class="bi bi-hdd-network text-body-secondary ms-1"
            :title="DAEMON_ENV_PER_NODE_HINT"
            aria-hidden="true"
          ></i>
          <span class="visually-hidden">{{ DAEMON_ENV_PER_NODE_HINT }}</span>
        </template>
        <template v-if="row.sensitive">
          <i
            class="bi bi-shield-lock text-body-secondary ms-1"
            :title="DAEMON_ENV_SENSITIVE_HINT"
            aria-hidden="true"
          ></i>
          <span class="visually-hidden">{{ DAEMON_ENV_SENSITIVE_HINT }}</span>
        </template>
      </template>

      <template #cell-type="{ row }">
        <span class="small text-body-secondary">{{ row.type }}</span>
      </template>

      <template #cell-value="{ row }">
        <span class="d-inline-flex align-items-center gap-2 flex-wrap">
          <template
            v-for="(val, index) in [
              formatDaemonEnvValue(row.value, row.type, row.sensitive),
            ]"
            :key="index"
          >
            <HilosHiddenMark v-if="val.kind === 'hidden'" />
            <span v-else-if="val.kind === 'secret'" class="text-body-secondary">
              <i class="bi bi-key me-1" aria-hidden="true"></i>{{ val.text }}
            </span>
            <span
              v-else-if="val.kind === 'none'"
              :class="row.required ? 'text-danger' : 'text-body-secondary'"
            >
              —
            </span>
            <span
              v-else-if="val.kind === 'empty'"
              class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              title="An empty string is a value"
            >
              empty
            </span>
            <input
              v-else-if="val.kind === 'boolean'"
              type="checkbox"
              class="form-check-input mt-0"
              disabled
              tabindex="-1"
              :checked="val.on"
              :aria-label="val.on ? 'On' : 'Off'"
            />
            <code
              v-else-if="val.kind === 'text'"
              :class="{ 'fst-italic': val.numeric }"
            >
              {{ val.text }}
            </code>
          </template>
          <span
            class="badge rounded-pill"
            :class="[
              `bg-${daemonEnvSourceBadge(row.source).tone}-subtle`,
              `text-${daemonEnvSourceBadge(row.source).tone}-emphasis`,
              `border border-${daemonEnvSourceBadge(row.source).tone}-subtle`,
            ]"
            :title="daemonEnvSourceBadge(row.source).hint"
          >
            {{ daemonEnvSourceBadge(row.source).label }}
          </span>
          <span
            v-if="formatDaemonEnvDrift(row) !== null"
            class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"
          >
            {{ formatDaemonEnvDrift(row) }}
          </span>
        </span>
      </template>

      <template #cell-required="{ row }">
        <template v-if="row.required">
          <i class="bi bi-check-lg text-body-secondary" aria-hidden="true"></i>
          <span class="visually-hidden">{{
            formatDaemonEnvRequired(row)
          }}</span>
        </template>
        <template v-else>
          <span class="text-body-secondary" aria-hidden="true">·</span>
          <span class="visually-hidden">Optional</span>
        </template>
      </template>

      <template #cell-declaredBy="{ row }">
        <span class="small text-body-secondary">{{
          formatDaemonEnvDeclaredBy(row)
        }}</span>
      </template>

      <template #empty>
        <span v-if="nodeSilent" data-id="hilos-daemon-env-keys-empty-silent">
          Nothing is read while the node is silent.
        </span>
        <span
          v-else-if="keysFilter[DAEMON_ENV_FILTER_ATTENTION] === true"
          data-id="hilos-daemon-env-keys-empty-attention"
        >
          No key needs attention.
        </span>
        <template v-else>No rows.</template>
      </template>
    </HilosViewportTable>

    <h2
      id="hilos-daemon-env-ladder-heading"
      class="h6 text-uppercase text-body-secondary mb-2 mt-4"
    >
      Reading ladder
    </h2>
    <div
      class="border rounded-3 overflow-hidden mb-3"
      data-id="hilos-daemon-env-ladder"
    >
      <div class="table-responsive">
        <table
          class="table table-sm align-middle mb-0"
          aria-labelledby="hilos-daemon-env-ladder-heading"
        >
          <tbody>
            <tr v-for="(source, ix) in DAEMON_ENV_LADDER" :key="source">
              <td
                class="text-center text-body-secondary small text-nowrap px-3"
              >
                {{ source === 'missing' ? '' : ix + 1 }}
              </td>
              <td class="text-nowrap">
                <span
                  class="badge rounded-pill"
                  :class="[
                    `bg-${daemonEnvSourceBadge(source).tone}-subtle`,
                    `text-${daemonEnvSourceBadge(source).tone}-emphasis`,
                    `border border-${daemonEnvSourceBadge(source).tone}-subtle`,
                  ]"
                  :title="daemonEnvSourceBadge(source).hint"
                >
                  {{ daemonEnvSourceBadge(source).label }}
                </span>
              </td>
              <td class="small text-body-secondary text-break w-100">
                {{ daemonEnvSourceBadge(source).hint }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <HilosViewportTable :key="nodeId" :controller="orphans.controller">
      <template #cell-key="{ row }">
        <code class="fw-semibold">{{ row.key }}</code>
      </template>

      <template #cell-value="{ row }">
        <span class="d-inline-flex align-items-center gap-2 flex-wrap">
          <template
            v-for="(val, index) in [
              formatDaemonEnvValue(row.value, 'string', true),
            ]"
            :key="index"
          >
            <HilosHiddenMark v-if="val.kind === 'hidden'" />
            <span v-else-if="val.kind === 'secret'" class="text-body-secondary">
              <i class="bi bi-key me-1" aria-hidden="true"></i>{{ val.text }}
            </span>
            <span v-else-if="val.kind === 'none'" class="text-body-secondary">
              —
            </span>
            <span
              v-else-if="val.kind === 'empty'"
              class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              title="An empty string is a value"
            >
              empty
            </span>
            <input
              v-else-if="val.kind === 'boolean'"
              type="checkbox"
              class="form-check-input mt-0"
              disabled
              tabindex="-1"
              :checked="val.on"
              :aria-label="val.on ? 'On' : 'Off'"
            />
            <code
              v-else-if="val.kind === 'text'"
              :class="{ 'fst-italic': val.numeric }"
            >
              {{ val.text }}
            </code>
          </template>
          <span
            class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"
          >
            not in catalog
          </span>
        </span>
      </template>

      <template #cell-similarTo="{ row }">
        <span class="small text-body-secondary">{{
          formatDaemonEnvSimilarTo(row)
        }}</span>
      </template>

      <template #empty>
        <span v-if="nodeSilent" data-id="hilos-daemon-env-orphans-empty-silent">
          Nothing is read while the node is silent.
        </span>
        <span v-else data-id="hilos-daemon-env-orphans-empty">
          Every line in .env is declared in the catalog.
        </span>
      </template>
    </HilosViewportTable>
  </HilosDaemonNodeShell>
</template>
