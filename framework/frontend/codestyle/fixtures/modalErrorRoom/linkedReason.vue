<script setup lang="ts">
import { useTrackedAction } from '@hilos/vue/base'

const action = useTrackedAction()
</script>

<template>
  <p class="invisible">{{ action.error }}</p>
</template>
