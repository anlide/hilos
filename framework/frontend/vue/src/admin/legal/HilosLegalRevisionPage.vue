<script setup lang="ts">
import {
  computedSignal,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  createHilosLegalRevision,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
} from '@hilos/core/admin/legal'
import { inject, onMounted, onUnmounted } from 'vue'
import HilosLink from '../../HilosLink.vue'
import HilosLegalChanges from '../../legal/HilosLegalChanges.vue'
import HilosLegalRevisionPageShell from './HilosLegalRevisionPageShell.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const details = useSignal(createHilosLegalRevision(props.context))
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosLegalRevisionPage requires a provided Hilos router.')
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const revisionId = computedSignal(() =>
  String(router.currentRoute.get().params.revisionId ?? ''),
)
const revisions = createHilosLegalRevisionsTable(
  props.context,
  documentKey,
  HilosPages.LEGAL_REVISION,
  revisionId,
)
const accepted = useSignal(
  createHilosLegalRevisionAcceptanceSummary(revisions.controller, revisionId),
)
onMounted(() => revisions.start())
onUnmounted(() => revisions.dispose())
</script>

<template>
  <HilosLegalRevisionPageShell
    :context="context"
    :page="HilosPages.LEGAL_REVISION"
  >
    <template v-if="details">
      <section v-if="details.declared && details.revision" class="mb-4">
        <h2 class="h5">Changes from the previous revision</h2>
        <HilosLegalChanges
          v-if="details.changes !== null && details.predecessorId !== null"
          :changes="details.changes"
          :from-label="details.predecessorId"
          :to-label="details.revisionId"
        />
        <p v-else class="text-body-secondary">
          This is the first revision; there is nothing to compare it with.
        </p>
      </section>
      <section>
        <h2 class="h5">Who accepted this revision</h2>
        <p data-id="legal-revision-accepted">
          {{ accepted }}
        </p>
        <HilosLink
          :to="resolveHilosPath(HilosPages.LEGAL_ACCEPTANCES)"
          class="btn btn-outline-secondary"
          data-id="legal-open-acceptances"
          >Open acceptances</HilosLink
        >
      </section>
    </template>
  </HilosLegalRevisionPageShell>
</template>
