<!-- HilosLogsSettingsPage — the framework Hilos logging-mode page
(HilosPages.LOGS_SETTINGS): the Logs section's own settings surface, where the five
keys that decide how loud the logs are come as three named modes instead. The
layout, the two gestures, the confirmation and the states are the common
setting-presets screen's; everything this page adds is which section it is — its
page key, its signal and its vocabulary. A project mounts it by passing its
HilosSettingPresetsContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  hilosLogSettingsVocabulary,
  HilosPages,
  LOG_SETTINGS_SIGNAL,
  type HilosSettingPresetsContext,
} from '@hilos/core'

import HilosSettingPresetsPage from '../settings/HilosSettingPresetsPage.vue'

defineProps<{
  /** The project context: the connection the frames arrive on and the action lifecycle. */
  context: HilosSettingPresetsContext
}>()
</script>

<template>
  <HilosSettingPresetsPage
    :page="HilosPages.LOGS_SETTINGS"
    :context="context"
    :signal="LOG_SETTINGS_SIGNAL"
    :vocabulary="hilosLogSettingsVocabulary"
  />
</template>
