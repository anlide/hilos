<script setup lang="ts">
import { HilosProfileAgreementsPage } from '@hilos/vue'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
</script>

<template>
  <HilosProfileAgreementsPage :context="{ connection, scopes, actions }" />
</template>
