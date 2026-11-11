<script setup lang="ts">
import { HilosModerationPage } from '@hilos/vue/admin/moderation'
import { hilosModerationContext } from './hilosModerationContext.js'

defineOptions({ name: 'ModerationPage' })
</script>

<template>
  <HilosModerationPage :context="hilosModerationContext" />
</template>
