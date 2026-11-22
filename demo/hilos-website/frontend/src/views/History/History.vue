<!-- The public History page (PAGE_HISTORY): how Hilos came to be, from the
first task sheet of 2009 to v2. A project's own static page in the frame the
footer pages use; the page subscribes like any other but sends no payload, so
the whole text lives here and is prerendered into history.html. -->
<script setup lang="ts">
import { HilosStaticPage } from '@hilos/vue/base'

defineOptions({ name: 'HistoryPage' })
</script>

<template>
  <HilosStaticPage
    title="History"
    icon="bi-clock-history"
    subtitle="Hilos is older than it looks: the code you see today is its third major rework, called v2."
  >
    <section aria-labelledby="history-2009-title">
      <h2 id="history-2009-title">
        2009 — a module for an engine without a name
      </h2>
      <p>
        On 18 September 2009 the first task sheet went out: build a
        phpMyAdmin-like tool "as a module for our own engine". The first code
        came from a fellow student, then in his fifth year, working through
        weekly goal sheets. On 3 October he handed in the first piece with a
        cheerful "Done, hooray!". A week later the engine had a name:
        <strong>Hilos — Hi Load Site</strong>. The project had been registered
        on SourceForge on 25 September, the first revision of the core went into
        Subversion on 15 October, and on 28 November 2009 the domain
        hilos-dev.org was bought. The plan for the year, written that same week,
        already had the line "put up the official Hilos site".
      </p>
    </section>

    <section aria-labelledby="history-2009-2017-title">
      <h2 id="history-2009-2017-title">2009–2017 — the Subversion years</h2>
      <p>
        A server-side PHP framework: a core, modules (login, admin, payments,
        languages, statistics), components and the projects built on them. By
        2011 hilos-dev.org ran on Hilos itself, with an "About the project" page
        on building for high load. The core went through four generations; the
        one rewritten in 2015 ran kosk.online, a browser game with about ten
        thousand players and real payments.
      </p>
    </section>

    <section aria-labelledby="history-2018-title">
      <h2 id="history-2018-title">2018 — v1</h2>
      <p>
        Hilos moved to git as a Composer package: an event-driven daemon with
        WebSockets and process control. kosk.online soon got its next version on
        the new engine, and hilos-dev.org got a new homepage, an Angular app
        over a PHP API, the direct ancestor of today's "SDK in the browser, PHP
        daemon on the server". Later v1 carried hleb.studio (Palianytsia, as the
        project was named in Ukrainian), and most of its later commits came from
        that work.
      </p>
    </section>

    <section aria-labelledby="history-2023-title">
      <h2 id="history-2023-title">2023</h2>
      <blockquote class="blockquote">
        <p class="mb-0">
          "An old project, rewritten from scratch five times in about fifteen
          years. I really want to bring it to a presentable state, as a free
          public Composer package."
        </p>
      </blockquote>
    </section>

    <section aria-labelledby="history-2025-title">
      <h2 id="history-2025-title">2025 — why v2</h2>
      <p>
        hleb.studio began giving the engine real, hard work, and v1 gave way:
        the load turned up a bug in the core. It could have been patched. But it
        was the year of the AI boom, and the decision went the other way: finish
        the framework properly, publish it as open source, and rebuild
        hleb.studio as just one application of it. The work began in summer 2025
        with ChatGPT and code pasted back and forth by hand, moved to Cursor in
        the autumn and, step by step, to agent-driven development. On 27 October
        2025 a "big bang" commit in the same git history started v2: a daemon
        with workers and agents, WebSocket-first, with SDKs for Vue, React and
        Angular.
      </p>
    </section>

    <section aria-labelledby="history-2026-title">
      <h2 id="history-2026-title">2026 — the factory</h2>
      <p class="mb-0">
        Since April 2026 every change is a ticket. In May the main assistant
        became Codex for six weeks, then Claude. On 19 July 2026 the first
        ticket went through the factory end to end: one AI agent wrote
        email-and-password registration, another reviewed it. Claude leads
        today, because the whole factory is run through it; Codex is just as
        strong and does as much of the coding; Cursor and Gemini take smaller
        tasks, so the framework stays at home in every major assistant. Agents
        write, the maintainer reviews, and a full test run is the gate.
        Seventeen years after the domain was registered, the official site is
        back, built on Hilos, as it was in 2011.
      </p>
    </section>
  </HilosStaticPage>
</template>
