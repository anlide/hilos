<template>
  <div data-id="vue-only" />
  <div data-id="strict-parity" />
  <div :data-id="`shared-dynamic-${row.id}`" />
  <div :data-id="`vue-dynamic-${row.id}`" />
  <div data-id="angular-peer" />
  <div data-id="repeated" />
  <div data-id="repeated" />
  <div data-id="repeated" />
  <div data-id="repeated" />
  <div data-id="repeated" />
  <div :data-id="dataId" />
  <HilosChild data-id="react-prop-only" />
  <!-- <div data-id="commented-out" /> -->
  <span>{{ example }}</span>
</template>

<script setup lang="ts">
// <div data-id="script-comment" />
const example = '<div data-id="script-string" />'
</script>
