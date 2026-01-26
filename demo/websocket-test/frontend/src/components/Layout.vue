<template>
  <div class="layout">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
      <div class="container-fluid">
        <router-link class="navbar-brand" to="/">WebSocket Chat Demo</router-link>
        <button
          class="navbar-toggler"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#navbarNav"
          aria-controls="navbarNav"
          aria-expanded="false"
          aria-label="Toggle navigation"
        >
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
          <ul class="navbar-nav ms-auto">
            <li class="nav-item">
              <router-link class="nav-link" to="/" :class="{ 'fw-bold': $route.name === 'home' }">
                Home
              </router-link>
            </li>
            <li class="nav-item">
              <router-link class="nav-link" to="/profile" :class="{ 'fw-bold': $route.name === 'profile' }">
                Profile
              </router-link>
            </li>
            <li class="nav-item">
              <router-link 
                class="nav-link" 
                to="/admin" 
                :class="{ 'fw-bold': $route.name === 'admin' || $route.name === 'admin_users' || $route.name === 'admin_moderator' || $route.name === 'admin_bots' }"
              >
                Admin
              </router-link>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    <main class="container-fluid py-4">
      <router-view />
    </main>
  </div>
</template>

<script setup lang="ts">
// Layout component with navigation
</script>
