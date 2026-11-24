<script setup lang="ts">
import { HilosModal, useTrackedAction } from '@hilos/vue/base'

const operation = useTrackedAction()
</script>

<template>
  <HilosModal>
    <div>
      <p
        v-if="!operation.error.value"
        v-show="false"
        class="invisible"
        aria-hidden="true"
      >
        Save failed.
      </p>
      <p v-else>{{ operation.error.value }}</p>
    </div>
  </HilosModal>
</template>
