<!-- The Hilos settings page (HilosPages.SETTINGS): a thin project binding of the
framework HilosSettingsPage to this app's context. The cataloged-settings table,
its value cell, the row view-model, and the add/update/delete round-trips are the
framework's; the project supplies only the context (its scope stores + live
connection) and declares the actual settings catalog on its backend. Bootstrap
classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosSettingsPage } from '@hilos/vue'

import { hilosSettingsContext } from './hilosSettingsContext'

defineOptions({ name: 'SettingsPage' })
</script>

<template>
  <HilosSettingsPage :context="hilosSettingsContext" />
</template>
