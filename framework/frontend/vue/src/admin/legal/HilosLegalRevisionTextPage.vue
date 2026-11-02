<script setup lang="ts">
import { createHilosLegalRevision } from '@hilos/core/admin/legal'
import { HilosPages, type HilosLegalContext } from '@hilos/core/base'
import HilosLegalRevisionText from '../../legal/HilosLegalRevisionText.vue'
import HilosLegalRevisionPageShell from './HilosLegalRevisionPageShell.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const details = useSignal(createHilosLegalRevision(props.context))
</script>

<template>
  <HilosLegalRevisionPageShell
    :context="context"
    :page="HilosPages.LEGAL_REVISION_TEXT"
  >
    <template v-if="details">
      <HilosLegalRevisionText
        v-if="details.clauses"
        :clauses="details.clauses"
      />
    </template>
  </HilosLegalRevisionPageShell>
</template>
