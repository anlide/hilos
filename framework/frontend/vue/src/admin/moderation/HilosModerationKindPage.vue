<!-- One declared kind's read-only Overview. The page answer owns the shared
head and the canonical single-row window; this view never fetches on mount. -->
<script setup lang="ts">
import {
  createHilosModerationKind,
  HILOS_MODERATION_COPY,
  type HilosModerationContext,
} from '@hilos/core/admin/moderation'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosTableEmptyState from '../../HilosTableEmptyState.vue'
import HilosTableLive from '../../HilosTableLive.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosModerationContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosModerationKindPage requires a provided Hilos router.')
}
const kind = computedSignal(() => router.currentRoute.get().params.kind ?? '')
const detail = createHilosModerationKind(props.context, kind)
const row = useSignal(detail.row)
const unavailable = useSignal(detail.unavailable)
const columns = useSignal(detail.controller.frame.columns)
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.MODERATION_KIND,
  computedSignal(() => detail.row.get()?.title),
)
onMounted(detail.start)
onUnmounted(() => {
  unbindName()
  detail.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.MODERATION_KIND"
    head-kind="entity"
    tabs-label="Kind pages"
  >
    <template v-if="row" #head-badges>
      <span
        class="badge border"
        :class="
          row.owner === 'framework'
            ? 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
            : 'bg-info-subtle text-info-emphasis border-info-subtle'
        "
        data-id="hilos-moderation-kind-owner"
      >
        {{
          row.owner === 'framework'
            ? HILOS_MODERATION_COPY.framework
            : HILOS_MODERATION_COPY.project
        }}
      </span>
    </template>
    <template v-if="row" #head-description>
      <code
        class="text-body-secondary text-break"
        data-id="hilos-moderation-kind-key"
        >{{ row.kind }}</code
      >
    </template>

    <template v-if="row">
      <HilosTableLive :controller="detail.controller" :columns="columns" />
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
            {{ row.input === 'image' ? 'Vision model' : 'Text model' }} ·
            <HilosHideable :value="row.model">
              <template #default="{ value: modelName }">
                <code>{{ modelName }}</code>
              </template>
            </HilosHideable>
            <span class="text-body-secondary">
              — profile
              <code data-id="hilos-moderation-kind-profile">{{
                row.profile
              }}</code>
            </span>
          </dd>
          <dt class="col-sm-3 fw-normal text-body-secondary py-1">
            Can refuse for
          </dt>
          <dd
            class="col-sm-9 mb-0 py-1 d-flex flex-wrap gap-1"
            data-id="hilos-moderation-kind-reasons"
          >
            <span
              v-for="reason in row.reasons"
              :key="reason"
              class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle text-wrap text-break mw-100"
              >{{ reason }}</span
            >
          </dd>
          <dt class="col-sm-3 fw-normal text-body-secondary py-1">
            Rules in force
          </dt>
          <dd class="col-sm-9 mb-0 py-1" data-id="hilos-moderation-kind-rules">
            <template v-if="row.ownRuleCount === 0">
              <span
                class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              >
                {{ HILOS_MODERATION_COPY.builtIn }}
              </span>
              <span class="text-body-secondary">
                until you add a rule of your own
              </span>
            </template>
            <template v-else>
              {{ row.ownRuleCount }}
              {{ row.ownRuleCount === 1 ? 'rule' : 'rules' }} of your own
            </template>
          </dd>
        </dl>
      </div>
    </template>
    <HilosTableEmptyState
      v-else-if="unavailable"
      :controller="detail.controller"
      kind="unavailable"
    />
    <div
      v-else
      class="placeholder-glow border rounded-3 px-3 py-2 mb-4"
      role="status"
      aria-label="Loading moderation kind"
      data-id="hilos-moderation-kind-loading"
    >
      <span class="placeholder col-8 d-block mb-2"></span>
      <span class="placeholder col-6 d-block mb-2"></span>
      <span class="placeholder col-4 d-block"></span>
    </div>
  </HilosAdminPage>
</template>
