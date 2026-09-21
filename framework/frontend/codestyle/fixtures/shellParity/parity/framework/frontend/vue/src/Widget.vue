<template>
  <div data-id="shared-static" />
  <div :data-id="`shared-dynamic-${row.id}`" />
  <div v-bind:data-id="`shared-long-${row.id}`" />
  <div :data-id="dataId" />
  <div :data-id="`${dataId}-slot`" />
  <HilosChild data-id="shared-prop" />
  <HilosChild :data-id="`shared-prop-dynamic-${row.id}`" />
</template>
