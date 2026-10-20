<script setup lang="ts">
import { HilosProfileNotificationsPage } from '@hilos/vue/base'
import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
</script>

<template>
  <HilosProfileNotificationsPage :context="{ connection, scopes }" />
</template>
