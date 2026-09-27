<script setup lang="ts">
import { HilosProfileDataPage } from '@hilos/vue'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
</script>

<template>
  <HilosProfileDataPage :context="{ connection, scopes, actions }" />
</template>
