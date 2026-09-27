<script setup lang="ts">
import { type HilosLegalChange } from '@hilos/core'
defineProps<{
  changes: HilosLegalChange[]
  fromLabel: string
  toLabel: string
}>()
</script>

<template>
  <div data-id="legal-changes" class="text-break">
    <p
      v-if="changes.length === 0"
      class="text-body-secondary mb-0"
      data-id="legal-changes-empty"
    >
      No clause changed
    </p>
    <template v-else>
      <div
        class="d-none d-md-block border rounded"
        data-id="legal-changes-wide"
      >
        <div class="row g-0 border-bottom small fw-semibold bg-body-tertiary">
          <div class="col-6 px-3 py-2 border-end">
            Before — revision {{ fromLabel }}
          </div>
          <div class="col-6 px-3 py-2">After — revision {{ toLabel }}</div>
        </div>
        <div
          v-for="change in changes"
          :key="change.clauseKey"
          class="row g-0 border-bottom"
          data-id="legal-change-row"
        >
          <div class="col-12 px-3 pt-2 small fw-semibold">
            {{ change.title }}
            <span
              class="badge text-bg-light border ms-1 text-capitalize"
              data-id="legal-change-kind"
              >{{ change.kind }}</span
            >
            <span class="text-body-secondary fw-normal ms-1">{{
              change.clauseKey
            }}</span>
          </div>
          <div
            v-for="(side, index) in [change.before, change.after]"
            :key="index"
            class="col-6 px-3 py-2"
            :class="{ 'border-end': index === 0 }"
          >
            <template v-if="side">
              <div class="small text-body-secondary">
                {{
                  side.source === 'standard'
                    ? 'Hilos standard text'
                    : 'Project deviation'
                }}
              </div>
              <strong>{{ side.statement }}.</strong>
              <p
                v-for="(paragraph, part) in side.text.split('\n\n')"
                :key="part"
                class="mb-2"
              >
                {{ paragraph }}
              </p>
              <span v-if="side.direction" class="small text-body-secondary">{{
                side.direction
              }}</span>
            </template>
            <span v-else class="text-body-secondary">Not present</span>
          </div>
        </div>
      </div>
      <div class="d-md-none" data-id="legal-changes-narrow">
        <div
          v-for="change in changes"
          :key="change.clauseKey"
          class="border rounded mb-2"
          data-id="legal-change-row"
        >
          <div
            class="px-3 py-2 border-bottom small fw-semibold bg-body-tertiary"
          >
            {{ change.title }}
            <span
              class="badge text-bg-light border ms-1 text-capitalize"
              data-id="legal-change-kind"
              >{{ change.kind }}</span
            >
            <span class="text-body-secondary fw-normal ms-1">{{
              change.clauseKey
            }}</span>
          </div>
          <div
            v-for="(side, index) in [change.before, change.after]"
            :key="index"
            class="px-3 py-2"
            :class="{ 'border-bottom': index === 0 }"
          >
            <div class="small fw-semibold">
              {{ index === 0 ? 'Before' : 'After' }} — revision
              {{ index === 0 ? fromLabel : toLabel }}
            </div>
            <template v-if="side">
              <div class="small text-body-secondary">
                {{
                  side.source === 'standard'
                    ? 'Hilos standard text'
                    : 'Project deviation'
                }}
              </div>
              <strong>{{ side.statement }}.</strong>
              <p
                v-for="(paragraph, part) in side.text.split('\n\n')"
                :key="part"
                class="mb-2"
              >
                {{ paragraph }}
              </p>
              <span v-if="side.direction" class="small text-body-secondary">{{
                side.direction
              }}</span>
            </template>
            <span v-else class="text-body-secondary">Not present</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
