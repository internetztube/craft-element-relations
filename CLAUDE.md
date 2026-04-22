# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Craft CMS plugin (`internetztube/craft-element-relations`) that tracks and displays where elements (entries, assets, etc.) are used across the site. It stores relation metadata in a custom cache table and exposes it via a read-only field type, GraphQL, and Twig.

Supports Craft CMS 4.x and 5.x, MySQL/MariaDB/PostgreSQL, and integrates with many third-party field plugins (Matrix, Neo, SuperTable, SEOmatic, Hyper, Redactor, CKEditor, etc.).

## Commands

### PHP / Composer
```bash
composer install
```

No automated test suite exists — testing is done manually in a Craft CMS environment.

### Frontend (React/TypeScript)
Working directory: `src/web/assets/src/`

```bash
npm install
npm run build   # Compile TypeScript/React → src/web/assets/dist/input.js
npm run dev     # Vite dev server
npm run lint    # ESLint
```

The compiled bundle (`src/web/assets/dist/input.js`) must be committed — it is loaded directly by Craft's AssetBundle.

### Console Command
```bash
craft element-relations/resave-relations
```
Queues all elements for relation refresh via Craft's queue.

## Architecture

### Data Flow

1. **Event trigger** — `Element::EVENT_AFTER_SAVE` fires; element is added to a local batch.
2. **Flush** — `Application::EVENT_AFTER_REQUEST` flushes the batch into Craft queue jobs.
3. **Extraction** — `ResaveElementRelationsJob` calls `ExtractorService` which delegates to field-type-specific extractors.
4. **Cache write** — Extracted relations are written to `{{%elementrelations_cache}}`.
5. **Read** — `ElementRelationsField` normalizes to a `RelationsModel`, which queries the cache table for counts/elements.

### Key Concepts

**Cache table** (`elementrelations_cache`) stores `(sourceElementId, targetElementId, fieldId, type)` tuples. No foreign keys intentionally — supports linking to soft-deleted elements (important for Redactor/CKEditor static links).

**Extractors** live in `src/services/extractors/`. Each implements `InterfaceFieldExtractor`. `ExtractorService` loops over all fields on an element and dispatches to the matching extractor. Adding support for a new field plugin means adding a new extractor class and registering it in `ExtractorService`.

**RelationsModel** (`src/models/RelationsModel.php`) is the primary query interface. Methods: `count()`, `isInUse()`, `elements()`, `isUsedInSeomaticGlobalSettings()`. It queries the cache table and returns Craft element queries.

**Batching** — `QueueElementsForRefreshService` collects element IDs during a request and pushes them in batches of 2000 (configurable via `bulkRefreshBatchSize` in settings) to avoid queue flooding.

### Directory Layout

```
src/
  ElementRelations.php          # Plugin bootstrap; registers events, field, utilities
  fields/ElementRelationsField.php  # Field type; normalizes to RelationsModel
  models/RelationsModel.php     # Core query logic against cache table
  services/
    ExtractorService.php        # Orchestrates extraction; calls field extractors
    DatabaseService.php         # DB abstraction for JSON path queries (MySQL vs PG)
    QueueElementsForRefreshService.php
    extractors/                 # One extractor per supported field/element type
  jobs/
    ResaveElementRelationsJob.php
    GenerateResaveAllElementRelationsJobsJob.php
  controllers/ElementRelationsController.php  # /actions/element-relations/element-relations/paginate
  gql/types/Relations.php       # GraphQL type
  records/ElementRelationsCacheRecord.php
  migrations/                   # DB schema migrations
  templates/                    # Twig templates for field input/preview/utility
  web/assets/
    src/lib/                    # TypeScript/React source
      main.tsx                  # React root with QueryClient
      Pagination.tsx            # MUI + React Query pagination component
      input.ts                  # Exported window.elementRelationsInputInit
    dist/input.js               # Compiled bundle (committed)
```

### GraphQL

The field exposes a `Relations` GraphQL type with fields: `count`, `isInUse`, `elements`, `isUsedInSeomaticGlobalSettings`.

### Namespace

PSR-4: `internetztube\elementRelations` → `src/`
