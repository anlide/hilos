<script setup lang="ts">
import { HilosModerationKindPage } from '@hilos/vue/admin/moderation'
import { hilosModerationContext } from './hilosModerationContext.js'

defineOptions({ name: 'ModerationKindPage' })
</script>

<template>
  <HilosModerationKindPage :context="hilosModerationContext" />
</template>
