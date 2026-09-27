<script setup lang="ts">
import { HilosProfileAgreementsHistoryPage } from '@hilos/vue'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
</script>

<template>
  <HilosProfileAgreementsHistoryPage
    :context="{ connection, scopes, actions }"
  />
</template>
