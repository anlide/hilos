<script setup lang="ts">
import { type HilosLegalClause } from '@hilos/core'
defineProps<{ clauses: HilosLegalClause[] }>()
</script>

<template>
  <ol class="ps-4 mb-0 text-break" data-id="legal-revision-text">
    <li
      v-for="clause in clauses"
      :key="clause.clauseKey"
      class="mb-3"
      data-id="legal-revision-clause"
    >
      <strong>{{ clause.statement }}.</strong>
      <p
        v-for="(paragraph, index) in clause.text.split('\n\n')"
        :key="index"
        class="mb-2"
      >
        {{ paragraph }}
      </p>
      <div
        v-if="clause.source === 'deviation'"
        class="small text-body-secondary"
        data-id="legal-revision-clause-deviation"
      >
        Project deviation from: {{ clause.standardStatement }} ·
        {{ clause.direction }}
      </div>
    </li>
  </ol>
</template>
