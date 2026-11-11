<script setup lang="ts">
import { type HilosProfilePageContext } from '@hilos/core/base'
import { HilosProfileNotificationsPage } from '@hilos/vue/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

const context: HilosProfilePageContext = { connection, scopes, actions }
</script>

<template>
  <HilosProfileNotificationsPage :context="context" />
</template>
