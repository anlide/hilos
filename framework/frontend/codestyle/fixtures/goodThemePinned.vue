<!-- Semantic classes and paint inherited from the surface are legal. -->
<template>
  <div class="bg-body-tertiary text-body-emphasis border" />
  <div class="bg-dark-subtle text-light-emphasis border-black-subtle" />
  <div class="alert alert-light" />
  <div class="list-group-item list-group-item-dark" />
  <svg>
    <path fill="currentColor" stroke="none" />
    <path fill="none" stroke="transparent" />
    <path fill="inherit" stroke="context-stroke" />
    <path fill="context-fill" stroke="url(#paint)" />
  </svg>
  <Widget color="secondary" />
</template>
