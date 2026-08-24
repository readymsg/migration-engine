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
- **`subsumed`** — descendant of a `platform_dynamic` node **whose BlockType subsumes** (Calendar, News — see per-BlockType subsumption below). The ancestor's block represents the whole subtree, so descendants are NOT scraped, classified, or independently rebuilt. Absent from `nav` + `kept_pages`, present in the ledger with reason `"subsumed by parent <BlockType> block at '<parent label>'"`. Reversible — a reviewer can promote one back if the block doesn't cover their case. Crucially, subsumed descendants are NEVER sent to the LLM (deterministic platform_dynamic catches them in phase 1; LLM-returned platform_dynamic catches them retroactively in phase 3).
- **`park`** — set aside, absent from `nav`/`kept_pages`, present in the ledger. Used for high-confidence (> 0.80) LLM parks/drops, unknown-shape nodes, and SE platform/tool/help links.
- **`drop`** — NEVER emitted in v1. High-confidence drops are rewritten as `park` (reversibility).
- **`merge`** — NEVER emitted in v1. The engine doesn't auto-fold pages; a model `merge` is rewritten as `keep` with the merge target preserved in the ledger reason for human review.
- **`dynamic`** — vestigial fallback for **unrecognized SE Instance types** we haven't mapped yet (`dynamic_other`). Calendar, NewsNode, LeagueInstance, DivisionInstance, and TeamInstance all route to specific `PlatformBlockType`s (see mapping below); anything else (`TournamentInstance`, `SeasonInstance`, …) stays vestigial `dynamic` + a visible ledger note (`"no platform block mapping yet"`). Deliberate safety net: safer to fail visibly on a novel Instance type than mis-map it to something that doesn't fit.

### Recall thresholds (enforced in `RootNavPlanner::applyRecallBias`)

A model `park`/`drop`/`platform_dynamic` is honored only when confidence is **strictly greater than 0.80**. At or below 0.80 the page is `keep`-ed and the model's verdict is preserved in the reason (`recall-biased keep (model wanted … @ 0.80: …)`) so a reviewer can still act.

### platform_dynamic detection (hybrid, conservative)

Three deterministic paths run BEFORE the LLM, plus an LLM fallback:

1. **node_type-driven** — full SE `node_type` → `PlatformBlockType` mapping:
   - `Calendar` → `PlatformBlockType::Calendar` (aggregate feed; subsumes descendants)
   - `NewsNode` → `PlatformBlockType::News` (aggregate feed; subsumes descendants)
   - `LeagueInstance` → `PlatformBlockType::Teams` (hierarchy directory; does NOT subsume)
   - `DivisionInstance` → `PlatformBlockType::Divisions` (hierarchy directory; does NOT subsume)
   - `TeamInstance` → `PlatformBlockType::Team` (singular team page; does NOT subsume)
   - Any other non-empty `node_type` → `kind = 'dynamic_other'` → `DecisionAction::Dynamic` + visible ledger note. Safety net for unrecognized Instance types (`TournamentInstance`, `SeasonInstance`, …).

   SE's structural classification is enough; the LLM is never asked.
2. **Name-map** — matches only unambiguous data-listing labels on `kind=page` nodes: `standings` → Standings; `score(s)/result(s)` → Scores; `schedule(s)` → Schedule; `roster(s)` → Roster; `division(s)` → Divisions; `teams` → Teams **only when the node has children** (a leaf "Teams" page is content, a Teams parent of team pages is a directory). Ambiguous words (`tryouts`, `recruiting`, `programs`, `events`, `camps`, `contacts`) are intentionally NOT in the map — they go to the LLM.
3. **Per-BlockType subsumption** — a `platform_dynamic` node subsumes its descendants ONLY when `PlatformBlockType::subsumesDescendants()` returns `true`. Rule:
   - **Subsumes** (aggregate feed — one page carries the whole feature): `Calendar`, `News`.
   - **Does NOT subsume** (hierarchy — each level is a distinct user destination): `Teams`, `Divisions`, `Team`, `Schedule`, `Scores`, `Standings`, `Roster`, `Contacts`.

   This is the LOAD-BEARING silent-loss gate. Universal subsumption (the pre-slice behavior) would swallow the entire team subtree of cjfl / langdon when their `LeagueInstance` at depth-0 became PlatformDynamic — silently deleting 19 / ~100 team pages. Per-BlockType subsumption preserves the hierarchy while still collapsing aggregate feeds. `RootNavPlanner::classify()` runs three phases: phase 1 = deterministic + early subsume (subsuming platform_dynamic descendants are never even sent to the LLM); phase 2 = LLM batches for remaining ambiguous pages; phase 3 = retroactive subsume of LLM-returned platform_dynamic descendants (only if the LLM's BlockType subsumes).
4. **LLM fallback** for semantic variants like "Game Schedule" or "League Standings". Strict > 0.80 threshold; below it the page is recall-biased to keep.

### `PlatformBlockType::Team` vs `::Teams`

Two distinct enum values:

- **`Teams` (plural, directory)** — represents a Teams DIRECTORY at the top of a league (cjfl's "Teams" depth-0 nav, tenacity's "TEAMS" name-matched at depth-0). Maps from `LeagueInstance` node_type OR from `kind=page` label-match with children.
- **`Team` (singular, dedicated page)** — represents ONE team's dedicated page (roster + schedule + team info). Maps from `TeamInstance` node_type. One TeamInstance in SE's hierarchy → one PlatformTeam block per PuckOutput.

Neither one subsumes; a directory Teams page + its underlying Team pages all survive as distinct PuckOutputs. The rebuilt site has both a Teams directory AND the per-team pages under it.

### Engine ROUTES; product renders later (deferred rendering)

This slice makes the engine RECOGNIZE and ROUTE league hierarchy without silent loss — no team subtree gets deleted. It does NOT make league team pages RENDER visibly: the PlatformTeam / PlatformDivisions / PlatformTeams blocks are **placeholder stubs** in the preview renderer (`resources/js/preview/components/PlatformBlockStub.tsx`), and the TeamLinkt product's own builder does not yet have a `PlatformTeam` React component either. A landed draft carries the block; the product catches up when its renderer ships.

Same posture as brand passthrough: the engine emits the correctly-shaped block, the product-side rendering is a separate slice on the product's roadmap. Do NOT re-open this in the engine — the block's shape (`{type: 'PlatformTeam', props: {org_id}}`) is fine; what's missing is a runtime component in TeamLinkt's Puck config that renders it. Structural correctness (page represented in page_map, nav resolves, no silent loss) is what this slice delivers.

### Registration intent

A nav node whose label or URL matches `\b(register|registration)\b` is classified `keep` with the ledger note `"registration link — GENERATE should retarget to TeamLinkt secure registration URL"`. The nav entry survives; only the destination is rewritten by GENERATE. This is intentionally narrow word-matching (`Sign Up` does NOT match — that goes to the LLM).

### SE platform LINKS vs SE CDN ASSETS — different concerns

Two SportsEngine-flavored signals, handled completely differently:

- **SE platform / tool / help LINKS in nav** → `park`. Detected by: `external_subtype === 'se_tool'` (the hardcoded `Dibs` toolsLink sibling SE injects on every site); OR label matching `/sports\s*engine/i`; OR URL path matching `/sportsengine`, `/sportsengine/*`, `/dib_sessions*`, `/sn_signin`, `/sn_login`, `/se_login`, `/se_signin`. These nav entries do not carry over to the rebuilt site.
- **SE CDN asset URLs** (`cdn*.sportngin.com`, `assets.ngin.com`, `app-assets*.sportngin.com`) — used by SE for brand logos, banner graphics, content images, theme JS/CSS — are **NEVER** matched by the link-removal rule. The detection logic looks at the URL *path*, not the host, so CDN URLs that happen to fall under sportngin.com don't false-positive.
- **`TODO (GENERATE):` re-host every `sportngin.com` / `assets.ngin.com` / `app-assets*.sportngin.com` asset URL found in scraped content to S3 (via `S3AssetUploader::putFromUrl`).** The rebuilt site must have **zero** live SportsEngine dependency at serve-time — no images, no JS, no CSS pulled from sportngin/ngin hosts. BrandExtractor already handles the brand logo at INGEST; the rest is a GENERATE concern.

## GENERATE — IR pass (v1, ALWAYS-CHUNKED two-agent seam)

`App\Services\Generate\IrPass::run(SitePlan, Manifest): IrPassResult` runs the IR pass as **two agents, always chunked** — no single-call path exists any more. Every conversion pays exactly `1 + ceil(N/15)` Opus 4.8 calls, where N is the keep-content page count:

1. **Brief-deriver** (`IrBriefDeriverAgent`, prod: `AnthropicIrBriefDeriverAgent`) — ONE call against a **bounded sample** of pages (depth-0 priority + fallback if depth-0 set is thin; capped at `BRIEF_SAMPLE_LIMIT = 12`). Returns the `GlobalStyleBrief` (brand voice, palette, layout conventions, nav — nav echoed from `SitePlan.nav`). The brief is the singular cross-chunk coherence anchor.
2. **Chunk-designer** (`IrChunkDesignerAgent`, prod: `AnthropicIrChunkDesignerAgent`) — K calls, one per chunk of at most `CHUNK_PAGE_LIMIT = 15` pages. Each receives the brief as LOCKED input and designs per-page `Ir` (page_slug, page_title, nav_order, ordered abstract block intents).

The old combined `IrPassAgent` / `AnthropicIrPassAgent` / `IrPassInput` / `IrPassAgentResponse` are deleted — the single-call path is gone. This replaced the abort mode where a 34-page site (cjfl) exhausted the model's output-token budget and returned nothing; every site now converts.

### Why always-chunked (not "chunked only when large")

Two agents on every site is intentional: it avoids branching orchestration for a special-case tier ("small enough to fit in one call") that would silently regress the moment a site crossed the boundary. Every site now takes the same path, tested by the same fake-agent tests, with predictable cost (small site: 1 brief + 1 chunk = 2 Opus calls; large site: 1 brief + 3-7 chunk calls).

**Incidental win**: the dedicated brief-deriver reliably emits a populated palette where the combined single-call left it empty. Validated on both live captures — cjfl 0→5 hex codes, tenacity 0→5 hex codes. The narrower prompt (brief-only, bounded sample) is what enables it. Not a scored goal; a partial close of a long-standing quality gap.

### IR pass scoping (CRITICAL, unchanged)

IR is generated **only** for pages whose disposition is `keep` AND whose `kind === 'page'`. Everything else is excluded *before* any LLM call:

| Disposition / kind | IR? | Why |
|---|---|---|
| `keep` + `kind=page` | **yes** | content page rebuilt from scraped content |
| `platform_dynamic` (Schedule/Scores/Standings/Roster/Teams/Divisions/Contacts/Calendar/News) | no | becomes a TeamLinkt platform block via a **separate later seam**; neither agent ever designs IR for one |
| `subsumed` | no | represented by an ancestor `platform_dynamic`'s block |
| `park` / `drop` | no | absent from the rebuilt site (drop never emitted in v1) |
| `dynamic` (vestigial) | no | not rebuilt as content |
| `keep` + `kind=external` (LinkNode / Dibs / etc.) | no | preserved as a nav link only — no content to design |

The filter lives in `IrPass::extractKeepContentPages()`. Additionally a per-page body-size guard (`MAX_BODY_BYTES = 50_000`) flags pages with markdown bodies too large to safely send — they land as content-failure `IrPassFailure`s BEFORE any LLM call, never reach the brief sample, never reach a chunk.

### Faithful-rebuild guarantee — union reconciliation across chunks is the AUTHORITY

Chunking multiplies the surface for silent loss (per-chunk drops AND per-chunk throws), so reconciliation is done at every layer and the whole-conversion diff is the source of truth:

1. **Per-chunk targeted retry** — after each chunk's initial response, `IrPass` diffs returned `page_slug`s against that chunk's expected slugs (via `PageSlug::of()` — the same single-sourced helper the agents use). If anything is missing, that chunk is called AGAIN with ONLY its missing pages. Anything still missing after the retry becomes an `IrPassFailure` for that chunk.
2. **Per-chunk try/catch** — a chunk that throws (429, malformed response, timeout) synthesises one `IrPassFailure` per page in the chunk. Never short-circuits the loop; remaining chunks still run.
3. **Whole-conversion diff** — after all chunks: every keep-content page appears in `IrPassResult.pages` OR `IrPassResult.failures`, exactly once. No chunk's success flag is trusted; the diff-the-universe check is.
4. **Brief-deriver failure degrades gracefully** — a brief-deriver throw becomes an empty brief + a sentinel `*style_brief*` `IrPassFailure`, and chunk-designer calls still run (no coherence anchor, but per-page IR still produced). Partial output beats throwing away all per-page work over a coherence-anchor failure.

**Status resolution**: `Complete` when zero failures; `Partial` when any failure but at least one page designed; `Failed` only when every designable page failed wholesale (the chunked equivalent of the old single-call catastrophe — currently no live path produces this, but it's the correct terminal case).

**Crucially**: missing pages are NEVER replaced by a stub Ir entry with placeholder content. A blank stub masquerading as a rebuilt page is worse than a visible failure — `IrPass` would rather a reviewer see the gap and re-run than ship a fake page.

### Schema-agnostic IR (still the rule)

`IrBlock.component_type` is an **abstract intent name** (`'hero'`, `'paragraph'`, `'card'`, `'cta'`, `'gallery'`, `'team_grid'`, …), NEVER a Puck-specific PROP name (`'background_image'`, `'subheading'`, `'cta_label'`). The recommended vocabulary lives in each agent's system prompt; the assembler is the only place that maps these abstract intents to real Puck components.

### Anthropic config gotcha (still applies to both agents)

`AnthropicIrBriefDeriverAgent` and `AnthropicIrChunkDesignerAgent` each pin `Lab::Anthropic` + `claude-opus-4-8` via `#[Provider]` / `#[Model]` attributes, since `config/ai.php` defaults to OpenAI. Same `HasStructuredOutput` + JSON schema pattern used by `AnthropicClassifierAgent` and `AnthropicBlockFillAgent`.

### Live-validated on cjfl (34-page abort → 31-page Complete)

The chunking slice was checkpointed against two live sites (~$6 total). cjfl (previously Failed/34-abort under the single-call path) converts as **Complete on IR + block-fill, Partial only from a pre-existing draft-landing Teams-nav gap unrelated to IR**. Coherence across the 3 chunks is strong: the brand_voice names CJFL-specific editorial features by name and grounds in the 1890 founding legacy; layout_conventions carry the domain-specific BCFC/PFC/OFC conference grouping. Tenacity was re-run as a regression check on a site that already worked under the single-call path — voice equivalent, palette 0→5 improved, conventions comparable-to-better. See `tests/Fixtures/blockfill/cjfl.json` (durable large-site replay fixture, new) and `tests/Fixtures/blockfill/tenacityvolleyball.json` (regenerated under the chunked path).

## GENERATE — block-fill (v1, slice 2c + async-correctness slice)

`App\Services\Generate\BlockFill` has three public methods split along the async boundary:

1. **`dispatch(IrPassResult, SitePlan, Manifest, conversionId): void`** — preflight-resolves ContentRefs, builds the `GeneratePageJob` list + preflight failures, writes a `BlockFillReconcileState` to the result store (the load-bearing hand-off — reconcile running on a worker later reads this back), writes the style brief to the context store, dispatches `Bus::batch(...)->finally()` with the finally callback dispatching a `ReconcileBlockFillJob`.
2. **`reconcile(conversionId): BlockFillResult`** — idempotent. Reads the reconcile-state, walks the expected slug set, produces a `BlockFillResult`, writes the reconciled result to the store (which doubles as the idempotency marker). Returns the reconciled result unchanged on any subsequent call. THIS METHOD IS THE FAITHFUL-REBUILD AUTHORITY — every expected slug becomes either a FilledPage, a stored BlockFillFailure, or a synthetic "silently absent" BlockFillFailure. Never a stub, never a hidden absence.
3. **`run(...)`** — sync-only convenience. Under `QUEUE_CONNECTION=sync` the whole pipeline (dispatch → batch → finally → ReconcileBlockFillJob → reconcile) runs inline in the calling process; `run()` returns the reconciled result. Under async queue this WILL return whatever the store contains at the moment (likely nothing), so callers on an async path must use `dispatch()` + read the reconciled-result store when the chain proceeds.

Each `GeneratePageJob` calls `BlockFillAgent` (production: `AnthropicBlockFillAgent`, claude-sonnet-4-6, `HasStructuredOutput`, `#[Timeout(600)]`) with the page's `Ir` + its REAL captured body + the per-conversion `GlobalStyleBrief` and writes one `FilledPage` (success) or one `BlockFillFailure` (terminal failure) to `BlockFillResultStore`.

### The async-correctness contract (silent-loss surface CLOSED)

The prior slice-2c write had `BlockFill::run()` inline-calling `reconcile()` right after `Bus::batch(...)->dispatch()`. Under sync queue that's fine (dispatch blocks). Under async, `dispatch()` returns immediately and reconcile ran against an empty store — producing a `BlockFillResult` where every page was a synthetic "silently absent" failure, while the actual results arrived on Redis with no reader. The refactored `dispatch/reconcile` split closes this surface. The four failure modes async opens are all covered:

1. **Worker OOM / SIGKILL mid-job** — the job dies before its `try/catch` writes anything. Neither FilledPage nor BlockFillFailure lands. `reconcile()` surfaces the slug as "silently absent" — visible failure, not silent loss. Covered by `chaos_worker_sigkill_killed_slug_surfaces_as_silently_absent`.
2. **Worker-level timeout SIGKILLs a long Sonnet call** — same shape as (1). Config fix in `config/horizon.php`: `supervisor-block-fill.timeout = 600` (was `60` — would kill any real Sonnet call). Chaos test `chaos_worker_timeout_65s_sleep_vs_60s_kill_surfaces_as_silently_absent` documents the failure mode.
3. **Batch callback `finally()` fails to fire** — the ReconcileBlockFillJob's own dispatch fails (worker OOM at exactly the wrong moment, Redis blip, callback payload evicted). Reconciled-result is absent. The **scheduled sweeper** (`engine:reconcile-stuck-conversions`, 1-min cadence) picks it up: finds `job_batches` rows where `finished_at IS NOT NULL AND cancelled_at IS NULL`, checks the reconciled-result namespace, invokes `reconcile()` if missing. Idempotent so overlapping ticks are safe. Chaos test `chaos_callback_never_fires_sweeper_picks_up_orphan_reconciles`.
4. **Redis job eviction** — `maxmemory-policy != noeviction` on Redis silently drops queued jobs under memory pressure. `pending_jobs` counter never decrements → batch never completes → callback never fires. Sweeper's second duty: stuck-batch detection (`finished_at IS NULL AND created_at < NOW() - 45min`). The 45-min threshold safely exceeds worst-case legitimate wall-clock (100 pages × 180s pathological per-page ÷ 10 concurrency = 30 min + 15 min buffer). Sized long deliberately: false-firing on a legit-slow batch would surface running pages as silently-absent and idempotency would freeze that stale result — the exact regression this slice exists to prevent, so we err long. Duty (a) — callback-loss recovery — has NO age requirement; it fires as soon as `finished_at` is set. `docker-compose.yml` pins `--maxmemory-policy noeviction` for local dev; production Redis MUST match. Chaos test `chaos_redis_eviction_of_queued_job_sweeper_is_only_recovery`.

`ReconcileBlockFillJob` sets `$tries = 3` (unlike GeneratePageJob's `$tries = 1`) — reconcile is cheap and idempotent; losing it strands a conversion, so the retry cost is trivially worth it.

### Bus::chain-native design + Partial-through-chain semantics

The pipeline (once step-6 wires the trigger endpoint) uses `Bus::chain` per stage: `[IngestJob, PlanJob, IrPassJob, BlockFillDispatchJob]` → batch runs → `ReconcileBlockFillJob` fires from `finally()` → chain continuation `[AssembleJob, PlatformRenderJob, DraftLandJob, LogJob]`. Each stage writes its own result DTO to a per-conversion store; the next stage reads.

**Partial is a valid downstream input; only uncaught throws halt the chain.** A `BlockFillResult` with status `Partial` (some FilledPages, some BlockFillFailures) MUST proceed to the Assembler — halting there would strand mostly-good conversions where 27 of 30 pages succeeded. `Assembler` handles Partial natively: renders the FilledPages, passes BlockFillFailures through as AssemblyFailures with `block-fill-failure:` prefix. Same posture at every stage. `ReconcileBlockFillJob` completes cleanly on Complete / Partial / Failed statuses — only uncaught exceptions (state missing, DB unreachable) halt the chain via Laravel's default halt-on-throw, and even then `$tries = 3` gives three shots before the dead-letter queue. The chain-semantics contract is enforced by `ReconcileBlockFillJobTest` — 4 tests covering Complete / Partial / IR-pass-Failed passthrough / missing-state (the one legit halt case).

### Reproducible local infra

`docker-compose.yml` at the repo root brings up Redis with `maxmemory-policy noeviction` (the load-bearing eviction policy that prevents queue silent loss). `docker compose up -d` before running Horizon locally. The chaos test suite (`tests/Feature/Async/`) validates the LOGIC via `Bus::fake` + direct store manipulation and runs green without Redis. The paired artisan command `engine:async-smoke-test [--pages=N]` exercises the same code path against real Redis + real Horizon workers when both are up — proves the machinery (worker startup, batch dispatch, finally firing, cross-process store reads) matches the logic tests.

### Thin job payload + per-conversion side stores

`GeneratePageJob` carries only `{conversion_id, page_slug, Ir, ContentRef, org_id}`. The `GlobalStyleBrief` is fetched from `BlockFillContextStore` (cache-backed, keyed by conversion_id) and the body is re-resolved via `ContentLoader`. This keeps queue rows small even for sites with 20+ pages.

`BlockFillResultStore` spans three namespaces per conversion: (a) per-page FilledPage/BlockFillFailure entries written by GeneratePageJobs; (b) the per-conversion `BlockFillReconcileState` written once by `dispatch()` (the async hand-off); (c) the final reconciled `BlockFillResult` written by `reconcile()` (also the idempotency marker). All cache-backed — array in tests (`CACHE_STORE=array`), Redis in prod. 24h TTL as backstop against orphaned entries.

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

## GENERATE — assembler (v1, slice 2d — deterministic, schema-aware)

`App\Services\Generate\Assembler::run(BlockFillResult): AssemblyResult` is the ONLY schema-aware point in the engine. Deterministic — NO LLM. Reads block-fill's `FilledPage`s and emits `PuckOutput`s ready for `ProductClient.createDraftSite()`, plus any per-block coercion issues and any whole-page failures.

### The validate → coerce → re-validate contract (BUILD.md "one repair attempt then flag")

Three internal pieces:

- **`BlockValidator`** — pure structural check against `ComponentSchema`. Reports every conformance violation it finds as a flat list of `ValidationIssue` with dotted paths (e.g. `props.columns[0].children[2].props.title`). Recurses into objects (`Hero.cta`), arrays-of-object (`ButtonGroup.buttons[]`), and `Columns.columns[].children` (special case — treated as nested Puck-shaped sub-blocks and validated against the full schema; accepts both `{component_type, props}` and `{type, props}` shapes).
- **`BlockCoercer`** — one repair pass per block, two-class split:
  - **NORMALIZATIONS** (value-preserving, SILENT — no issue emitted): stringy-number → number, h1-h6 case fix, whitespace trim, drop-unknown-prop-key, drop-missing-optional-field, scalar-into-text cast.
  - **SUBSTITUTIONS** (value-changing, recorded as `Substitution`): empty `ButtonGroup.buttons[].href` → `'#'`, select-value-not-in-options → first-option documented default, missing `Heading.level` → `'h2'`.
  - **DROPS** (block-losing, recorded as `Drop`): unknown `component_type`, required content field missing on Hero/Heading/Card/Text/Image where no safe substitution exists, wrong type that can't be losslessly coerced, required-and-empty-after-coercion array (e.g. ButtonGroup whose only button lost its label).
  - The cardinal rule: missing button LABEL is NOT substituted (inventing 'Learn more' would masquerade as real content) — the button item is dropped; if the group has no surviving buttons, the parent ButtonGroup is dropped.
- **`Assembler`** — top-level orchestrator. For each top-level `FilledBlock` calls the coercer, recurses into `Columns.children` via the coercer's nested handling, builds the Puck content array, and tracks per-page `AssemblyBlockIssue`s.

### Page-level failure posture (no blank Puck)

A page where SOME blocks survive emits a `PuckOutput` plus `AssemblyBlockIssue` entries on `AssemblyResult.block_issues_by_slug` — flagging the conversion as Partial. A page where EVERY block drops becomes an `AssemblyFailure` (NEVER a blank PuckOutput that would render empty — same posture as IR-pass/block-fill: visible failure beats a fake rebuild).

### Reconciliation (chains across stages)

Diff universe is EXACTLY `BlockFillResult.pages`. The assembler does NOT consult SitePlan — platform_dynamic pages are legitimately absent (filtered at IR-pass time) and the assembler MUST NOT phantom-fail them. `PlatformBlockRenderer` (slice 2e) is responsible for emitting their PuckOutputs from the SitePlan.ledger; draft-landing (slice 2f) folds both PuckOutput streams into one `array<page_slug, page_json>` for `ProductClient.createDraftSite()`.

`BlockFillFailure`s pass through as `AssemblyFailure`s with reason prefixed `block-fill-failure:` — so the conversion log sees every page once across the IR-pass → block-fill → assembler chain. Status: `Failed` only when upstream `BlockFillStatus::Failed`; `Partial` if any AssemblyFailure or any block_issues; `Complete` only when every FilledPage emitted a clean PuckOutput with no dropped/substituted blocks AND no upstream failures.

### Block-issue surface is a SIDECAR on AssemblyResult, not on PuckOutput

`PuckOutput` is the `createDraftSite()` contract; adding a non-Puck field would pollute the seam. `AssemblyResult.block_issues_by_slug: array<string, array<int, AssemblyBlockIssue>>` keeps PuckOutput pure and lets SCORE & LOG read both streams independently.

### Durable fixture: `tests/Fixtures/blockfill/tbirdhoops.json`

Canonical replayable `BlockFillResult` captured from a one-time real Sonnet 4.6 run against the tbirdhoops captured bodies. **Read by**: `AssemblerFixtureReplayTest`, the `engine:assemble-from-fixture` artisan command, and every downstream slice (draft-landing, preview, SCORE & LOG) that wants real FilledPages without re-spending LLM credits. **Regenerate** with `QUEUE_CONNECTION=sync php artisan engine:capture-tbirdhoops-block-fill` — costs ~1 Opus call (IR pass) + ~7 Sonnet calls (block-fill); no Firecrawl (`LocalDiskFirecrawlClient` reads from `storage/app/private/orgs/ngin-63620/scrapes/`). The captured run produced 7 FilledPages, status complete, and assembled with ZERO coercions of any kind under the current Assembler — `AssemblerFixtureReplayTest` enforces that going forward.

## GENERATE — platform-block renderer (v1, slice 2e — deterministic, schema-aware)

`App\Services\Generate\PlatformBlockRenderer::run(SitePlan, Manifest): PlatformRenderResult` is the renderer for `DecisionAction::PlatformDynamic` ledger entries — the pages PLAN classified as live-data features (Schedule, Roster, Teams, Divisions, Contacts, Calendar, News, etc.) that IR-pass deliberately filtered out before block-fill. Deterministic — NO LLM. Pure code over a closed PlatformBlockType → Puck-type table. Disjoint universe from the assembler by construction (the assembler reads `BlockFillResult.pages`; the renderer reads `SitePlan.ledger`).

### Schema shape — two scoped methods, one provider

`ComponentSchema` carries two methods that return disjoint sets:

- `all()` / `get()` / `types()` — CONTENT components. The closed set the block-fill LLM may emit; the set the assembler validates against.
- `platformBlocks()` — PLATFORM components. The closed set the renderer constructs from PLAN. The block-fill LLM is NEVER told about these.

The "assembler is the one schema-aware validation point" property holds correctly scoped: validate→coerce→re-validate runs over CONTENT blocks (where fabrication risk lives). Platform blocks are constructed from a closed table — no LLM, no fabrication, no coerce/repair pipeline needed. When `ProductClient.getComponentSchema()` lands, it'll deliver one export with both sets, slotting into this shape with no re-merge.

### PlatformBlockType → Puck mapping

Every enum value in `App\Data\PlatformBlockType` maps to a single Puck component. v1 carries ONE prop — `org_id` from `Manifest.org_id`. No `team_id` (v1 doesn't walk team subtrees per the site-rebuild scope cut), no `layout` knob (no source signal), no baked data. **"Placeholder" doesn't mean placeholder text** — the renderer emits a structurally-valid `Platform<X>` block; the runtime React component owns the empty-state ("Roster will appear here when teams are added") when day-1 the database has no rows for that org_id.

| `PlatformBlockType` | Puck `type` |
| --- | --- |
| `Schedule`, `Scores`, `Standings`, `Roster`, `Teams`, `Divisions`, `Contacts`, `Calendar`, `News` | `PlatformSchedule`, `PlatformScores`, `PlatformStandings`, `PlatformRoster`, `PlatformTeams`, `PlatformDivisions`, `PlatformContacts`, `PlatformCalendar`, `PlatformNews` |

Drift between the enum and `platformBlocks()` is caught by `PlatformBlockRendererTest::every_platform_block_type_enum_has_a_schema_definition` — adding a 10th `PlatformBlockType` without a matching definition fails the test loud.

### Faithful-rebuild guarantee + three defensive failure modes

Diff universe is EXACTLY the `PlatformDynamic` ledger entries. Each entry → one `PuckOutput` in `pages` OR one `PlatformRenderFailure` in `failures`, exactly once. NEVER a blank PuckOutput, NEVER a silent absence — same posture as `AssemblyResult` / `BlockFillResult` / `IrPassResult`. The renderer surfaces three failure modes even though two are unreachable under current invariants (the discipline is what's caught real bugs in adjacent stages):

1. **target-not-in-kept_pages** — defensive. `RootNavPlanner::decideIa` keeps PlatformDynamic pages in `kept_pages`, so this is currently unreachable. If PLAN ever drops them, the renderer surfaces the failure instead of silently skipping.
2. **null platform_block_type on PlatformDynamic entry** — defensive. The planner only emits PlatformDynamic with a non-null type (`applyRecallBias` requires `platform_block_type !== null`; the two deterministic emitters always set it).
3. **enum-with-no-schema-definition** — reachable in practice; catches enum/schema drift.

`PlatformRenderStatus` has NO `Failed` case — the renderer is a leaf of a deterministic table lookup, no upstream signal can fail it wholesale. `Complete` when every entry rendered cleanly; `Partial` when ≥1 PlatformRenderFailure was surfaced.

### Slug uses `PageSlug::of()` — same as content pages

The renderer's PuckOutput `page_slug` comes from `PageSlug::of(InventoryPage)` — the same helper IR-pass and block-fill use, so platform-page slugs share a convention with content-page slugs (`page-{node_id}` when `page_node_id` is set, label-slug fallback otherwise). Single-sourced via `PageSlug` so a future planner change can't silently fork the slug rule.

### The 2e/2f boundary

Slice 2e produces ONE thing: `PlatformRenderResult`. It does NOT merge with `AssemblyResult`, does NOT build `createDraftSite()`'s `array<page_slug, page_json>` payload, does NOT call `createDraftSite()`, does NOT log conversion status. That's slice 2f's job (draft-landing). 2f folds `AssemblyResult.pages ⊎ PlatformRenderResult.pages` into one map, unions the failure streams, decides per-conversion status, and lands the draft.

### Real-fixture replay

`PlatformBlockRendererFixtureReplayTest` runs PLAN (with `FakeClassifierAgent`) + the renderer against the rootNav fixtures we already have — no LLM, no network. Confirmed outputs:

- **tenacityvolleyball** → 2 platform PuckOutputs: TEAMS (name-matched → `PlatformTeams`, `page_slug=page-8116200`) and CALENDAR (Calendar node_type → `PlatformCalendar`, `page_slug=page-8115918`). Subsumed TEAMS children (11s & 12s, 13s & 14s, 15s-18s) are absent from the rendered pages — the parent's block represents them.
- **langdondiamonds** → 1 platform PuckOutput: Calendar (Calendar node_type → `PlatformCalendar`, `page_slug=page-7507237`). Cross-fixture confirmation that the calendar route isn't tenacity-specific.
- **tbirdhoops offline replay → 0 platform pages** is correct for the offline rootNav fixture, which contains no name-matching or NewsNode/Calendar pages. Whether tbirdhoops surfaces platform pages under a live PLAN run (real Haiku) is **unverified offline** — the LLM might classify pages the FakeClassifier keeps. The renderer ITSELF is validated against real platform pages via tenacityvolleyball + langdondiamonds; the tbirdhoops zero is a renderer-correctness signal ("doesn't phantom-render"), NOT a claim about the production tbirdhoops site's platform-content shape.

## GENERATE — SE-platform block scrubber (v1, post-assembly deterministic)

`App\Services\Generate\SePlatformBlockScrubber::run(AssemblyResult): AssemblyResult` runs AFTER the assembler and BEFORE draft-landing, dropping SE-injected content blocks (competitor ads, stale live-widget captures) that the block-fill agent faithfully rendered from the source body. Deterministic — NO LLM. Consumes and produces `AssemblyResult`; populates a new `scrub_issues_by_slug` sidecar with a visible audit trail. Every scrub emits a `ScrubIssue`; silent scrubbing is FORBIDDEN.

### Three PRECISION-FIRST detection layers

**Layer 1 — SE-promo href scan** (`SE_PROMO_HREF_PATTERNS`):

- `itunes.apple.com/*/app/sport-ngin/` (SE's iOS app store link)
- `play.google.com/store/apps/details?id=com.sportngin.` (SE's Android app store link)
- `sportsengine.com/solutions/` (SE marketing pages)

Deliberately NARROWER than `SePlatformContentDetector::SE_PLATFORM_PATTERNS` (which is calibrated for page-level "overwhelmingly SE-tutorial" judgment). Excludes `help.sportsengine.com`, `mobile-help.sportsengine.com`, `my.sportngin.com/user/`, `intercom.help/SportsEngine/` — those hosts ARE org-linkable (langdondiamonds' Coaches page and tenacityvolleyball's coach-help page both link to `help.sportsengine.com` articles legitimately). App-store + solutions are the unambiguous set: no org links to them; SE's template injects them. Actions per block type:

- `ButtonGroup.buttons[i].href` SE-promo → drop that button. If all buttons drop → drop the ButtonGroup.
- `Card.href` SE-promo → drop the whole Card (the link IS its CTA).
- `Hero.cta.href` SE-promo → clear the cta prop (Hero body kept — the promo CTA doesn't).

**Layer 2 — EXACT-match label whitelist** (`SE_PROMO_LABEL_WHITELIST`):

Closed set of full-string case-insensitive matches — NOT substring, NOT fuzzy. `strtolower(trim($label)) === $whitelist_entry`. Current entries: `"stay connected to your team with sportsengine"`, `"get the sportsengine app"`, `"sportsengine for apple users"`, `"sportsengine for android users"`, `"download the sportsengine app"`, `"sportsengine mobile app"`. A label that merely mentions SportsEngine ("we've been on SportsEngine since 2015") is LEFT ALONE — the href layer catches promo variants that matter; label scrubbing is only for the no-href label-only buttons ("Stay Connected..." with href="#"). **False-scrubbing real org content is silent-loss pointed at the wrong target — worse than missing a promo variant. Err TIGHT.** Adding a new entry is a decision to remove exactly that copy across every site the scrubber ever runs on.

**Layer 3 — stale-countdown pattern** (`/\b\d+\s+Days?\s+\d+\s+Hours?\s+\d+\s+Minutes?/i`):

SE's live JS widget scraped as static text after JS didn't run during Firecrawl fetch. Multi-unit format ("N Days N Hours N Minutes") is the signal — precise enough to not false-positive on natural copy. `Card` (top-level or nested inside `Columns.columns[i].children[j]`) whose `props.body` matches → drop the Card. If a Columns block's every nested Card matches → drop the Columns.

### Faithful-rebuild tension resolution

Scrubbing IS deliberate omission. Under the engine's usual posture that's a red flag. But this is the third leg of the SE-content-omission tripod:

1. SE platform LINKS in nav → parked (`RootNavPlanner::isSePlatformLink`).
2. SE platform CONTENT PAGES → parked (`SePlatformContentDetector`, phase 1.5 of classify).
3. SE platform CONTENT BLOCKS → scrubbed here.

All three surface the omission visibly in the ledger. Precedent consistent. SE-injected content isn't org content; the rebuilt TeamLinkt site must not carry it (competitor ad on a competitor-displacement rebuild is the highest-embarrassment demo item).

### Visibility — the audit trail

`ScrubIssue` per drop: `block_index`, `component_type`, `kind` (`SePromoHref | SePromoLabel | StaleCountdown`), `reason`, `dropped_content_summary` (short human-readable, e.g., "3 buttons: 2 app-store hrefs + 1 promo label"). The full dropped payload is NOT preserved — we don't need to reconstruct it, only to make it visible. SCORE & LOG surfaces the sidecar in the conversion log; a reviewer can see every scrub per page and undo a false positive.

### Validated on the captured fixtures — both halves

Two validation gates, both PASS (`tests/Unit/Generate/SePlatformBlockScrubberTest.php`, 6 tests / 66 assertions):

**Gate 1 (removes the ad)** — `tests/Fixtures/blockfill/tbirdhoops.json` Home page:
- Block #1 Columns (3 nested stale-countdown Cards: "Flight Tryouts", "Thunderbird Assessments", "Winter Basketball starts again in", each with body `0 Days 0 Hours 0 Minutes 0 Seconds`) → DROPPED as StaleCountdown.
- Block #5 ButtonGroup (SE-promo: "Stay Connected...", "SportsEngine for Apple Users", "SportsEngine for Android Users") → DROPPED as SePromoHref (2 app-store buttons hit Layer 1 + 1 label-only button hit Layer 2 → entire group empties → dropped).
- Every OTHER block on Home byte-for-byte identical.
- Every OTHER page on tbirdhoops byte-for-byte identical (only Home has SE content).

**Gate 2 (no false positives)** — the three real-org fixtures:
- `cjfl.json` (31 pages of Canadian Junior Football League): **ZERO scrubs.**
- `langdondiamonds.json` (18 pages) — CRITICAL: the Coaches page (`page-7507234`) block #9 Columns contains SEVEN `help.sportsengine.com` article links. Layer 1 must NOT touch these (they're org-authored, help.sportsengine.com is excluded from the scrubber's narrower pattern set). **ZERO scrubs, byte-for-byte identity across all pages.**
- `tenacityvolleyball.json` (20 pages): **ZERO scrubs.** Cross-fixture confirmation.

### Wiring position — post-assembly, pre-draft-landing

Runs between `Assembler::run()` and `DraftLanding::run()`. Draft-landing's ConversionResult now carries `scrub_issues_by_slug` as a passthrough (default empty when the scrubber didn't run). Bind via DI as a singleton in `AppServiceProvider`.

**Slice B (deferred)** — an IR-pass prompt addendum instructing the chunk-designer agent to skip SE-platform sections upstream. Reinforces this deterministic catch. Cost to validate: ~$3 (one live capture re-run). Not needed for demo readiness — Slice A alone closes the embarrassment gap. Add when convenient.

## GENERATE — draft-landing (v1, slice 2f — deterministic, the createDraftSite seam)

`App\Services\Generate\DraftLanding::run(conversionId, SitePlan, AssemblyResult, PlatformRenderResult, Manifest): ConversionResult` is the per-conversion fold of everything downstream of PLAN/IR-pass/block-fill/assembler/platform-render, and the ONE place in the engine that calls `ProductClient::createDraftSite()`. Deterministic — NO LLM.

### What it does

1. **Folds the two PuckOutput streams** — `AssemblyResult.pages` (content pages) ⊎ `PlatformRenderResult.pages` (platform_dynamic ledger entries) — into one `array<page_slug, page_json>` keyed by `PageSlug::of()`. Disjoint by construction (a page is `keep+kind=page` XOR `platform_dynamic`); a defensive slug-collision guard surfaces the unreachable case as a draft-landing `ConversionFailure` rather than silently overwriting.
2. **Reconciles `SitePlan.nav`** so each `NavItem.page_slug` joins into the page_map keys. Join key is exact `NavItem.label` against depth-0 `kept_pages` (mechanically guaranteed clean: `RootNavPlanner::decideIa` copies `$page->label` verbatim into `NavItem.label` in the same loop that adds the page to `kept_pages`). Output is `ResolvedNavItem[]` with three status cases: `Resolved` (page_slug keys into page_map), `UnmatchedExternal` (`kind=external` — LinkNode/toolsLink — legitimately has no PuckOutput; nav-layer concern for later, NOT a draft-landing failure), `Unresolved` (matched a depth-0 page but no PuckOutput exists — surfaced as a `ConversionFailure`).
3. **Unions failures** — `AssemblyResult.failures` + `PlatformRenderResult.failures` + slug-collision + nav-reconciliation failures — into `ConversionResult.failures` as flat `ConversionFailure` (page_slug, page_title, page_node_id, stage, reason). Chained reasons (`block-fill-failure:` / `ir-pass-failure:` prefixes already set by upstream stages) are parsed once at the lander seam to attribute `ConversionStage`, so SCORE & LOG can group by stage without re-parsing strings.
4. **Carries `AssemblyResult.block_issues_by_slug` forward** as a passthrough on `ConversionResult` — SCORE & LOG sees one DTO with per-block partial signals included.
5. **Computes `ConversionStatus`** via the truth table: `AssemblyStatus::Failed` → `Failed`; any failure OR any upstream Partial → `Partial`; otherwise `Completed`. A `createDraftSite` client error (network / 503) degrades a would-be `Completed` to `Partial` with the error in `failures`.
6. **Calls `createDraftSite` IFF status ≠ Failed.** Aborted conversions land NOTHING — `draft_id`/`draft_url` stay null, the failure list carries the upstream reason. Partial conversions still ship (a reviewable draft beats an invisible failure — same posture as the rest of the engine).

### Slug-reconciliation is double-locked at the source AND at the seam

`RootNavPlanner::slugOf()` was fixed in this slice to delegate to `PageSlug::of()` — one slug convention for every producer in the engine (single source of truth, per `PageSlug.php`'s own docblock). The lander ALSO reconciles `SitePlan.nav` at landing time, deterministically rewriting any drifted `NavItem.page_slug` to the page-id form. Two reinforcing layers:

- The producer fix prevents drift from being emitted on fresh runs.
- The landing-time reconciliation handles drift the planner emitted historically (e.g. the existing tbirdhoops `BlockFillResult` fixture's `style_brief.nav[].page_slug = "home"` form — captured before the planner fix). No fixture regen required.

### Draft-only safety is STRUCTURAL, not a flag

The `ProductClient` interface exposes exactly two methods — `getComponentSchema()` and `createDraftSite()`. There is no `publishSite()`, no `setSitePublished()`, no `published: bool` parameter on `createDraftSite`. The engine cannot publish — by construction. When the real HTTP client lands, it MUST call a product endpoint that is itself draft-only; do not grow a publish method on this interface. See `ProductClient` docblock for the full guarantee and the real-client verification gate (an integration test against a staging product confirming the endpoint genuinely lands publish=false) that becomes a required pre-prod gate when the stub is replaced.

### ConversionResult is the SCORE & LOG handoff

`App\Data\ConversionResult` (per-conversion DTO) — `conversion_id`, `org_id`, `page_map`, reconciled `nav`, unioned `failures`, passthrough `block_issues_by_slug`, `status`, `draft_id?`, `draft_url?`. SCORE & LOG consumes this single shape to produce `ConversionLog` — no need to re-read AssemblyResult / PlatformRenderResult / SitePlan independently.

### Tests

`DraftLandingTest` (8 unit cases): status truth-table, Failed-skips-createDraftSite (asserts 0 calls — the never-auto-publish-aborted-conversions invariant), defensive slug-collision, createDraftSite client error → Partial, external-nav handling. `DraftLandingFixtureReplayTest` runs the full pipeline end-to-end against the real tbirdhoops `BlockFillResult` fixture and asserts the lander's INVARIANTS (every Resolved nav keys into page_map; every Unresolved nav surfaces a draft-landing failure; createDraftSite is called iff status ≠ Failed; submitted page_map keys == built page_map keys) rather than the exact page set — so a future fixture regen can't false-positive the test.

## Step 6 — trigger endpoint + ConversionJob chain (v1 demo cut)

The demo-facing entry: paste a SportsEngine URL → click → watch it convert → land on the preview. Assembles the proven pieces (async block-fill, deterministic Assemble/Scrub/Platform/DraftLand) into a two-job chain fronted by three HTTP routes.

### Chain shape — two jobs, four stores

**`ConversionJob`** (pre-batch, `app/Jobs/ConversionJob.php`): runs INGEST + PLAN + IR-pass INLINE (all deterministic + total ~1-3 min wall clock), writes the `ConversionContext` (Manifest + SitePlan hand-off), then fires `BlockFill::dispatch` which schedules the per-page Bus::batch. Its role ends when the batch is on Redis. `$tries = 1` — a full-conversion re-run is a user action (they hit convert again), not a queue-level retry. `timeout = 1200` (20 min covers pessimistic pre-batch wall clock).

**`FinalizeConversionJob`** (post-reconcile, `app/Jobs/FinalizeConversionJob.php`): dispatched by `ReconcileBlockFillJob::handle()` after `BlockFill::reconcile()` writes its result. Reads `ConversionContext` + reconciled `BlockFillResult`, runs Assemble → Scrub → PlatformRender → DraftLand INLINE (all ms-scale), writes the final `ConversionResult` to `ConversionResultStore`, updates status to Complete/Partial/Failed. **Idempotent**: if `ConversionResultStore.get(id)` already returns a result, no-ops. `$tries = 3` (deterministic + idempotent + losing it strands the conversion).

**Graceful "not in a full pipeline" gate**: `FinalizeConversionJob::handle` checks if a `ConversionStatusSnapshot` exists first. If not, it returns silently — meaning `BlockFill::run`/`dispatch` was called outside a full ConversionJob (CaptureLive, chain-equals-inline test, direct BlockFill calls). Real bug case (snapshot exists but ConversionContext missing) still throws + failed()-hook writes a Failed status.

**`ReconcileBlockFillJob`** modified: after `BlockFill::reconcile()`, dispatches `FinalizeConversionJob`. Idempotent — safe to dispatch multiple times.

**Four cache-backed stores** (all in `app/Services/Conversion/`, TTL 24h):

- `ConversionContextStore` — Manifest + SitePlan hand-off between ConversionJob and FinalizeConversionJob.
- `ConversionStatusStore` — the polling contract. Advance/complete/fail write a `ConversionStatusSnapshot` at each stage boundary. Terminal-once (Complete/Partial/Failed is locked; subsequent advance/fail no-ops — sweeper re-drives can't mutate a terminated conversion). First-win on `fail()` so a downstream cascade doesn't overwrite the root cause.
- `ConversionResultStore` — final `ConversionResult`. Doubles as the Finalize idempotency marker.
- `ConversionDedupeStore` — (token, url) → conversion_id map with 10-min TTL. LOAD-BEARING for cost control: a nervous demo watcher hitting refresh must NOT trigger a second $2-6 Sonnet conversion.

### HTTP routes — `/api/conversions/*` under demo-token gate + throttle

```
POST /api/conversions          Body: {url}    → 202 (fresh) OR 200 (dedupe hit); JSON: {conversion_id, status_url, result_url, preview_url, deduped}
GET  /api/conversions/{id}/status              → snapshot + block_fill_progress (computed on read for stage=BlockFill)
GET  /api/conversions/{id}                     → ConversionResult when Complete/Partial; 409 with stage when not-ready; 404 when unknown
GET  /preview/{conversion_id}                  → additive to /preview/{slug}; React bundle reads live ConversionResult
```

- **`EnsureDemoToken` middleware** (`app/Http/Middleware/EnsureDemoToken.php`) — validates `X-Demo-Token` header against `env('DEMO_TOKEN')`. Missing env → 503 (refuses to accept anything, prevents accidental prod exposure with unset token). Missing/wrong header → 401.
- **`throttle:5,60`** on POST — 5 conversions per IP per hour. Tight for cost control (~$2-6 per conversion).
- **URL normalization for dedupe** — `strtolower(trim($url))`. So `HTTPS://EXAMPLE.COM/` and `https://example.com/` share a dedupe key.

### Progress signal — block-fill N-of-M, computed on read

`ConversionStatusStore` writes the base snapshot at stage boundaries. `block_fill_progress` is COMPUTED on `GET /api/conversions/{id}/status` when `stage=BlockFill`, by counting entries in the block-fill result store keyed against `BlockFillReconcileState.expected_slugs`. No new state to keep in sync — the block-fill machinery already tracks per-slug completion, we just present it. Frontend polls at ~2s.

### No-silent-hang contract (LOAD-BEARING for the demo)

Every job in the chain implements `failed()` to write `final_status=failed` with a `failure_reason` BEFORE the exception propagates up. Every silent-hang door has an explicit close:

- **ConversionJob throws** (INGEST/PLAN/IR-pass throw) → `failed()` writes Failed status inline. `NoSilentHangTest::conversion_job_ingest_throws_status_flips_to_failed` / `conversion_job_plan_throws_status_flips_to_failed`.
- **FinalizeConversionJob throws** (Assembler bug, DraftLanding client 5xx) → `failed()` writes Failed status. Sweeper's retry ($tries=3) provides recovery; final exhaustion → failed() fires. `NoSilentHangTest::finalize_conversion_job_throws_status_flips_to_failed`.
- **ConversionJob dies before begin() was written** (defensive) → `failed()` writes a bare Failed snapshot so `/status` returns SOMETHING. `NoSilentHangTest::conversion_job_failed_hook_writes_status_even_without_prior_begin`.
- **Reconcile succeeded, Finalize dispatch failed** (the mid-chain hang) → sweeper's duty (c) checks for `reconciled-result present + ConversionResult absent`, dispatches `FinalizeConversionJob` (idempotent). `NoSilentHangTest::sweeper_kicks_finalize_when_reconcile_succeeded_but_finalize_never_fired`.
- **BlockFill short-circuit paths** (IR-Failed / no jobs / all preflight-failed) — each explicitly dispatches `ReconcileBlockFillJob::dispatch($conversionId)` so the chain forwards to Finalize even when no batch runs. Reconcile's idempotency guard makes the dispatch safe.

Property test `NoSilentHangTest::status_snapshot_never_reports_non_terminal_stage_after_conversion_job_fails` is the umbrella invariant: NO matter how the job dies, the resulting status must be terminal. Never a spinner-forever demo failure.

### Chain-as-jobs = chain-as-inline (correctness gate)

`ChainEqualsInlineTest::chain_as_jobs_equals_chain_as_inline_for_tbirdhoops` proves the ConversionJob chain produces a BYTE-FOR-BYTE identical `ConversionResult` to the CaptureLive-style straight-line pipeline (modulo `conversion_id` + `draft_id`/`draft_url` which are per-run identifiers). Runs under phpunit sync queue so the full chain executes inline in the test process. $0 gate — no LLM, no network. Any drift = broken hand-off between ConversionJob and FinalizeConversionJob.

### Deferred (production hardening, not demo)

- Per-user auth / login flow (demo cut uses a shared `DEMO_TOKEN`).
- Per-account rate limits (per-IP throttle only for now).
- Real audit DB (cache-only 24h TTL is enough for the demo).
- SSE / websocket push (polling at 2s is fine for a demo).
- SCORE & LOG proper (BUILD.md step 5 — a separate slice; Finalize's `Log::info` is the placeholder).
- `$tries=3` on `GeneratePageJob` (still deferred per the async-slice known gap).
- Prompt caching for block-fill (still deferred).
- Persistence beyond 24h cache TTL — a 24h-old conversion becomes ungettable; fine for a demo, review-later conversions require re-running.

## Hosted demo — cost-guarded public trigger

Deployment shape for the shareable link. **The load-bearing property**: the trigger endpoint spends real Sonnet/Firecrawl money per call (~$3-6 per conversion), and its token is client-visible (embedded in landing HTML — not a real secret). Cost safety comes from THREE stacked guards, not token secrecy.

### The cost-guard model (Level 2 + Level 1 stacked)

`App\Services\Conversion\ConversionCostGuard`, wired into `ConversionController::trigger`:

1. **URL allowlist** (`DEMO_URL_ALLOWLIST` env, comma-separated) — the primary gate. Only listed URLs trigger conversions; anything else → 400. Empty env → no allowlist enforcement (dev/local). Normalized match: `lowercase + trim + trailing-slash-tolerant`.
2. **Daily budget cap** (`DEMO_DAILY_BUDGET_USD` env, default 30) — hard ceiling. Cache-backed UTC-day counter increments ~$4 per fresh conversion dispatch. When exceeded, POST returns 429 until UTC midnight. Bounds cost even if the allowlist is expanded.
3. **Concurrency lock** (`DEMO_CONCURRENT_CONVERSIONS` env, default 1) — at most N conversions in-flight. Second fresh POST returns 409 while another runs. **Dedupe hits BYPASS this check** (visitor B refreshing during A's conversion gets A's id, not a 409 — that would be terrible UX).

Guards fire in the controller in this order:
```
1. token middleware       (EnsureDemoToken)
2. URL validation         (Laravel validator)
3. allowlist              → 400 if not listed
4. daily budget           → 429 if cap exceeded
5. dedupe                 → return existing conversion_id (200) if hit
6. concurrency            → 409 if another in-flight (fresh dispatch only)
7. commit spend + dispatch
```

Release on terminal: `FinalizeConversionJob::handle` and `failed()` decrement the concurrency counter. `ConversionJob::failed()` does the same. Counter also has a 60-min TTL fallback in case release is missed — the demo self-heals.

### The SHARED-TOKEN-DEDUPE property — LOAD-BEARING for cost

`ConversionDedupeStore::registerOrGetExisting` keys on `sha1(token + normalized_url)`. Two DIFFERENT visitors sharing the same embedded demo token who POST the same URL hit THE SAME dedupe entry — Visitor B gets Visitor A's `conversion_id`. **One conversion, one $3-6 bill, everyone sees the same result.** This is what bounds hosted-demo cost to `(allowlist size × ~$3 × 1/day)` instead of `(visitors × ~$3 × 1/day)`. Proven by `CostGuardTest::shared_token_dedupe_across_visitors_returns_same_conversion_id` — this test is the guardrail; if it ever regresses, the demo is no longer cost-safe. Do not weaken.

For allowlisted URLs, the dedupe TTL extends to 24 hours (predictable-cost sites can share a conversion for a full day). Non-allowlisted URLs keep the 10-min default when the allowlist is unset (dev/local).

### Failure surfaces (what visitors see)

- `400` — URL not on allowlist. Frontend shows "This demo only converts a curated set..."
- `409` — another conversion running. Frontend shows "Another conversion is running right now. Please try again in a moment." Retry button.
- `429` — daily budget exhausted. Frontend shows "Daily demo budget reached. The demo resumes tomorrow."
- `401` — invalid `X-Demo-Token`. Not visible from the landing (token is embedded), but a direct API caller would see it.
- `503` — `DEMO_TOKEN` env unset. Deliberate refusal — prevents accidental prod exposure with no gate.

### The landing page

`app/Http/Controllers/Demo/LandingController.php` + `resources/views/landing.blade.php` — single Blade view. URL input pre-populated with `tbirdhoops.org` (the club, most-fully-rendered lead; leagues like cjfl show more placeholder blocks). Allowlist rendered as clickable chips. Convert button → vanilla-JS `fetch()` POST → transition to "watching" view → 2s polling of `/api/conversions/{id}/status` → stage label + N-of-M during block-fill + elapsed timer + per-stage tick-marks → redirect to `/preview/conv-<id>` on Complete/Partial → cleanly-shown `failure_reason` on Failed. No new React entry; reuses the existing preview bundle for the result page. Token + allowlist embedded server-side via `@json`.

### Tests (the gate before public exposure)

`CostGuardTest` — 11 tests / 42 assertions. Each closes one door:

- `guard_rejects_url_not_on_allowlist_with_400` — the primary gate.
- `guard_accepts_url_on_allowlist` — listed URL passes.
- `guard_normalizes_allowlist_urls_case_insensitive_and_trailing_slash_tolerant` — matcher is tolerant of common URL variations.
- `guard_blocks_when_daily_budget_would_exceed_returns_429` — the hard cap.
- `guard_blocks_second_fresh_dispatch_while_one_is_in_flight_returns_409` — concurrency lock holds.
- `guard_release_on_concurrency_reject_does_not_leave_stale_dedupe_entry` — a 409 rejection rolls back the dedupe registration so retries work cleanly.
- `allowlisted_dedupe_ttl_is_24h_not_10min` — time-traveled 11 min forward, dedupe still hits.
- **`shared_token_dedupe_across_visitors_returns_same_conversion_id`** — the load-bearing property.
- `dedupe_hit_bypasses_concurrency_check` — visitor B during A's in-flight for the SAME URL gets A's id, not a 409.
- `dispatch_commits_daily_spend_counter` — 400 cents per fresh dispatch; dedupe hits skip the increment.
- `no_allowlist_configured_all_urls_accepted` — backwards-compat for dev/local when `DEMO_URL_ALLOWLIST` is unset.

`ConversionEndpointTest` also gained a `Cache::flush()` in setUp + `concurrent_limit=100` to prevent cross-test concurrency-counter leaks under `Bus::fake` (no worker to release the lock).

### Deploy — see `DEPLOY.md`

Standalone Forge box, isolated from TeamLinkt prod. Three load-bearing items called out in DEPLOY.md that MUST NOT be forgotten:
1. Redis `maxmemory-policy=noeviction` (the eviction door the async chaos suite closed; not default on managed Redis).
2. Nginx basic-auth on `/horizon` (the UI leaks queue internals; `HorizonServiceProvider::gate()` alone isn't enough on a public box).
3. `npm run build` in the deploy script (`@vite()` blade directives 500 without a built bundle).

DEPLOY.md has a 9-step post-deploy smoke test that MUST pass before sharing the URL.

## Known gaps / next slices

- **Offline fixture-replay produces a different page set than live PLAN.** The body-aware `SePlatformContentDetector` (and any other body-dependent classification) can't run offline — the Firecrawl fixtures we have for offline tests carry nav structure but NOT bodies. So offline replay keeps pages live PLAN would park: tbirdhoops's offline planner emits 5 depth-0 nav items (Home, About Us, TBird News, Parents, **Unsubscribe**), but the live capture parks Unsubscribe (its body is pure SE-platform copy), so the committed BlockFillResult fixture only carries 7 pages and no `page-8659687`. **This is inherent to offline replay, not a bug.** Implication for tests: offline-replay tests MUST assert INVARIANTS (every Resolved nav keys into page_map; every nav whose page is missing from the map surfaces as Unresolved + a draft-landing ConversionFailure), NOT EXACT page sets (these specific N pages, status=Completed, this specific label is Unresolved). A fixture regen could shift the parked set without the lander being wrong; tests pinned to artifacts would false-positive. Second occurrence of the offline/online gap (first: 2e platform-page zero — tbirdhoops offline produces 0 platform pages while live PLAN might LLM-classify some pages into PlatformDynamic).

- **`ProductClient` draft-only guarantee is STRUCTURAL while the client is stubbed; needs a real-client integration gate.** The interface exposes no publish method, so the engine cannot publish — by construction. But the "real product endpoint is genuinely draft-only on the product side" property is NOT tested while `StubProductClient` is the only implementation. When the real HTTP client lands (graduation step), a required pre-prod gate is an integration test against a staging product that proves `createDraftSite` lands the site with publish=false — and that calling it twice for the same conversion doesn't accidentally promote the previous draft. Document the staging endpoint and the test in `ProductClient`'s real-client implementation when it lands.

- **SE-platform CONTENT — page-level: handled. Block-level: NOT yet handled — and now CONFIRMED PRESENT IN REAL PUCK OUTPUT.** PLAN's phase 1.5 (`SePlatformContentDetector` + the body-content park in `RootNavPlanner`) parks pages whose ENTIRE body is SE platform/tutorial content (validated on tbirdhoops: SE Parents page + Unsubscribe page). Detector requires all three of: ≥3 outbound links to SE-platform-tutorial hosts, ≥0.70 ratio, ≥2 distinct SE-platform vocabulary phrases. **Block-level scrubbing still missing**, and slice 2d confirms it in real assembled output: the tbirdhoops Home `PuckOutput` (saved at `tests/Fixtures/blockfill/tbirdhoops.json`) contains a `ButtonGroup` block whose three buttons are `"Stay Connected to Your Team with SportsEngine"` (href `#`), `"SportsEngine for Apple Users"` (iTunes URL), and `"SportsEngine for Android Users"` (Google Play URL). The block-fill prompt has no rule against rendering SE-promo body sections; the deterministic assembler validates the ButtonGroup as schema-conformant and emits it faithfully. **This is the known-gap manifesting, not a regression** — page-level park is correctly NOT firing on Home (the page is overwhelmingly org content), but the embedded SE-promo block survives end-to-end. The first preview rendered from the fixture WILL show a SE-promo widget on the Home page until the block-level scrub slice lands. Project read: catch upstream via an IR-pass-prompt addendum ("skip body sections whose primary content is SE onboarding/tutorial") plus a downstream deterministic `SePlatformBlockScrubber` (post-assemble) reusing `SePlatformContentDetector`'s patterns at block level — two cheap reinforcing catches, neither in the block-fill prompt itself (would mix faithful-rendering with selective-omission). Path forward when the slice lands: a single Sonnet re-run of block-fill regenerates the fixture; reviewers diff the JSON to confirm the SE-promo ButtonGroup is gone.

- **Block-fill's async CORRECTNESS is proven; live-Sonnet-under-async THROUGHPUT is not yet run.** The async-correctness slice closed the four silent-loss doors (worker SIGKILL, worker timeout, callback-never-fires, Redis eviction) and 14 async tests cover them: 4 baseline (dispatch persists state, no premature reconcile, reconcile idempotency, missing-state throws), 5 chaos (each door hits reconciliation visibly), 5 sweeper (finished-but-unreconciled, stuck-batch, in-flight-left-alone, idempotency, scope). See `tests/Feature/Async/`. Config fix: `config/horizon.php` supervisor-block-fill.timeout=600 (was 60 — would SIGKILL Sonnet calls). Sweeper wired via `routes/console.php` at 1-min cadence. `docker-compose.yml` pins `noeviction` for the Redis eviction door. What's NOT yet run: (a) Tier-3 tiny live-Sonnet run under real Horizon+Redis (3 pages, ~$0.30) proving the fake-vs-real interface holds and rate-limits under parallelism don't burst 429s; (b) Tier-4 captured-fixture replay through the async pipeline (pre-populate results, fake agent re-reads fixture) — cross-checks the full orchestration against real captured data with no Sonnet spend. Both deferred until user reviews the chaos suite. The `engine:async-smoke-test` command is the runner for (a); it currently times out cleanly under the local default queue (database, no worker) — expected, demonstrates the async deferral. Under `QUEUE_CONNECTION=sync` it reconciles inline in ~0.1s. Under docker-compose Redis + `php artisan horizon` it will exercise the real machinery.

- **`source_quote` substring verification is naive about formatting.** The tbirdhoops live dump used a literal substring check (`str_contains($body, $quote)`) to flag possible fabrications; it produced 19 false-positive ⚠️ warnings across 7 pages caused by THREE benign reformatting categories: (a) markdown escape stripping — body has `\*\*X\*\*` (Firecrawl-escaped), quote has `**X**`; (b) Unicode apostrophe normalization — body has `’` (U+2019), quote has `'` (U+0027); (c) structural-pointer whitespace collapse — multi-paragraph list in body collapsed to single-line summary in quote (every individual sub-line still a verbatim substring). None of the 19 was an actual fabrication. A stricter check — strip Firecrawl backslash escapes → NFKC-normalize → substring-match — would reduce false-positive warnings to true paraphrases. Belongs in the SCORE & LOG structural-confidence layer (downstream verification), NOT in block-fill itself (block-fill's job is faithful rendering; verification belongs as its own deterministic signal).

- **Contract "Pages you should not create" partial coverage:** four rules in the section; three enforced, two deliberately deferred.
  - ✅ Entity detail pages → `PlatformBlockType::isReservedRoutePage()` filters Team-type entries at PlatformBlockRenderer. Game/news-article/player types aren't in NavNodes we walk today.
  - ✅ Paginated duplicates → PLAN's deterministicAction parks any URL ending `/(page|p)/\d+/?$` with a `paginated_duplicate:` reason; DraftLanding surfaces info diagnostics.
  - ⚠️ **Login/cart/account/search/admin pages — SE-namespace only.** `isSePlatformLink` matches `/sn_signin`, `/sn_login`, `/se_login`, `/se_signin`, `/sportsengine`, `/dib_sessions`. Generic `/login`, `/cart`, `/account`, `/search`, `/admin` on non-SE sources would slip through. Not a gap for the current SE-only corpus (all four sites use `/sn_*` routes) but MUST land before a non-SE extractor. Simple addition: extend `isSePlatformLink` (or a new `isPlatformFeaturePath`) with a small allowlist of common platform paths — 5-10 lines.
  - ⚠️ **Near-empty pages — specific case only.** The PlatformTeam reserved-route skip removes the biggest concrete instance (19 near-empty per-team pages on cjfl). The GENERAL rule ("a page with nothing but a title") requires defining "substantive block count" and choosing a threshold — punted for now because the definition is a judgment call and false-drops would cost content. Reconsider after a live run surfaces the shape (e.g., "we shipped a page with only a heading and no body" pattern with a specific fix).

- **`GeneratePageJob` retry — RESOLVED (post-cjfl-live). `$tries = 3` with `[30, 60]` backoff + `failed()` hook writes the BlockFillFailure with attempt count after all attempts exhausted. Existing internal try/catch KEPT for Sonnet-reachable exceptions (write and return normally — reproducible errors don't benefit from retry). `failed()` is the safety net for the process-kill class (worker OOM / timeout SIGKILL / Redis eviction) that never reaches the catch. Original note below is HISTORICAL — kept for the rationale of why this was deferred through the demo range.** A 503 / network blip / Anthropic 429 on one page immediately fails that single page (visible, no stub — the job catches Throwable and writes a `BlockFillFailure` via the async slice's reconciliation surface), but the conversion goes Partial and there's no automatic recovery. Recovery = re-run the whole conversion. Safe (no fabrications, no silent loss — visible failure) but wasteful: at a 50-page site one transient blip partial-fails the conversion. Cost of the wasted work in Sonnet dollars is real; the correctness posture stands. Add per-page retry-on-transient-error (Horizon `$tries = 3` with backoff, classifying transient vs terminal — 429, 5xx, network as transient; malformed-response, schema-violation as terminal) BEFORE running block-fill at production volume. Fine for the current demo range (30-40 page sites). Note: `ReconcileBlockFillJob` DOES have `$tries = 3` — reconcile is cheap and idempotent and losing it strands the whole conversion; the per-page failure is bounded to one page so `$tries = 1` there is a defensible v1 tradeoff.

- **Live SE widgets scrape as static zero-state text and rebuild faithfully as stale static content** — not an assembler issue, a downstream platform-block-detection concern. Confirmed on the tbirdhoops Home fixture: the three upcoming-events Cards at the top of Home (Flight Tryouts / Thunderbird Assessments / Winter Basketball starts again in …) all have body `"0 Days 0 Hours 0 Minutes 0 Seconds"` because that's what Firecrawl captured — SE's live countdown JS hadn't run when the page was fetched. Block-fill writes the literal text from `body_markdown` (faithful — exactly its job). The deterministic assembler emits the Cards (schema-conformant — exactly its job). Net effect: the rebuilt site shows a frozen `0 Days 0 Hours …` countdown. Detection belongs at the PLAN / IR-pass level (a sibling concern to `platform_dynamic` classification): a `Card` whose body matches a `\d+\s+(Days?|Hours?|Minutes?|Seconds?)` countdown shape, or a roster/schedule rendered as zero-row scaffold, is a candidate to either (a) suppress and replace with a TeamLinkt platform block, or (b) flag for human review. Future work for the platform-block slice — out of scope for v1's site-rebuild-only cut, but the fixture is the diff target when it lands.

- **Prompt-caching the shared block-fill prefix is the biggest speed/cost win at volume, deferred for v1.** `AnthropicBlockFillAgent` ships UNCACHED. The shared prefix (schema + GlobalStyleBrief + rubric + faithfulness rules) is the same across every page in a conversion — a perfect fit for Anthropic's 5-min `cache_control: {type: 'ephemeral'}` ephemeral cache, which would seed on call #1 and read on calls 2..N. The blocker is in `vendor/laravel/ai/src/Gateway/Anthropic/Concerns/BuildsTextRequests.php:31` — the gateway sends `system` as a plain string (`$body['system'] = $instructions;`), and Anthropic prompt caching requires the structured-system-blocks array shape with `cache_control` markers. Options when we pick this up: (a) wedge a structured system through `providerOptions` (the gateway's `array_merge($body, $providerOptions)` would override) — but `Promptable::prompt()` doesn't expose per-call providerOptions today; (b) own a bespoke Anthropic Messages HTTP call for block-fill (block-fill is the highest-volume LLM call in the engine — the cache win is real) with `cache_control` baked in, behind the same `BlockFillAgent` interface so tests stay clean; (c) wait for `laravel/ai` to ship cache_control support. Pick (b) when volume justifies it.

- **Tool-call structured-output `blocks` stringification — investigated, hardened, accepted as rare for v1.** During the four-site calibration run, langdon's "Adult Langdon Softball" page (`page-8932018`) emitted `blocks: []` from block-fill despite carrying ~9KB of substantial body content (rulebook PDFs + RULE 4 player-substitution rules + "Did Ya Know?" narrative sections). The faithful-rebuild guarantee held — empty page → `AssemblyFailure` — but the model HAD actually rendered content; the engine couldn't read it. **Diagnosis (3 fresh Sonnet calls + raw-response capture):** on the laravel/ai tool-call structured-output path, Sonnet **deterministically (6/6 on this body, immune to prompt addendums)** emits the `blocks` array as a STRINGIFIED JSON array (a string containing JSON instead of a native array). The model also under-escapes literal embedded quotes inside the stringified content — Langdon-Softball had `("EP") rule.` in its rule text, which after one round of escaping yields `"EP"` literal inside the inner string, breaking inner JSON parse. The content INSIDE the broken string is fully rendered (18 well-formed blocks across the 3 runs, identical sequence) but unrecoverable without lossy regex extraction. **Hardening (committed in `26c677a`):** `AnthropicBlockFillAgent::filledPageFromDecoded` throws on non-array `blocks` (top-level) and non-array block items (per-index), converting silent-loss-by-transport-accident to a visible `BlockFillFailure` with a diagnostic exception message. `GeneratePageJob`'s existing Throwable handler catches the throw and surfaces the failure end-to-end. **Block-level loss below the page-level guarantee remains impossible.** **Correlation check (free, against captured fixtures):** across 52 pages spanning 3 calibrated sites, only Adult Langdon Softball has the combination of (paren-wrapped quoted abbreviation in body text + RULE/Sec/lettered legal-document formatting). 13 of 52 pages have at least one literal quote, but the OTHER 12 produced clean native arrays — the bug isn't "any literal quotes break it," it's the specific shape of legal-doc-formatted prose with embedded quoted abbreviations. The rate is "rare" within the calibration corpus BUT the failure mode IS the shape that rulebooks / codes of conduct / bylaws take — those pages exist across youth-sports sites even if uncommon. Re-rank if/when a calibrated site emits more than 1-2 such pages. **Native `output_config` IS the real fix — but is a substantial slice, not a flag.** Experiment summary (~$0.40 spent): (a) flipping `config/ai.php`'s `anthropic` provider config to `'anthropic_beta' => 'structured-outputs-2025-11-13,…'` would route through native — the substring `structured-outputs` is what `BuildsTextRequests::supportsNativeStructuredOutput()` checks; (b) native produces clean native arrays for `blocks` (verified — no stringify), but (c) **every Anthropic agent's current schema is rejected by native validation at request time** with `output_config.format.schema: For 'number' type, properties maximum, minimum are not supported` — `confidence: number().min(0).max(1)` on both block-fill and classifier; IR-pass doesn't have one. (d) After temp-stripping min/max from block-fill: the native call succeeds AND emits a native array of 18 blocks, but every block's `props` comes back as `[]` because the schema declares `props: object()` (open dict) which native validation treats as `additionalProperties: false` with no declared sub-properties → the model can't add any prop content. Sonnet's self_assessment confirmed this explicitly. **Scoped slice definition for Option 3 (the real native-path migration), to land if/when the failure rate justifies the cost:** (1) replace `confidence: number().min(0).max(1)` with `confidence: number()` on `AnthropicBlockFillAgent` + `AnthropicClassifierAgent`; enforce 0..1 textually in the prompt. (2) replace `props: object()` with a `oneOf` discriminated by `component_type` — each branch declares the typed props (`Heading.text` + `Heading.level enum`, `Text.body`, `ButtonGroup.buttons[].label/href/variant`, `Hero.heading/subheading/background_image/cta.{label,href}`, `Image.src/alt/caption`, `Card.title/body/image/href`, `Columns.columns[].width enum`). (3) **THE HARD PART — `Columns.columns[].children` is RECURSIVE** (each child is itself a block). Anthropic's native structured-outputs subset is JSON-schema-flavored but has limited `$ref` / recursive-schema support — verify by experiment before committing. May require flattening (no nested Columns) or capping recursion depth, which is a real expressivity loss. (4) Per-agent scoping decision — register a second provider name (e.g., `'anthropic_native'`) in `config/ai.php` with the beta header, pin block-fill to it via `#[ProviderAttribute('anthropic_native')]`; leave classifier + IR-pass on the existing provider until they're independently verified under native. `supportsNativeStructuredOutput()` reads provider config not per-call options, so per-agent scoping IS achievable but requires the second-provider hop. (5) Coordinate with the assembler's `BlockCoercer` — currently the coercer is the canonical schema-aware validation point at the assembler/Puck seam and tolerates arbitrary props at the LLM-output seam; tightening the LLM schema and the assembler schema together is a coordinated change. (6) Regenerate the calibration fixtures under the new path to confirm the langdon-softball case (and other rulebook-shape bodies as they're encountered) now produce native arrays with populated props. **Diagnostic tooling available for re-investigation:** `engine:replay-page-blockfill` (single-page block-fill replay, ~$0.10/call) and `engine:test-native-path` (INGEST disk + PLAN + IR-pass, no block-fill, used to exercise classifier + IR-pass independently). Both keep the cost of re-testing under $1 per pass.

## Guardrails (from BUILD.md — these come up repeatedly)

- DTOs + Larastan are the strictness net (PHP isn't TS) — make them strict.
- Each LLM stage = a `laravel/ai` **Agent** class with structured output matching the contract. Use the SDK's **testing fakes** for per-stage tests against fixtures.
- Orchestration (jobs, batch, retries) is a thin separate layer from clever logic.
- **No abstraction not asked for.** The user owns the prompts and the planner's keep/merge/drop logic; Claude owns plumbing, clients, queue/batch, the deterministic assembler/validator, and the throwaway demo.
- A test per stage against a real fixture. Keep raw scrape + manifest for fixture replay without re-scraping.
- Admin emails are PII — out of general logs; redact/scope retention.
- Idempotency on the trigger (dedupe key per account). Clear `failed | partial` state, never a silent hang.
- **Queue boundaries are process boundaries.** Anything that must be true on the worker — DI bindings, env vars, config, feature flags — MUST be set in the WORKER's environment (`php artisan horizon` / `queue:work` process), not just the dispatcher's. The container is per-process; a `$this->app->instance(Foo::class, ...)` call in the CLI binds it in the CLI's container, NOT in workers picking up jobs from Redis. The generalizable lesson from the async fixture-replay burn: the queue boundary silently swaps out the container, so anything the job depends on has to be resolvable from the WORKER's process on its own terms. Config-driven bindings via env vars work; ad-hoc `->instance()` calls do not.
