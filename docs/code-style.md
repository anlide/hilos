# Code Style Guide

> Draft. To be expanded.

Code style guidelines for Hilos projects.

---

## Plan for filling this file

1. **PHP (Backend)**
   - PSR-12 / PSR-1 compliance
   - `declare(strict_types=1)` usage
   - Namespace conventions (Hilos\\, App\\, Demo\\)
   - Class naming: PascalCase, suffixes (Entity, Object, Db, Command, etc.)
   - Method naming: camelCase
   - Property visibility (private/protected/public)
   - PHPDoc: blocks, `@param`, `@return`, `@throws`
   - Line length, indentation (4 spaces)
   - Use statements ordering

2. **TypeScript / Vue (Frontend)**
   - Vue 3 Composition API vs Options API
   - TypeScript strict mode, typing conventions
   - Component structure (script setup, template, style)
   - File naming (PascalCase for components, camelCase for utils)
   - Import ordering

3. **File & Directory Structure**
   - Backend: backend/, Bootstrap/, Database/, etc.
   - Entity/Object/Db placement
   - Migration files naming (e.g. `001_initial.sql`)

4. **Naming Conventions**
   - Tables: snake_case
   - Database columns: snake_case
   - Entity properties vs column mapping
   - Object-exclude directives

5. **Error Handling & Logging**
   - When to throw vs log
   - Exception hierarchy usage
   - Log levels (debug, info, error)

6. **Database & ORM**
   - Entity -> Object -> Db flow
   - **Runtime (ORM part):** `Runtime/Rt/`, `Runtime/State/` (RtContext, RtState, RtStates)
   - **Frontend ORM subset:** `framework/frontend/src/stores`, `framework/frontend/src/types` (part of ORM, implementation in progress)
   - When to use `db:entity:fix`, `db:object:fix`, legacy `db:idea:fix`
   - Custom methods preservation

7. **Docker & Environment**
   - .env usage
   - docker-compose naming (local, dev, prod)

*Consider referencing existing demos (e.g. chat) as style examples.*
