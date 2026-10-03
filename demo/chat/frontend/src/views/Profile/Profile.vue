<!-- The profile root (HilosPages.PROFILE, /profile, HIL-1169): a thin project
binding of the framework HilosProfilePage to this app's connection, scopes and
action lifecycle. The page, its rows and its windows are the framework's; the
chat hands it the live name, its moderated rename and the lists behind the
summaries only it keeps. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core'
import { HilosProfilePage } from '@hilos/vue'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import {
  profileDeviceCount,
  profileSessionCount,
} from '../../profile/profileLists.js'
import { chatProfileRename } from './profileActions.js'
import { committedName } from './profilePage.js'

defineOptions({ name: 'ProfilePage' })

const context: HilosProfilePageContext = { connection, scopes, actions }
const binding: HilosProfileBinding = {
  name: committedName,
  rename: chatProfileRename,
  sessionCount: profileSessionCount,
  deviceCount: profileDeviceCount,
}
</script>

<template>
  <HilosProfilePage :context="context" :binding="binding" />
</template>
