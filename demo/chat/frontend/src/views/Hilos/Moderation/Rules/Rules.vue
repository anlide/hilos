<script setup lang="ts">
import { HilosModerationKindRulesPage } from '@hilos/vue/admin/moderation'
import { hilosModerationContext } from '../hilosModerationContext.js'

defineOptions({ name: 'ModerationKindRulesPage' })
</script>

<template>
  <HilosModerationKindRulesPage :context="hilosModerationContext" />
</template>
