<script setup lang="ts">
import {
  createHilosModerationKind,
  createHilosModerationRuleAdd,
  createHilosModerationRuleDelete,
  createHilosModerationRuleEdit,
  createHilosModerationRules,
  HILOS_MODERATION_COPY,
  HILOS_MODERATION_RULE_DELETE_COPY,
  HILOS_MODERATION_RULE_EDIT_COPY,
  type HilosModerationRulesContext,
} from '@hilos/core/admin/moderation'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosTableEmptyState from '../../HilosTableEmptyState.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosModerationRuleAdd from './HilosModerationRuleAdd.vue'
import HilosModerationRuleDelete from './HilosModerationRuleDelete.vue'
import HilosModerationRuleEdit from './HilosModerationRuleEdit.vue'

const props = defineProps<{ context: HilosModerationRulesContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosModerationKindRulesPage requires a provided Hilos router.',
  )
}
const kind = computedSignal(() => router.currentRoute.get().params.kind ?? '')
const detail = createHilosModerationKind(
  props.context,
  kind,
  HilosPages.MODERATION_KIND_RULES,
)
const rules = createHilosModerationRules(props.context, kind, {
  openAdd: () => add.open(),
})
const add = createHilosModerationRuleAdd(
  props.context,
  detail.row,
  rules.current,
)
const remove = createHilosModerationRuleDelete(props.context, detail.row, rules)
const edit = createHilosModerationRuleEdit(props.context, detail.row, rules)
const row = useSignal(detail.row)
const unavailable = useSignal(detail.unavailable)
const current = useSignal(rules.current)
const builtIn = useSignal(
  computedSignal(
    () =>
      detail.row.get()?.ownRuleCount === 0 &&
      rules.current.get() &&
      rules.controller.frame.body.get() !== 'unavailable',
  ),
)
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.MODERATION_KIND,
  computedSignal(() => detail.row.get()?.title),
)
onMounted(() => {
  detail.start()
  rules.start()
  add.start()
  remove.start()
  edit.start()
})
onUnmounted(() => {
  unbindName()
  add.dispose()
  remove.dispose()
  edit.dispose()
  detail.dispose()
  rules.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.MODERATION_KIND_RULES"
    head-kind="entity"
    tabs-label="Kind pages"
  >
    <template v-if="row" #head-visual>
      <span
        class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
        data-id="hilos-moderation-kind-visual"
      >
        <i class="bi bi-shield-check"></i>
      </span>
    </template>
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

    <HilosTableEmptyState
      v-if="unavailable"
      :controller="detail.controller"
      kind="unavailable"
    />
    <template v-else-if="row && current">
      <HilosViewportTable
        :controller="rules.controller"
        data-id="hilos-moderation-rules-table"
      >
        <template #cell-rule="{ row: rule }">{{ rule.rule }}</template>
        <template #cell-actions="{ row: rule }">
          <div class="d-flex gap-1 justify-content-end">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              :title="HILOS_MODERATION_RULE_EDIT_COPY.open"
              :aria-label="HILOS_MODERATION_RULE_EDIT_COPY.open"
              :data-id="`hilos-moderation-rule-edit-open-${rule.id}`"
              @click="edit.open(String(rule.id))"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
              <span class="d-md-none ms-1">Edit</span>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              :title="HILOS_MODERATION_RULE_DELETE_COPY.open"
              :aria-label="HILOS_MODERATION_RULE_DELETE_COPY.open"
              :data-id="`hilos-moderation-rule-delete-open-${rule.id}`"
              @click="remove.open(String(rule.id))"
            >
              <i class="bi bi-trash" aria-hidden="true"></i>
              <span class="d-md-none ms-1">{{
                HILOS_MODERATION_RULE_DELETE_COPY.confirm
              }}</span>
            </button>
          </div>
        </template>
        <template #empty>
          <div class="text-center py-4" role="status">
            <i
              class="bi bi-list-check fs-2 text-body-secondary"
              aria-hidden="true"
            ></i>
            <p class="fw-semibold mb-1">No rules of your own yet</p>
            <p class="text-body-secondary mb-0">
              {{ row.title }} is checked by its built-in rules below.
            </p>
          </div>
        </template>
      </HilosViewportTable>
      <div
        v-if="builtIn"
        class="border rounded-3 p-3 mt-3 bg-body-tertiary"
        data-id="hilos-moderation-rules-built-in"
      >
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <h2 class="fw-semibold small text-body-secondary mb-0">
            Built-in rules
          </h2>
          <span
            class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
            >{{ HILOS_MODERATION_COPY.builtIn }}</span
          >
        </div>
        <p class="small text-body-secondary mb-2">
          They come with the kind and apply until you add a rule of your own.
          Your first rule replaces them all.
        </p>
        <ul class="small text-body-secondary mb-0 ps-3 text-break">
          <li v-for="(rule, index) in row.defaultRules" :key="index">
            {{ rule }}
          </li>
        </ul>
      </div>
    </template>
    <div
      v-else
      class="placeholder-glow border rounded-3 px-3 py-2 mb-4"
      role="status"
      aria-label="Loading moderation rules"
      data-id="hilos-moderation-rules-loading"
    >
      <span class="placeholder col-8 d-block mb-2"></span>
      <span class="placeholder col-6 d-block mb-2"></span>
      <span class="placeholder col-4 d-block"></span>
    </div>
    <HilosModerationRuleAdd :add="add" />
    <HilosModerationRuleDelete :remove="remove" />
    <HilosModerationRuleEdit :edit="edit" />
  </HilosAdminPage>
</template>
