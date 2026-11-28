<script setup lang="ts">
import { formatNumber } from '@hilos/core/base'

const count = 3
const caption = count.toLocaleString()
const shown = formatNumber(count)
</script>

<template>
  <p :title="caption">{{ shown }}</p>
  <p>{{ (5).toLocaleString() }} {{ Number(total).toLocaleString() }}</p>
  <p>{{ count.toLocaleString() }} {{ formatNumber(count) }}</p>
  <p>{{ new Intl.NumberFormat().format(count) }}</p>
</template>
