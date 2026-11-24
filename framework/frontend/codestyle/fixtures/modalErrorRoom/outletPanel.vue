<script setup lang="ts">
import { useTrackedAction } from '@hilos/vue/base'
const action = useTrackedAction()
</script>
<template>
  <slot name="body">
    <p class="invisible">{{ action.error.value }}</p>
  </slot>
</template>
