<script setup lang="ts">
import { HilosSecurityPage } from '@hilos/vue/admin/security'
import { scopes } from '../../../bootstrap/session.js'

defineOptions({ name: 'HilosSecurity' })
</script>

<template>
  <HilosSecurityPage :context="{ scopes }" />
</template>
