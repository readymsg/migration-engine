# Migration Engine — Build Spec (Laravel 13, v1)

**Hand to Claude Code one stage at a time, in the build order below.** Do NOT build the whole thing in one prompt. Supersedes the earlier SPIKE.md/BUILD.md — the spike is folded in as inline checkpoints (steps 1 and 3).

---

## Context

A standalone Laravel 13 service that converts a youth-sports org's existing **SportsEngine** website into a TeamLinkt site: **extract → plan the IA → generate the decided pages → land as an unpublished draft → log + notify.** Runs on its own Forge server, talks to the product over two API seams, graduates into the Laravel monolith later by promoting its modules.

**v1 scope:** SportsEngine only; critic-free; structural validation only. Sports Connect, the render critic, and live-signup wiring are later phases.

## Stack (confirmed June 2026)

- **Laravel 13 / PHP 8.4.**
- **`laravel/ai`** (official AI SDK) for all LLM stages — Agent classes, structured output, Anthropic provider, testing fakes, token-usage tracking.
- **Queue:** Redis + Horizon. Fan-out via **`Bus::batch()`** — one job per page.
- **Contracts as DTOs:** `spatie/laravel-data`. **Static analysis:** Larastan.
- **Assets:** S3 via flysystem. **Scraping:** Firecrawl via `Http`. **Preview renderer:** Vite + React + `@measured/puck` (throwaway, see Demo).

## Models

- Inventory/classification (cheap, parallel, **batched**): **Haiku 4.5** (`claude-haiku-4-5-20251001`)
- Block fill: **Sonnet 4.6** (`claude-sonnet-4-6`)
- IR/architecture pass (one call, high-judgment): **Opus 4.8** (`claude-opus-4-8`)

## The three locked contracts (define as `spatie/laravel-data` DTOs first — they are the spec)

- **`Manifest`** — extractor output: structure, provisioning (teams/divisions/admins), brand, content refs, asset refs, confidence, flags.
- **`Ir`** — per page: ordered `{ component_type, content_brief, asset_refs }` + nav order. **Schema-agnostic** — abstract intent only, never Puck prop names.
- **`PuckOutput`** — validated Puck data per page, conforming to the `ComponentSchema` provider.

Plus `DecisionLedger`, `ConversionLog`, and `GlobalStyleBrief` as first-class DTOs.

## Seams (build as stubs first, so the whole engine can be built before external pieces exist)

- **`ComponentSchema` provider** — single source of block types + prop shapes. **Today: hand-written default-Puck config** (Hero, Heading, Text, Image, Columns, Card, ButtonGroup). Later: the real fetched export. The **assembler is the ONLY place mapping abstract IR + schema → Puck JSON.**
- **`ProductClient`** — `getComponentSchema()`, `createDraftSite(orgId, puck, provisioning)`. Stub both; wire later. Never touch the product DB.
- **`Extractor` interface** — implement `SportNginExtractor` (drop in existing rootNav code). Sports Connect is a later second class behind the same interface.

---

## The four stages

### 1. INGEST — (proves rootNav inline)
`extract(url): Manifest` behind `Extractor`. Structure + provisioning from rootNav — no blind crawl. Scrape only real content pages via Firecrawl (async submit + poll). Assets to S3 — **pass references, never binary payloads.** Brand fallback ladder: header → og:image → favicon → flag.
**OPTIMIZE:** brand extraction, content scraping, and asset upload are independent — run them concurrently (`Http::pool()` / parallel jobs), don't serialize.
**Checkpoint:** run against ~10 real org URLs, save manifests as fixtures, eyeball structure + dynamic-vs-content classification. Messy ones = the batch-later case.

### 2. PLAN — decide the site (highest-judgment stage)
- `inventory(manifest): PageInventory` — cheap, parallel.
- `classify(inventory)` — disposition `dynamic | merge | keep | drop` + `confidence` + one-line `reason`. Obvious dynamic pages classified deterministically from signals; LLM only for ambiguous content pages.
  **OPTIMIZE — batched classification:** send ~20 pages per Haiku call, not one call per page. A 300-page site = ~15 calls, not 300. This is the big speed lever for large sites.
- `decideIa(classified): SitePlan` — nav + final page set.

**Do-it-well rules, enforced in code:**
- Drops are **reversible** — mark `parked`, never delete.
- **Conservative on drops** — low confidence defaults to keep.
- Every decision → a `DecisionLedger` entry (action + reason + confidence).
- Separate the "what's the IA" prompt from the "is this page worth keeping" prompt; the keep/drop one biased toward recall.

### 3. GENERATE — parallel, on the decided set only — (proves fan-out inline)
- `irPass(sitePlan): { ir: Ir[], styleBrief: GlobalStyleBrief }` — one structured Opus pass. **Emit a compact `GlobalStyleBrief`** (brand voice, palette, layout conventions, nav).
  **OPTIMIZE — coherence:** inject the `GlobalStyleBrief` into **every** block-fill call so pages don't drift. This is the main site-level coherence lever in a critic-free v1.
- `GeneratePageJob` per page, **with in-pass self-critique**: the agent drafts, self-assesses against the rubric, revises within the same call, returns `{ output, self_assessment, confidence }`. Self-score = soft signal only.
  **OPTIMIZE — prompt caching:** the block-fill calls share a large common prefix — the **component schema + GlobalStyleBrief + rubric**. Mark that prefix cacheable (Anthropic cache_control) so it's written once and read on every page call. Biggest single speed + cost win.
- Dispatch pages as a **`Bus::batch()`** on a concurrency-capped Horizon queue (cap just under Claude's rate limit). `then()` → assemble; `catch()` → flag. Partial failure reported per page, never swallowed.
- `assemble(ir): PuckOutput` — **deterministic, no LLM**; reads `ComponentSchema`, maps IR intent → real props. `validate(puck)`; on failure, one repair attempt, then flag.
- Land via `ProductClient.createDraftSite()` as an **unpublished draft**. Never auto-publish.
**Checkpoint:** ~15 concurrent page-gens complete cleanly, stay inside rate limits (backoff on 429), one deliberately-failing page degrades gracefully.

### 4. SCORE & LOG
- `structuralConfidence(manifest, puck)` — extraction-grounded (logo found, content density, provisioning complete, Puck validated). **The monitored score — trust over the self-assessment.**
- Write `ConversionLog`: per-stage confidence, decision ledger, per-page scores, duration, cost (AI SDK token usage), failure reason, draft link. Structured for Metabase.
- Slack notify on completion; **flag low-confidence conversions specifically** — not every conversion.

---

## Demo harness (THROWAWAY — deleted at integration, replaced by the product builder/preview)

A self-contained preview for showing the team. Renders the **default Puck blocks we already generate against** — not the product's components — so generation and preview share one config and match exactly. Does NOT use the `createDraftSite` seam.

- `GET /demo` — form: enter a SportsEngine URL.
- `POST /demo` — create a `Conversion`, dispatch the pipeline, redirect to `/demo/{id}`.
- `GET /demo/{id}` — status page that **polls the conversion state** (ingesting → planning → generating → done), showing live per-stage progress. When done, shows the **decision ledger** (kept/merged/parked + reasons + confidence), the **structural scores**, and a **"View rendered site"** link.
- `GET /preview/{id}` — a minimal **Vite + React** bundle using Puck's `<Render>` with simple default-block components, plus the decided **nav** to click between pages. Reads stored Puck JSON from `GET /api/demo/{id}/site`.
- Default block React components live in `resources/js/preview`, matching the component_type + prop names from the default schema.

Mark the whole `Demo`/`Preview` namespace clearly as throwaway. Build it after stage 4 so there's something real to render.

## Cross-cutting (don't skip)

- **Per-stage retries** with backoff (Horizon); clear `failed | partial` state, never a silent hang.
- **Idempotency** on the trigger (dedupe key per account).
- **Keep raw scrape + manifest** for debugging without re-scraping.
- **Admin emails are PII** — out of general logs; redact/scope retention.

## Build order (separate Claude Code passes; fixtures at each seam)

1. Contracts (DTOs) + `ComponentSchema` (default-Puck stub) + `ProductClient` (stub)
2. INGEST (SportNgin) — checkpoint on 10 real sites
3. PLAN
4. GENERATE — checkpoint on fan-out
5. SCORE & LOG
6. Trigger endpoint + queue wiring
7. Demo harness + preview renderer (throwaway)

**Don't build:** Sports Connect, render critic, live-signup wiring.

## Guardrails for Claude Code

- DTOs + Larastan are the strictness net (PHP isn't TS) — make them strict; run Larastan.
- Each LLM stage = a `laravel/ai` **Agent** class with structured output matching the contract. Use the SDK's **testing fakes** for per-stage tests against fixtures.
- Keep clever logic in well-bounded classes; orchestration (jobs, batch, retries) a thin separate layer. No abstraction I didn't ask for.
- I review the prompts and the planner's keep/merge/drop logic; you own plumbing, clients, queue/batch, the deterministic assembler/validator, and the throwaway demo.
- A test per stage against a real fixture.

## Done when

Drop a SportsEngine URL on `/demo` → watch the stages → get a decision ledger + a clickable rendered preview of the decided pages; the same pipeline also lands an unpublished draft via `createDraftSite`; each stage is fixture-replayable; a deliberately-failing page degrades gracefully.

## Graduation (later)

Already Laravel: promote the stage classes + DTOs into the monolith, swap the two `ProductClient` seams for internal calls, swap the default-Puck `ComponentSchema` for the real export, replace the manual trigger with the signup event, and delete the demo/preview namespace. No rewrite.
