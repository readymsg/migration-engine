# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

Standalone Laravel 13 service that converts a youth-sports org's existing **SportsEngine** website into a TeamLinkt site: **extract → plan the IA → generate the decided pages → land as an unpublished draft → log + notify.** Runs on its own Forge server, talks to the product over two API seams, and is designed to be graduated into the Laravel monolith later by promoting its modules (no rewrite).

**`BUILD.md` is the authoritative spec.** Read it before starting any non-trivial change. v1 scope is SportsEngine only, critic-free, structural validation only — do NOT build Sports Connect, a render critic, or live-signup wiring.

**v1 SCOPE CUT — site rebuild only.** Beyond BUILD.md's v1 boundary, the engine in this repo is further narrowed: we only convert the **site** (structure, content, brand). We do **NOT** extract or provision TeamLinkt data — no teams, divisions, admins, or team logos. The `Provisioning`/`Team`/`Division`/`Admin` DTOs are kept in the contracts as scaffolding for a later phase, but `Manifest.provisioning` is nullable and always set to `null` by the v1 extractor. Do not walk the "Teams" / "Divisions" subtrees, do not invent admin sources, do not flag "missing" provisioning — it's intentionally absent.

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

- **`Manifest`** — output of stage 1 (INGEST). Structure, brand, content refs, asset refs, confidence, flags. `provisioning` is nullable and always null in v1 (see scope cut above). Asset payloads are always S3 references, never binary.
- **`Ir`** — per page: ordered `{ component_type, content_brief, asset_refs }` + nav order. **Schema-agnostic** — abstract intent only, **never Puck prop names.** This is enforced — keeping IR abstract is what lets the `ComponentSchema` change without rewriting the LLM stages.
- **`PuckOutput`** — validated Puck data per page, conforming to the `ComponentSchema` provider.

Plus `DecisionLedger`, `ConversionLog`, and `GlobalStyleBrief` as first-class DTOs.

## Architecture: the two seams (stub first, wire later)

- **`ComponentSchema` provider** — single source of block types + prop shapes. **Today: hand-written default-Puck config** (Hero, Heading, Text, Image, Columns, Card, ButtonGroup). Later: the real fetched export from the product. **The assembler is the ONLY place that maps abstract IR + schema → Puck JSON.** No other module touches Puck prop names.
- **`ProductClient`** — `getComponentSchema()`, `createDraftSite(orgId, puck, provisioning)`. Stub both. Never touch the product DB directly.
- **`Extractor` interface** — `extract(url): Manifest`. Implement `SportNginExtractor` (drop in existing rootNav code). Sports Connect is a later second class behind the same interface.

## Architecture: the four stages

1. **INGEST** — `extract(url): Manifest` behind `Extractor`. Structure from rootNav (no blind crawl). Firecrawl for content pages (async submit + poll), S3 for assets. Brand fallback ladder: header → og:image → favicon → flag (signals come from the **homepage HTML**, not rootNav — see "Real SportsEngine rootNav" below). **Provisioning is out of scope for v1** — site rebuild only; the `provisioning` field stays null. Brand extraction, content scraping, asset upload are independent — run concurrently (`Http::pool()` / parallel jobs) once the queue lands.
2. **PLAN** — `inventory → classify → decideIa`. **Batched classification** (~20 pages per Haiku call). Three buckets per page: content → `keep`, live data → `platform_dynamic` + block, SE plumbing → `park`. Faithful-rebuild bias: a model `park`/`drop`/`platform_dynamic` is honored only when confidence is **strictly > 0.80**; below that the page is `keep`-ed with the model's verdict preserved in the ledger reason. Drops are **reversible** (high-confidence drop → `park`, never delete); merges are **suggestions** (engine never auto-folds; model merge → `keep` with target in ledger reason). Descendants of `platform_dynamic` are `subsumed` (represented by the parent's block, never independently classified). Every decision gets a `DecisionLedger` entry. See "PLAN — v1 dispositions" for the full action set, the `PlatformBlockType` enum (now including `Calendar` and `News`), registration retargeting, and the SE-platform-link vs SE-CDN-asset distinction.
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

## Real SportsEngine rootNav (recon'd against 6 live sites)

**Don't believe a fixture you invented.** Stage 2's synthetic fixture was wrong about almost everything; replaced with real per-site fixtures in `tests/Fixtures/rootnav/real/` (homepage HTML + one or more `/page/nav/<id>` JSON responses per site).

- **No single `/rootnav` endpoint.** rootNav is per-node: `GET https://<site>/page/nav/<page_node_id>` returns one node with its `parent`, `siblings`, and `children`. Full-tree extraction is a **BFS** that expands any sibling/child whose `has_child > 0` by calling the same endpoint with that node's id. Cap depth (`SportNginExtractor::MAX_DEPTH = 5`).
- **Node JSON shape** (keys we rely on): `name`, `id` (string `"page_node_<int>"`), `url`, `node_type`, `has_child`, `nav_url`, `siblings: array<self>`, `children: array<self>`, `parent: self|null`.
- **`node_type` taxonomy is what's real**, NOT an invented `kind` flag. Values seen across the 6 sites: `"Page"`, `"Calendar"`, `"NewsNode"`, `"LinkNode"`, `null` (root and SE-tool siblings). Other types likely exist on bigger sites. `NavNode.kind` is a derived classification: `'page' | 'dynamic_calendar' | 'dynamic_news' | 'dynamic_other' | 'external' | 'unknown'`.
- **SE external-link node shapes** — surfaced after dumping real manifests: `node_type === 'LinkNode'` is an external link sibling (e.g. a "Shop" link); `id === 'toolsLink'` is the hardcoded SE third-party-tool sibling (e.g. the "Dibs" volunteer-scheduling link injected on every site). Both classify as `kind = 'external'` with `external_subtype` `'external_link'` and `'se_tool'` respectively, are kept in the tree, and never scraped.
- **Themes vary, the API doesn't.** `itasca` (5/6 sites) inlines `var rootNav = {...}` and `var currentId = 'page_node_<id>'` into every `/page/show/...` HTML; `waterworld` (1/6) does not. **The extractor never reads the inline blob** — always uses the `/page/nav/<id>` API so it's theme-agnostic. The HTML is used only to discover (a) the SE numeric site_id from the `site_files/<id>/favicon.ico` link, (b) at least one valid `page_node_<id>` to start BFS from, and (c) brand assets.
- **Bootstrap heuristic** for the starting page_node_id: prefer `var currentId = 'page_node_<int>'` (itasca shortcut), else try every distinct `page_node_<int>` reference in the HTML in order — the site's root parent id often returns 401, so the extractor falls forward until one node fetches cleanly. See `SportNginExtractor::resolveStartNode()`.
- **Provisioning is out of scope in v1.** Even though rootNav lacks provisioning anyway (no `team`/`division`/`admins` keys), the v1 site-rebuild scope means we don't try to recover it. `Manifest.provisioning` is null. The Provisioning/Team/Division/Admin DTOs remain as scaffolding — do not delete them — but the extractor doesn't populate them and doesn't walk any "Teams"-named subtree. When v2 lifts this cut, the structural-walk heuristic (top-level siblings labelled `Teams` / `Divisions`, their direct children become rows) lives in git history and can be revived; admins will additionally require an authenticated SE endpoint we don't have.
- **Brand signals live in the homepage HTML**, not anywhere in rootNav. Patterns by rung:
  - **header** → first `https://cdn[1-4].sportngin.com/attachments/banner_graphic/.../<file>`; fall back to `logo_graphic` within the same rung if no banner.
  - **og:image** → `<meta property="og:image" content="...">`.
  - **favicon** → `attachments/favicon_graphic/...` else `<link rel="shortcut icon">` (which on every site we saw points to `https://assets.ngin.com/site_files/<site_id>/favicon.ico`).
  - **flag** → none found.
- **Redirects are real.** One of the six recon sites (`strikersbaseball.ca`) 301s to a rebrand domain (`langdondiamonds.ca`). The Manifest stores the **post-redirect** URL in `source_url`; the input URL is preserved only via a `redirected: <from> -> <to>` flag. `HttpHtmlFetcher` captures the final URL via Guzzle's `on_stats`. `SportNginExtractor` builds all subsequent absolute URLs (rootNav endpoint, scrape submissions) against the post-redirect origin.

## PLAN — v1 dispositions

The engine's guiding principle: **any dynamic SportsEngine content TeamLinkt reproduces as its own block — zero live SE dependency.** Three buckets every page falls into:

1. **Content → `keep`.** Static informational pages (About Us, Coaches, FAQs, Programs). Scraped and rebuilt 1:1.
2. **Live data → `platform_dynamic` + a `PlatformBlockType`.** Anything SE renders from data — Calendar, News, Schedule, Scores, Standings, Roster, Teams, Divisions, Contacts. The block replaces the source page; the source is NOT scraped. The rebuilt site reads from TeamLinkt's own data.
3. **SE plumbing → `park`.** SportsEngine platform / tool / help links (Dibs toolsLink, /sportsengine, SE login). Removed in the rebuild.

Every page lands in the ledger with exactly one `DecisionAction`. v1 is a **faithful-rebuild migration** — the engine rebuilds the whole site by default and only sets aside pages it's very confident are junk.

- **`keep`** — preserve as content. Default for ambiguous pages.
- **`platform_dynamic`** — page whose content TeamLinkt **regenerates** via a `PlatformBlockType` Puck block: `Schedule | Scores | Standings | Roster | Teams | Divisions | Contacts | Calendar | News`. The block replaces the source page; the source is NOT scraped. Conservative-by-design — a false `platform_dynamic` destroys real content (replaced by an empty block), so the bar is high.
- **`subsumed`** — descendant of a `platform_dynamic` node. The ancestor's block represents the whole subtree, so descendants are NOT scraped, classified, or independently rebuilt. Absent from `nav` + `kept_pages`, present in the ledger with reason `"subsumed by parent <BlockType> block at '<parent label>'"`. Reversible — a reviewer can promote one back if the block doesn't cover their case. Crucially, subsumed descendants are NEVER sent to the LLM (deterministic platform_dynamic catches them in phase 1; LLM-returned platform_dynamic catches them retroactively in phase 3).
- **`park`** — set aside, absent from `nav`/`kept_pages`, present in the ledger. Used for high-confidence (> 0.80) LLM parks/drops, unknown-shape nodes, and SE platform/tool/help links.
- **`drop`** — NEVER emitted in v1. High-confidence drops are rewritten as `park` (reversibility).
- **`merge`** — NEVER emitted in v1. The engine doesn't auto-fold pages; a model `merge` is rewritten as `keep` with the merge target preserved in the ledger reason for human review.
- **`dynamic`** — vestigial fallback for unrecognized SE dynamic node types (`dynamic_other`). Calendar and NewsNode no longer use this — they map to `platform_dynamic` with `PlatformBlockType::Calendar` / `News`. v1 should never emit `dynamic` in practice; if you see it in a ledger, that's a signal a new SE dynamic type needs a PlatformBlockType.

### Recall thresholds (enforced in `RootNavPlanner::applyRecallBias`)

A model `park`/`drop`/`platform_dynamic` is honored only when confidence is **strictly greater than 0.80**. At or below 0.80 the page is `keep`-ed and the model's verdict is preserved in the reason (`recall-biased keep (model wanted … @ 0.80: …)`) so a reviewer can still act.

### platform_dynamic detection (hybrid, conservative)

Three deterministic paths run BEFORE the LLM, plus an LLM fallback:

1. **node_type-driven** — `Calendar` → `PlatformBlockType::Calendar`; `NewsNode` → `PlatformBlockType::News`. SE's structural classification is enough; the LLM is never asked.
2. **Name-map** — matches only unambiguous data-listing labels on `kind=page` nodes: `standings` → Standings; `score(s)/result(s)` → Scores; `schedule(s)` → Schedule; `roster(s)` → Roster; `division(s)` → Divisions; `teams` → Teams **only when the node has children** (a leaf "Teams" page is content, a Teams parent of team pages is a directory). Ambiguous words (`tryouts`, `recruiting`, `programs`, `events`, `camps`, `contacts`) are intentionally NOT in the map — they go to the LLM.
3. **Subsumption** — once a node is `platform_dynamic`, every descendant in its subtree is `subsumed`. `RootNavPlanner::classify()` runs in three phases for this: phase 1 = deterministic + early subsume (deterministic platform_dynamic descendants are never even sent to the LLM); phase 2 = LLM batches for the remaining ambiguous pages; phase 3 = retroactive subsume of LLM-returned platform_dynamic descendants (their LLM verdicts are discarded — the parent's block represents them).
4. **LLM fallback** for semantic variants like "Game Schedule" or "League Standings". Strict > 0.80 threshold; below it the page is recall-biased to keep.

### Registration intent

A nav node whose label or URL matches `\b(register|registration)\b` is classified `keep` with the ledger note `"registration link — GENERATE should retarget to TeamLinkt secure registration URL"`. The nav entry survives; only the destination is rewritten by GENERATE. This is intentionally narrow word-matching (`Sign Up` does NOT match — that goes to the LLM).

### SE platform LINKS vs SE CDN ASSETS — different concerns

Two SportsEngine-flavored signals, handled completely differently:

- **SE platform / tool / help LINKS in nav** → `park`. Detected by: `external_subtype === 'se_tool'` (the hardcoded `Dibs` toolsLink sibling SE injects on every site); OR label matching `/sports\s*engine/i`; OR URL path matching `/sportsengine`, `/sportsengine/*`, `/dib_sessions*`, `/sn_signin`, `/sn_login`, `/se_login`, `/se_signin`. These nav entries do not carry over to the rebuilt site.
- **SE CDN asset URLs** (`cdn*.sportngin.com`, `assets.ngin.com`, `app-assets*.sportngin.com`) — used by SE for brand logos, banner graphics, content images, theme JS/CSS — are **NEVER** matched by the link-removal rule. The detection logic looks at the URL *path*, not the host, so CDN URLs that happen to fall under sportngin.com don't false-positive.
- **`TODO (GENERATE):` re-host every `sportngin.com` / `assets.ngin.com` / `app-assets*.sportngin.com` asset URL found in scraped content to S3 (via `S3AssetUploader::putFromUrl`).** The rebuilt site must have **zero** live SportsEngine dependency at serve-time — no images, no JS, no CSS pulled from sportngin/ngin hosts. BrandExtractor already handles the brand logo at INGEST; the rest is a GENERATE concern.

## GENERATE — IR pass (v1, irPass only)

`App\Services\Generate\IrPass::run(SitePlan, Manifest): IrPassResult` runs ONE structured Opus 4.8 call (via the injectable `IrPassAgent`) to produce:

- a compact **`GlobalStyleBrief`** (brand voice, palette, layout conventions, nav — nav is echoed from `SitePlan.nav`, not re-derived by the LLM); and
- per-page **`Ir`** (page_slug, page_title, nav_order, ordered abstract block intents).

**Scope is tight** — the IR pass is *just* the architecture call. Block-fill (Sonnet 4.6 per BUILD.md), `GeneratePageJob`, and `Bus::batch()` fan-out are built but UNCACHED and not yet exercised against real Sonnet — see "GENERATE — block-fill" below. The deterministic assembler, validation/repair, `createDraftSite`, placeholder blocks, asset re-hosting, and the demo are all **not built yet** — separate seams that come after this checkpoint.

### IR pass scoping (CRITICAL)

IR is generated **only** for pages whose disposition is `keep` AND whose `kind === 'page'`. Everything else is excluded *before* the LLM call:

| Disposition / kind | IR? | Why |
|---|---|---|
| `keep` + `kind=page` | **yes** | content page rebuilt from scraped content |
| `platform_dynamic` (Schedule/Scores/Standings/Roster/Teams/Divisions/Contacts/Calendar/News) | no | becomes a TeamLinkt platform block via a **separate later seam**; the LLM never designs IR for one |
| `subsumed` | no | represented by an ancestor `platform_dynamic`'s block |
| `park` / `drop` | no | absent from the rebuilt site (drop never emitted in v1) |
| `dynamic` (vestigial) | no | not rebuilt as content |
| `keep` + `kind=external` (LinkNode / Dibs / etc.) | no | preserved as a nav link only — no content to design |

The filter lives in `IrPass::extractKeepContentPages()`. Tests assert that platform_dynamic, subsumed, parked, and external pages never reach the agent's `$seen->keep_pages`.

### Faithful-rebuild guarantee: validate → targeted retry → flag

Opus is allowed to silently drop pages from a large batch (observed on the first real run: 2 of 16 keep-pages missing from the response). The IR pass NEVER lets that turn into a silent loss:

1. After the agent returns, `IrPass` diffs the returned `page_slug`s against the expected slugs (single-sourced via `App\Services\Generate\PageSlug::of()` — the helper the prompt and the orchestration both use; drift here would re-introduce silent loss).
2. If anything is missing, the agent is called a **second** time with **only the missing pages** (full nav still passed for context; the retry's `style_brief` is discarded — the first call's is authoritative).
3. If anything is **still** missing after the retry, it lands in `IrPassResult.failures` as an explicit `IrPassFailure` (slug, title, page_node_id, reason). `IrPassResult.status` flips to `Partial` so the ConversionLog can flag the conversion.

**Crucially**: missing pages are NEVER replaced by a stub Ir entry with placeholder content. A blank stub masquerading as a rebuilt page is worse than a visible failure — `IrPass` would rather a reviewer see the gap and re-run than ship a fake page.

### Schema-agnostic IR (still the rule)

`IrBlock.component_type` is an **abstract intent name** (`'hero'`, `'paragraph'`, `'card'`, `'cta'`, `'gallery'`, `'team_grid'`, …), NEVER a Puck-specific PROP name (`'background_image'`, `'subheading'`, `'cta_label'`). The recommended vocabulary lives in the agent's system prompt; the assembler is the only place that maps these abstract intents to real Puck components, and it doesn't exist yet.

### Anthropic config gotcha (still applies)

`AnthropicIrPassAgent` pins `Lab::Anthropic` + `claude-opus-4-8` via `#[Provider]` / `#[Model]` attributes, since `config/ai.php` defaults to OpenAI. The same `HasStructuredOutput` + JSON schema pattern from `AnthropicClassifierAgent`.

## GENERATE — block-fill (v1, slice 2c — wired, uncached, not yet exercised against real Sonnet)

`App\Services\Generate\BlockFill::run(IrPassResult, SitePlan, Manifest, conversionId): BlockFillResult` dispatches ONE `App\Jobs\GeneratePageJob` per IR page via `Bus::batch` on a concurrency-capped Horizon queue. Each job calls `BlockFillAgent` (production: `AnthropicBlockFillAgent`, claude-sonnet-4-6, `HasStructuredOutput`, `#[Timeout(600)]`) with the page's `Ir` + its REAL captured body + the per-conversion `GlobalStyleBrief` and writes one `FilledPage` (success) or one `BlockFillFailure` (terminal failure) to `BlockFillResultStore`.

### Thin job payload + per-conversion side stores

`GeneratePageJob` carries only `{conversion_id, page_slug, Ir, ContentRef, org_id}`. The `GlobalStyleBrief` is fetched from `BlockFillContextStore` (cache-backed, keyed by conversion_id) and the body is re-resolved via `ContentLoader`. This keeps queue rows small even for sites with 20+ pages. The two stores (`BlockFillContextStore`, `BlockFillResultStore`) are cache-backed and use the application default cache driver — array in tests (CACHE_STORE=array), Redis in prod.

### Faithful-rebuild guarantee chains across stages

Same posture as the IR pass: every `IrPassResult.pages` slug must end up in `BlockFillResult.pages` OR `BlockFillResult.failures`, exactly once, never as a stub, never silently absent. Reconciliation is the AUTHORITY — `Bus::batch`'s success flag is not. The orchestrator diffs returned `FilledPage` slugs against the input IR slugs and surfaces:

1. **Pre-flight failures** — IR slug → no matching `InventoryPage`, no URL, no `ContentRef` on manifest, or a chained `ContentExtractionFailure`. NO Sonnet call burned for these.
2. **Per-job terminal failures** — the job's `handle()` catches `Throwable` and writes a `BlockFillFailure` (so `Bus::batch` is never cancelled by one bad page; the exception never propagates past the job boundary).
3. **Silently-absent slugs** — a job that never wrote anything to the result store gets a synthetic `BlockFillFailure` with reason "page silently absent from result store after batch (job never wrote)".
4. **Upstream IR-pass failures** — `IrPassResult.failures` pass through as `BlockFillFailure`s with reason prefixed `ir-pass-failure:` so SCORE & LOG sees the page once across stages.

`BlockFillStatus::Failed` ONLY when the upstream `IrPassResult.status === IrPassStatus::Failed` (e.g. over-capacity abort). Anything else with failures is `Partial`. NEVER a stub `FilledPage`.

### Fabrication guard (this is where real copy gets written)

The agent's system prompt enforces "every prop value must be supported by `body_markdown` — do NOT invent names, dates, prices, contacts, programs, statistics, testimonials, claims that aren't in the body. If the IR's content_brief points at material the body lacks, fill from what IS there and lower confidence." The full captured body goes in the user message verbatim (per-page → no batching budget pressure). Each emitted `FilledBlock` carries a `source_quote` field: the body snippet (≤240 chars, substring of body_markdown) that anchored the block's content. Required (non-empty) for content blocks (Hero/Heading/Text/Card); empty-OK for prop-style blocks (Image, ButtonGroup, Columns wrapping children).

### Schema-shaped FilledBlock (block-fill, not assembler, picks the schema type)

`FilledBlock.component_type` is the SCHEMA-NAMED type (`'Hero'`, `'Heading'`, etc. — matches a `ComponentDefinition.type` from `ComponentSchema`), not the IR's abstract intent. The agent resolves abstract intent → schema type using mapping rules in the system prompt ('hero' → Hero, 'paragraph' → Text, 'team_grid' → Columns of Cards, etc.) and emits `props` keys matching the schema's `FieldDefinition` keys. The deterministic assembler in the next slice is the SINGLE schema-aware validation point that turns `FilledPage` into `PuckOutput` — it strictly validates `props` against the schema and runs at most one repair attempt before flagging.

### In-pass self-critique — soft signal only

`FilledPage` carries `self_assessment` (1-3 sentence model reflection) and `confidence` (0..1). BUILD.md specifies the agent drafts → audits against the rubric → revises in place → returns the revised output. The REVISE step is the value; the score is informational. **Block-fill NEVER gates on `confidence`** — low-confidence pages still ship and the trusted score (`structuralConfidence`, SCORE & LOG) is what flags conversions. Same posture as classifier confidence, IR-pass completeness, and stage-4 monitoring: trust structural/deterministic signals over LLM self-report.

### Why uncached for v1 (and what we'd change)

`AnthropicBlockFillAgent` ships UNCACHED — see Known Gaps below. The shared prefix (schema + GlobalStyleBrief + rubric) is the same on every call in a site and would seed once + read N-1 times under Anthropic's 5-min ephemeral cache. The blocker is in `laravel/ai`'s Anthropic gateway and is deferred to its own slice when volume justifies the bespoke client. Block-fill's interface (`BlockFillAgent::run(BlockFillInput): FilledPage`) is the seam — swapping the impl behind it doesn't touch orchestration.

## Known gaps / next slices

- **SE-platform CONTENT — page-level: handled. Block-level: NOT yet handled.** PLAN's phase 1.5 (`SePlatformContentDetector` + the body-content park in `RootNavPlanner`) now parks pages whose ENTIRE body is SportsEngine platform/tutorial content (validated on tbirdhoops: SE Parents page + Unsubscribe page). The detector requires all three of: ≥3 outbound links to SE-platform-tutorial hosts, ≥0.70 ratio, ≥2 distinct SE-platform vocabulary phrases — the vocab signal is load-bearing against the curated-SE-links false-park case (an org page that LINKS to SE help articles but writes its own copy says "click here", not "MySE"/"the SE Bar"/"Team Management Guide"). **Block-level scrubbing still missing**: an otherwise-org page can contain an embedded SE-platform block (e.g. the tbirdhoops Home page's "Stay Connected To Your Team With SPORTSENGINE" section with iTunes/Google Play app-download links). Page-level park is correctly NOT firing on Home — the page is overwhelmingly org content — but the embedded block remains. Block-level scrubbing belongs at GENERATE assembler/block-fill time, not PLAN. Next slice when we get to it. Project read: catch upstream via an IR-pass-prompt addendum ("skip body sections whose primary content is SE onboarding/tutorial") plus a downstream deterministic `SePlatformBlockScrubber` (post-assemble) reusing `SePlatformContentDetector`'s patterns at block level — two cheap reinforcing catches, neither in the block-fill prompt itself (would mix faithful-rendering with selective-omission).

- **Block-fill's real async `Bus::batch` path is NOT yet exercised against a live run.** The tbirdhoops live dump (slice 2c checkpoint, 7 pages) ran with `QUEUE_CONNECTION=sync` — jobs executed inline in the dispatching process, in serial. What that validated: the fabrication guard (real Sonnet wrote real copy traceable to the body, all 10 board members verbatim, no invented names/dates/prices), the reconciliation logic (every IR slug landed in `pages` or `failures` exactly once, no stubs even when the IR-pass slug-echo bug surfaced upstream), and the schema-shape wiring. What it did NOT validate: a Horizon worker picking jobs off Redis, the concurrency cap holding under real parallelism, `Bus::batch` `then()` / `catch()` callbacks firing after parallel completion, partial-failure semantics when some workers crash mid-job. That validation happens when the trigger endpoint + Horizon worker config land (build step 6 — Trigger + queue wiring). Until then: local dev dumps need `QUEUE_CONNECTION=sync php artisan <command>` because the local default is `database` (no worker means jobs sit in the `jobs` table; block-fill's reconciliation correctly surfaces every page as a synthetic "silently absent" `BlockFillFailure`, which is loud but useless for the dev loop).

- **`source_quote` substring verification is naive about formatting.** The tbirdhoops live dump used a literal substring check (`str_contains($body, $quote)`) to flag possible fabrications; it produced 19 false-positive ⚠️ warnings across 7 pages caused by THREE benign reformatting categories: (a) markdown escape stripping — body has `\*\*X\*\*` (Firecrawl-escaped), quote has `**X**`; (b) Unicode apostrophe normalization — body has `’` (U+2019), quote has `'` (U+0027); (c) structural-pointer whitespace collapse — multi-paragraph list in body collapsed to single-line summary in quote (every individual sub-line still a verbatim substring). None of the 19 was an actual fabrication. A stricter check — strip Firecrawl backslash escapes → NFKC-normalize → substring-match — would reduce false-positive warnings to true paraphrases. Belongs in the SCORE & LOG structural-confidence layer (downstream verification), NOT in block-fill itself (block-fill's job is faithful rendering; verification belongs as its own deterministic signal).

- **`GeneratePageJob` ships `$tries = 1` — no per-page retry on transient errors yet.** A 503 / network blip / Anthropic 429 on one page immediately fails that single page (visible, no stub — the job catches Throwable and writes a `BlockFillFailure`), but the conversion goes Partial and there's no automatic recovery. Recovery = re-run the whole conversion. Safe (no fabrications, no silent loss) but wasteful: at a 50-page site one transient blip partial-fails the conversion. Add per-page retry-on-transient-error (Horizon `$tries = 3` with backoff, classifying transient vs terminal) BEFORE we run block-fill at volume. Fine for tbirdhoops checkpoint where N=7.

- **Prompt-caching the shared block-fill prefix is the biggest speed/cost win at volume, deferred for v1.** `AnthropicBlockFillAgent` ships UNCACHED. The shared prefix (schema + GlobalStyleBrief + rubric + faithfulness rules) is the same across every page in a conversion — a perfect fit for Anthropic's 5-min `cache_control: {type: 'ephemeral'}` ephemeral cache, which would seed on call #1 and read on calls 2..N. The blocker is in `vendor/laravel/ai/src/Gateway/Anthropic/Concerns/BuildsTextRequests.php:31` — the gateway sends `system` as a plain string (`$body['system'] = $instructions;`), and Anthropic prompt caching requires the structured-system-blocks array shape with `cache_control` markers. Options when we pick this up: (a) wedge a structured system through `providerOptions` (the gateway's `array_merge($body, $providerOptions)` would override) — but `Promptable::prompt()` doesn't expose per-call providerOptions today; (b) own a bespoke Anthropic Messages HTTP call for block-fill (block-fill is the highest-volume LLM call in the engine — the cache win is real) with `cache_control` baked in, behind the same `BlockFillAgent` interface so tests stay clean; (c) wait for `laravel/ai` to ship cache_control support. Pick (b) when volume justifies it.

## Guardrails (from BUILD.md — these come up repeatedly)

- DTOs + Larastan are the strictness net (PHP isn't TS) — make them strict.
- Each LLM stage = a `laravel/ai` **Agent** class with structured output matching the contract. Use the SDK's **testing fakes** for per-stage tests against fixtures.
- Orchestration (jobs, batch, retries) is a thin separate layer from clever logic.
- **No abstraction not asked for.** The user owns the prompts and the planner's keep/merge/drop logic; Claude owns plumbing, clients, queue/batch, the deterministic assembler/validator, and the throwaway demo.
- A test per stage against a real fixture. Keep raw scrape + manifest for fixture replay without re-scraping.
- Admin emails are PII — out of general logs; redact/scope retention.
- Idempotency on the trigger (dedupe key per account). Clear `failed | partial` state, never a silent hang.
