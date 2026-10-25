<!-- HilosModerationPage — the framework moderation kinds administration page
(HilosPages.MODERATION, HIL-1629): displays the server-windowed table of
published moderation kinds, declaring their model, declared-by owner, and rule count.
The controller and row view-model belong to @hilos/core/admin/moderation; this view
owns the presentation markup, mounted with a project HilosModerationContext.
Bootstrap classes and the SDK's own utilities only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosModerationKindsTable,
  HILOS_MODERATION_COPY,
  moderationKindPath,
  type HilosModerationContext,
} from '@hilos/core/admin/moderation'
import { HilosPages } from '@hilos/core/base'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project moderation context: scopes and live connection. */
  context: HilosModerationContext
}>()

const kindsTable = createHilosModerationKindsTable(props.context)

onMounted(() => kindsTable.start())
onUnmounted(() => kindsTable.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.MODERATION" head-kind="section">
    <HilosViewportTable
      :controller="kindsTable.controller"
      data-id="hilos-moderation-kinds-table"
    >
      <template #cell-title="{ row }">
        <div class="fw-semibold">{{ row.title }}</div>
        <code
          class="small text-body-secondary"
          :data-id="'hilos-moderation-row-' + row.kind"
          >{{ row.kind }}</code
        >
      </template>
      <template #cell-owner="{ row }">
        <span class="badge bg-body-tertiary text-body-emphasis border">
          {{
            row.owner === 'framework'
              ? HILOS_MODERATION_COPY.framework
              : HILOS_MODERATION_COPY.project
          }}
        </span>
      </template>
      <template #cell-model="{ row }">
        <div>{{ row.input === 'image' ? 'Vision model' : 'Text model' }}</div>
        <HilosHideable :value="row.model">
          <template #default="{ value: modelName }">
            <code class="small text-body-secondary">{{ modelName }}</code>
          </template>
        </HilosHideable>
      </template>
      <template #cell-ownRuleCount="{ row }">
        <span
          v-if="row.ownRuleCount === 0"
          class="badge bg-body-tertiary text-body-emphasis border"
          >{{ HILOS_MODERATION_COPY.builtIn }}</span
        >
        <span v-else
          >{{ row.ownRuleCount }}
          {{ row.ownRuleCount === 1 ? 'rule' : 'rules' }}</span
        >
      </template>
      <template #cell-actions="{ row }">
        <HilosLink
          :to="moderationKindPath(row.kind)"
          class="btn btn-sm btn-outline-primary"
          :aria-label="'Open ' + row.title"
          :data-id="'hilos-moderation-open-' + row.kind"
        >
          {{ HILOS_MODERATION_COPY.open }}
        </HilosLink>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
