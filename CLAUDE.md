# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

Standalone Laravel 13 service that converts a youth-sports org's existing **SportsEngine** website into a TeamLinkt site: **extract → plan the IA → generate the decided pages → land as an unpublished draft → log + notify.** Runs on its own Forge server, talks to the product over two API seams, and is designed to be graduated into the Laravel monolith later by promoting its modules (no rewrite).

**`BUILD.md` is the authoritative spec.** Read it before starting any non-trivial change. v1 scope is SportsEngine only, critic-free, structural validation only — do NOT build Sports Connect, a render critic, or live-signup wiring.

The codebase today is a fresh Laravel 13 skeleton (just the User model, default routes, stock providers) plus the engine packages from `composer.json`. None of the four pipeline stages exist yet — the build is staged and Claude Code is meant to implement one stage at a time per the build order in BUILD.md.

## Commands

- `composer dev` — runs `php artisan serve`, `queue:listen`, `pail` (log tailer), and `vite` concurrently. The combined dev loop.
- `composer test` — clears config then runs `php artisan test`. To run one file/test: `php artisan test --filter=SomeTest` or `php artisan test tests/Feature/Foo.php`.
- `vendor/bin/phpstan analyse --memory-limit=1G` — Larastan (PHPStan) at level 8. The `--memory-limit` flag is needed because Larastan's parallel workers exceed PHP's default 128M when analysing Laravel. Note: PHPStan 2.x removed several v1-era `parameters` (e.g. `checkMissingIterableValueType`, `checkGenericClassInNonGenericObjectType`) — they're defaults now, don't re-add them or the analyser will exit 1 on the config.
- `vendor/bin/pint` — code style.
- `php artisan horizon` — start the queue supervisor (Redis). `Bus::batch()` fan-out for stage 3 is meant to run on a Horizon-managed, concurrency-capped queue.
- `php artisan pail` — tail logs.

Stock `.env` ships with `DB_CONNECTION=sqlite` and `QUEUE_CONNECTION=database`; running Horizon-backed fan-out locally requires switching the queue to `redis` and pointing `REDIS_*` at a running instance.

## AI configuration gotcha

`config/ai.php` ships with `'default' => 'openai'`, but **every LLM stage in this engine uses Anthropic** (Haiku 4.5 for inventory/classification, Sonnet 4.6 for block fill, Opus 4.8 for the IR pass — exact model IDs in BUILD.md). When wiring `laravel/ai` Agent classes, pin the Anthropic provider + model explicitly per agent; don't rely on the package default. `ANTHROPIC_API_KEY` is the relevant env var.

## Architecture: the three locked contracts

These DTOs (built with `spatie/laravel-data`) are the spec. Define them strictly first; everything else conforms to them.

- **`Manifest`** — output of stage 1 (INGEST). Structure, provisioning (teams/divisions/admins), brand, content refs, asset refs, confidence, flags. Asset payloads are always S3 references, never binary.
- **`Ir`** — per page: ordered `{ component_type, content_brief, asset_refs }` + nav order. **Schema-agnostic** — abstract intent only, **never Puck prop names.** This is enforced — keeping IR abstract is what lets the `ComponentSchema` change without rewriting the LLM stages.
- **`PuckOutput`** — validated Puck data per page, conforming to the `ComponentSchema` provider.

Plus `DecisionLedger`, `ConversionLog`, and `GlobalStyleBrief` as first-class DTOs.

## Architecture: the two seams (stub first, wire later)

- **`ComponentSchema` provider** — single source of block types + prop shapes. **Today: hand-written default-Puck config** (Hero, Heading, Text, Image, Columns, Card, ButtonGroup). Later: the real fetched export from the product. **The assembler is the ONLY place that maps abstract IR + schema → Puck JSON.** No other module touches Puck prop names.
- **`ProductClient`** — `getComponentSchema()`, `createDraftSite(orgId, puck, provisioning)`. Stub both. Never touch the product DB directly.
- **`Extractor` interface** — `extract(url): Manifest`. Implement `SportNginExtractor` (drop in existing rootNav code). Sports Connect is a later second class behind the same interface.

## Architecture: the four stages

1. **INGEST** — `extract(url): Manifest` behind `Extractor`. Structure + provisioning from rootNav (no blind crawl). Firecrawl for content pages (async submit + poll), S3 for assets. Brand fallback ladder: header → og:image → favicon → flag. Brand extraction, content scraping, asset upload are independent — run concurrently (`Http::pool()` / parallel jobs).
2. **PLAN** — `inventory → classify → decideIa`. **Batched classification** (~20 pages per Haiku call, not one). Drops are **reversible** (mark `parked`, never delete) and **conservative** (low confidence → keep). Every decision gets a `DecisionLedger` entry. Keep the "what's the IA" and "is this page worth keeping" prompts separate.
3. **GENERATE** — `irPass` (one Opus call) emits `Ir[]` + a compact `GlobalStyleBrief`. **Inject `GlobalStyleBrief` into every block-fill call** — that's the main coherence lever in a critic-free v1. **Mark the schema + GlobalStyleBrief + rubric prefix as `cache_control` cacheable** on Anthropic — biggest single speed/cost win. `GeneratePageJob` per page in a `Bus::batch()` with `then()`/`catch()`. `assemble(ir): PuckOutput` is **deterministic, no LLM** — one repair attempt on validation failure, then flag. Land via `ProductClient.createDraftSite()` as **unpublished draft, never auto-publish**.
4. **SCORE & LOG** — `structuralConfidence(manifest, puck)` is the trusted monitored score (extraction-grounded), not LLM self-assessment. Write `ConversionLog` structured for Metabase. Slack notify on completion; **flag low-confidence conversions specifically** — not every conversion.

## Build order (separate Claude Code passes; fixtures at each seam)

1. Contracts (DTOs) + `ComponentSchema` (default-Puck stub) + `ProductClient` (stub)
2. INGEST — checkpoint on 10 real sites
3. PLAN
4. GENERATE — checkpoint on fan-out
5. SCORE & LOG
6. Trigger endpoint + queue wiring
7. Demo harness + preview renderer (throwaway, deleted at integration)

The Demo namespace (`/demo`, `/preview/{id}`, Vite + React + `@measured/puck`) is marked throwaway — it renders the default Puck blocks so generation and preview share one config. Don't entangle it with the engine.

## Guardrails (from BUILD.md — these come up repeatedly)

- DTOs + Larastan are the strictness net (PHP isn't TS) — make them strict.
- Each LLM stage = a `laravel/ai` **Agent** class with structured output matching the contract. Use the SDK's **testing fakes** for per-stage tests against fixtures.
- Orchestration (jobs, batch, retries) is a thin separate layer from clever logic.
- **No abstraction not asked for.** The user owns the prompts and the planner's keep/merge/drop logic; Claude owns plumbing, clients, queue/batch, the deterministic assembler/validator, and the throwaway demo.
- A test per stage against a real fixture. Keep raw scrape + manifest for fixture replay without re-scraping.
- Admin emails are PII — out of general logs; redact/scope retention.
- Idempotency on the trigger (dedupe key per account). Clear `failed | partial` state, never a silent hang.
