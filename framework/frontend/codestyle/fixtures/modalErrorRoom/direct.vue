<script setup lang="ts">
import {
  HilosActionError,
  type TrackedAction,
  HilosFormError,
  HilosModal as LocalDialog,
} from '@hilos/vue/base'

defineProps<{
  action: TrackedAction
  refusal: string
  progress: string
}>()
</script>

<template>
  <local-dialog initial-focus="dialog">
    <div data-id="save-refusal-slot">
      <div class="invisible" data-id="save-refusal-twin" aria-hidden="true">
        &nbsp;
      </div>
      <div v-if="action.error.value" data-id="save-refusal">{{ refusal }}</div>
    </div>

    <template #actions>
      <p v-if="action.error.value">Changes could not be saved.</p>
      <div v-else style="min-height: 2rem" aria-hidden="true"></div>
    </template>

    <div :class="{ 'opacity-0': !action.error.value }">{{ refusal }}</div>

    <div class="invisible" data-id="shared-error-wrapper">
      Save error
      <HilosFormError :message="refusal" />
    </div>
    <HilosActionError />
    <div class="invisible" role="status">{{ progress }}</div>
    <p v-if="action.error.value">You cannot continue.</p>

    <!-- <p class="invisible">Changes could not be saved.</p> -->
  </local-dialog>
  <div class="invisible alert-danger">{{ action.error.value }}</div>
</template>
