<script setup lang="ts">
import { HilosPages } from '@hilos/core'
</script>
<template>
  <!-- <HilosAdminPage :page="HilosPages.MISSING"><b>Not a view</b></HilosAdminPage> -->
  <HilosSettingPresetsPage :page="HilosPages.PRESETS" />
</template>
