<script setup lang="ts">
import {
  HilosProfileHead,
  HilosProfileSessions,
  useSignal,
} from '@hilos/vue/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

import { profileSessionActions } from './profileSessionsActions.js'
import { profileSessions } from './profileSessionsPage.js'

defineOptions({ name: 'ProfileSessionsPage' })

const sessions = useSignal(profileSessions)

const context = { connection, scopes, actions }
</script>

<template>
  <section>
    <HilosProfileHead :context="context" />
    <HilosProfileSessions
      :sessions="sessions"
      :actions="profileSessionActions"
    />
  </section>
</template>
