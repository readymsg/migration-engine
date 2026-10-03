# HANDOVER

Standalone Laravel 13 service that converts a youth-sports org's existing SportsEngine website into a TeamLinkt site. Reads a URL → extracts structure/content/brand → plans the IA → generates pages → lands as an unpublished draft → emits a contract-shaped JSON payload for TeamLinkt's import endpoint.

Companion docs:
- `BUILD.md` — authoritative spec.
- `CLAUDE.md` — denser code-level notes (per-slice reasoning, invariants, failure doors closed).
- `DEPLOY.md` — Forge deployment steps + post-deploy smoke test.

---

## 1. Pipeline stages (seven)

Entry: `POST /api/conversions` (controller `App\Http\Controllers\ConversionController`). Each conversion flows:

| # | Stage | Code | Shape in / out |
|---|---|---|---|
| 1 | **INGEST** | `App\Services\Ingest\SportNginExtractor` behind `App\Contracts\Extractor` | url → `Manifest` |
| 2 | **PLAN** | `App\Services\Plan\RootNavPlanner` | `Manifest` → `SitePlan` (keep/park/platform_dynamic/subsumed ledger + nav) |
| 3 | **IR pass** | `App\Services\Generate\IrPass` (brief-deriver + chunk-designer agents) | `SitePlan` + `Manifest` → `IrPassResult` (per-page `Ir`, `GlobalStyleBrief`) |
| 4 | **Block-fill** | `App\Services\Generate\BlockFill` + `GeneratePageJob` (per-page async) | `IrPassResult` + `Manifest` → `BlockFillResult` (per-page `FilledPage` schema-named blocks) |
| 5 | **Assembler** | `App\Services\Generate\Assembler` + `BlockCoercer` + `BlockValidator` | `BlockFillResult` → `AssemblyResult` (deterministic `FilledPage` → `PuckOutput`, validate→coerce→re-validate) |
| 6 | **Post-assembly** | `SePlatformBlockScrubber` → `PlatformBlockRenderer` → `DraftLanding` | `AssemblyResult` → `ConversionResult` (scrub SE promos, render platform-dynamic blocks, fold page_map + nav, call stub `ProductClient::createDraftSite`) |
| 7 | **Emission** | `App\Services\ContractEmitter\ContractPayloadEmitter` + `EnvelopeJson` + `ContractPayloadValidator` (opis/json-schema) | `ConversionResult` → `EmitResult { Envelope JSON string, diagnostics sidecar }` |

Each stage writes its result DTO to a per-conversion cache-backed store under `App\Services\Conversion\*`. Finalize reads block-fill reconciled result + context (Manifest+SitePlan) and runs stages 5-7 inline.

The faithful-rebuild guarantee chains across stages: every page is accounted for (page, upstream-chained failure, or synthetic "silently absent" failure) at every reconciliation boundary. Never a stub.

---

## 2. Required env vars

See `.env.example` for the authoritative list. The engine-specific ones:

| Var | Purpose | Default |
|---|---|---|
| `ANTHROPIC_API_KEY` | Haiku 4.5 (classify), Sonnet 4.6 (block-fill), Opus 4.8 (IR) | required |
| `FIRECRAWL_API_KEY` | Content scraping during INGEST | required |
| `SCRAPES_DISK` | Filesystem disk ContentLoader reads scrapes from | `local` |
| `SVG_RASTERIZER_PATH` | Path to `rsvg-convert` for SVG → PNG asset rasterization | `/opt/homebrew/bin/rsvg-convert` (macOS) / `/usr/bin/rsvg-convert` (Ubuntu) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_BUCKET` / `AWS_DEFAULT_REGION` | S3 asset uploads | required (prod); optional (local — `FakePublicAssetHost` is default) |
| `DEMO_TOKEN` | Shared `X-Demo-Token` for the hosted-demo gate. CLIENT-VISIBLE (embedded in landing HTML). | required when demo endpoint is public |
| `DEMO_URL_ALLOWLIST` | Comma-separated URL allowlist (Level 2 cost gate). Empty = all URLs allowed (dev). | empty |
| `DEMO_DAILY_BUDGET_USD` | Hard daily cap (Level 1 cost gate). | `30` |
| `DEMO_CONCURRENT_CONVERSIONS` | In-flight concurrency cap. Dedupe hits bypass. | `1` |
| `BLOCKFILL_FIXTURE_REPLAY` | `1` swaps in a fixture-replaying block-fill stub. MUST BE UNSET in prod. | unset |
| `QUEUE_CONNECTION` | `redis` for prod (Horizon). `sync` runs the chain inline (tests). `database` for simple dev. | `database` |
| `CACHE_STORE` | `redis` in prod. `array` in tests. | `database` |

---

## 3. Queue / Horizon requirements

- Redis is required for prod. The async-correctness slice closed four silent-loss doors (worker SIGKILL, worker timeout, callback-never-fires, Redis eviction) by requiring Redis + Horizon + the sweeper command.
- **`maxmemory-policy=noeviction` on Redis is LOAD-BEARING.** Other eviction policies silently drop queued jobs under memory pressure, so `pending_jobs` never decrements, batch never completes, callback never fires. `docker-compose.yml` pins this for local dev. Prod Redis MUST match.
- Horizon supervisor `supervisor-block-fill.timeout = 600` (not the default 60s) so a real Sonnet call isn't SIGKILLed mid-flight. See `config/horizon.php`.
- Scheduled sweeper runs every minute: `php artisan engine:reconcile-stuck-conversions`. Duties: (a) finished-but-unreconciled batches, (b) stuck batches > 45 min, (c) reconciled-result-present-but-finalize-never-fired. See `routes/console.php`.
- `GeneratePageJob` is `$tries=3` with `[30, 60]`s backoff + `failed()` hook that writes a `BlockFillFailure` with attempt count. `ReconcileBlockFillJob` is `$tries=3`. `FinalizeConversionJob` is `$tries=3`. `ConversionJob` is `$tries=1` (full re-runs are a user action).
- Start locally: `docker compose up -d` then `php artisan horizon`.

---

## 4. Hosted-demo cost guards

Three stacked, independently sufficient defenses (`App\Services\Conversion\ConversionCostGuard`):

1. **URL allowlist** (`DEMO_URL_ALLOWLIST`) — primary gate. Only listed URLs accepted; else 400.
2. **Daily budget cap** (`DEMO_DAILY_BUDGET_USD`, default $30) — hard ceiling. ~$4 per fresh dispatch; 429 until UTC midnight when exceeded.
3. **Concurrency lock** (`DEMO_CONCURRENT_CONVERSIONS`, default 1) — second fresh dispatch while one runs → 409. **Dedupe hits BYPASS** so visitor B refreshing during A's conversion gets A's id.

**Load-bearing property**: the dedupe key is `sha1(token + normalized_url)`. Two visitors sharing the embedded demo token hitting the same URL get the SAME conversion_id — one bill, one result for everyone. Tested by `CostGuardTest::shared_token_dedupe_across_visitors_returns_same_conversion_id`. Do not weaken.

Allowlisted URLs get a 24h dedupe TTL (predictable sites share one conversion all day); non-allowlisted (dev) keeps 10-min default.

---

## 5. Test sites + current baselines

Four live-captured fixtures. Durable `BlockFillResult` captures live at `tests/Fixtures/blockfill/{site}.json`.

| Site | Shape | Captured status | Notes |
|---|---|---|---|
| **tbirdhoops.org** | Youth basketball club, 7 pages | Complete | Canonical replay fixture. Captured through full assemble. Home page carries the SE-promo ButtonGroup + stale-countdown Cards that the SE-platform-block scrubber removes post-assembly. |
| **tenacityvolleyball** | Youth volleyball club, 20 pages | 0 content-page scrubs, 2 platform PuckOutputs (Teams, Calendar). Live baseline run under chunked IR. |
| **langdondiamonds.ca** | Youth baseball association, 18 pages | 0 content-page scrubs. Coaches page carries 7 legitimate `help.sportsengine.com` links — proves the SE-platform-block scrubber's narrower pattern doesn't false-positive on help-article links. |
| **cjfl.ca** | Canadian Junior Football League, 34-page site | Complete on IR + block-fill under chunked path (was Failed/abort pre-chunking). Partial only from a pre-existing draft-landing Teams-nav gap unrelated to IR. |

### Fixture emission baseline

Measured via `php artisan engine:emit-contract-fixture` against the committed-shape `storage/app/public/preview/{site}.json` ConversionResult fixtures. The gitignored source fixtures are not guaranteed to reflect current live-pipeline output on their own — the cjfl row has just been refreshed, the other two sources date from earlier captures.

| Site | Pages | Blocks | Assets | Schema errors | Block-rule errors | Source |
|---|---|---|---|---|---|---|
| tbirdhoops | 7 | 87 | 107 | 0 | 0 | pre-handover fixture |
| cjfl | 41 | 133 | 81 | 0 | 0 | **live refresh 2026-10-02, ≤$4 estimated spend** |
| langdondiamonds | 18 | 85 | 50 | 0 | 0 | pre-handover fixture |

All three emitted JSON files independently validate against `resources/site-import-schema/site-import-schema.json` via opis/json-schema.

### Live-run baseline

Produced by `php artisan engine:capture-live` running the full `INGEST → PLAN → IR → BLOCK-FILL → ASSEMBLE → SCRUB → PLATFORM-RENDER → DRAFT-LAND` pipeline under `QUEUE_CONNECTION=sync` (inline, no Horizon required). Wall-clock ~18 min for cjfl.

| Site | Pages | Blocks | Failures | Notes |
|---|---|---|---|---|
| tbirdhoops | not recorded | not recorded | not recorded | ~93% migratable coverage. Pages/blocks/failures were not captured in a durable artifact from the live run — only the coverage percentage is on record. The 7/87 numbers quoted elsewhere are the fixture-emission figures above, not a live measurement. |
| cjfl (2026-10-02 refresh) | 41 | 133 | 0 | Live run via `engine:capture-live https://www.cjfl.org`. Reproduces the "41 pages / 0 failures" prior baseline exactly; **the "143 blocks" figure from the earlier handover note did not reproduce — the current emission is 133 blocks.** The difference is unexplained from this run alone (deterministic assembler on the new BlockFillResult produced 133; the 143 came from a different capture whose BlockFillResult is not available for diff). Treat 133 as the current source of truth; 143 is the historical note. |

**To refresh the cjfl fixture** so emission can re-baseline: delete `storage/app/public/preview/cjfl.json` and run the live pipeline (`POST /api/conversions` with the cjfl URL under `QUEUE_CONNECTION=redis` + Horizon). Costs ~1 Opus call for the brief + ~3 Opus calls for IR chunks + ~34 Sonnet calls for block-fill ≈ $4-6.

---

## 6. Known open items

- **Prompt caching OFF on block-fill.** `AnthropicBlockFillAgent` ships uncached. The shared prefix (schema + GlobalStyleBrief + rubric) is a perfect fit for Anthropic's 5-min ephemeral cache (`cache_control: {type: 'ephemeral'}`). Blocker is in `vendor/laravel/ai`'s Anthropic gateway (`BuildsTextRequests.php:31` sends `system` as a plain string, not the structured-blocks array shape with `cache_control` markers). Options: (a) wedge through `providerOptions` once laravel/ai exposes them per-call; (b) own a bespoke Anthropic Messages HTTP client for block-fill behind the same `BlockFillAgent` interface; (c) wait for laravel/ai cache_control support. Pick (b) when volume justifies the biggest single speed/cost win in the engine.

- **`GeneratePageJob` $tries=3 — RESOLVED.** `app/Jobs/GeneratePageJob.php` is `$tries=3` with `[30, 60]`s backoff + `failed()` hook that writes a `BlockFillFailure` with attempt count. CLAUDE.md's known-gaps section matches. No outstanding work.

- **`PublicAssetHost` has only `FakePublicAssetHost`.** `app/Services/Assets/FakePublicAssetHost` is the only implementation registered in `AppServiceProvider`. A real `SpacesPublicAssetHost` (DigitalOcean Spaces / S3 + CloudFront) is referenced in docblocks but not built. Interface is at `app/Contracts/PublicAssetHost.php` — the seam exists; the production implementation does not. **BLOCKER for shipping emitted contract payloads to a real TeamLinkt endpoint**: emitted `assets[].url` fields will reference the fake host's deterministic sha256-keyed URLs. Build before go-live.

- **Diagnostics should be written as a sidecar file next to each payload — DONE for `EmitContractFixture` only.** `app/Console/Commands/EmitContractFixture.php` writes `{name}-contract.json` + `{name}-contract.diagnostics.json`. The REAL `ConversionController` → `FinalizeConversionJob` pipeline writes to `DiagnosticsSidecarStore` (cache-backed) and does NOT currently write a sidecar file next to the on-disk envelope. When `ProductClient` becomes an HTTP client (not a stub) and we persist the shipped payload, the sidecar should land as `{conversion_id}-contract.json` + `{conversion_id}-contract.diagnostics.json` on the same disk, same pattern as the fixture emitter. Keeps every shipped payload traceable to the diagnostics that produced it + the schema sha256 it was validated against.

- **Offline fixture-replay produces a different page set than live PLAN.** Body-aware classification (`SePlatformContentDetector`) can't run offline because Firecrawl fixtures carry nav but not bodies. Offline-replay tests must assert INVARIANTS, not exact page sets. See CLAUDE.md "Known gaps / next slices" for the full write-up.

- **SE-platform CONTENT page-level: handled. Block-level: handled via `SePlatformBlockScrubber`.** Both close the SE-content leak surface pre-emission.

- **`ProductClient` draft-only guarantee is STRUCTURAL (no publish method on interface) while stub-only.** When the real HTTP client lands, a required pre-prod gate is a staging integration test proving `createDraftSite` genuinely lands with publish=false.

- **SponsorGrid `Card.image` URLs not routed through `AssetLedger::tokenFor`.** `PuckToContractMapper::emitSponsors()` emits a `Sponsors` widget with only an `id` prop; scraped sponsor logos (e.g. langdon's `Remuda_Building_Supplies_Logo.svg`) are discarded. Pinning test: `tests/Feature/Assets/SponsorGridSvgNotTokenisedTest` (markTestSkipped-with-reason). Close by extending `emitSponsors()` to emit a `logos: string[]` prop of tokens, preserving scraped data as opt-in.

- **Tool-call structured-output `blocks` stringification.** On rare rulebook-shape pages (`("EP") rule.` ↔ embedded quoted abbreviations + legal-doc formatting), Sonnet deterministically emits `blocks` as a stringified JSON array. Correctness is preserved — the hardened `AnthropicBlockFillAgent::filledPageFromDecoded` throws and the job surfaces a visible `BlockFillFailure`. Real fix is Option 3 (native structured-outputs via Anthropic beta header) — substantial slice; schema changes required on `confidence` and `props`. Fully scoped in CLAUDE.md.

- **Scraped third-party API keys in captured fixtures — mitigated.** The Google Maps Static API key previously embedded in `tests/Fixtures/blockfill/tbirdhoops.json` from scraped SE map embeds has been replaced with `REDACTED_GMAPS_KEY` in commit `1249b5e`. Future live captures of SE sites with embedded Google Maps widgets will re-introduce the pattern; `engine:capture-live` has no redaction pass. Worth adding a post-capture scrubber if more SE sites get captured.

- **`engine:capture-live` unconditionally overwrites BOTH the gitignored preview `ConversionResult` and the git-TRACKED block-fill fixture.** Default write paths: `storage/app/public/preview/<slug>.json` AND `tests/Fixtures/blockfill/<slug>.json`. The second is a committed artifact that downstream fixture-replay tests (`DraftLandingFixtureReplayTest`, `AssemblerFixtureReplayTest`, `AssetUrlRewriterTest`, `GalleryFillerTest`, `SePlatformBlockScrubberTest::cjfl_zero_scrubs_*`, `PuckToContractMapperTest::cjfl_*`, etc.) pin against. **To sandbox a run that shouldn't touch committed state, pass `--blockfill-out=/tmp/<slug>-bf.json --preview-out=/tmp/<slug>-pv.json`.** The command has no `--dry-run` flag today. Learned the hard way during the 2026-10-02 cjfl refresh — the overwrite is pre-gate.

- **CLI live path has NO spend meter, NO token logging, and does NOT enforce `DEMO_DAILY_BUDGET_USD`.** `ConversionCostGuard` is referenced only by `app/Http/Controllers/Conversion/ConversionController.php:39`, `app/Http/Controllers/Demo/LandingController.php:28`, and `app/Jobs/FinalizeConversionJob.php:16` (release-on-terminal only). `app/Console/Commands/CaptureLive.php` has zero references to the guard. `laravel/ai`'s client does not log Anthropic token counts to any file by default. A single `engine:capture-live` invocation can therefore spend arbitrarily (bounded only by Anthropic's org-level rate limits and your approval). **This must be fixed before any batch or mass-migration run.** Minimum viable fix: pre-call cost estimate + per-call token usage logging to a sidecar JSONL at `storage/logs/llm-usage/<conversion_id>.jsonl` so the artisan path has an auditable spend trail.

- **A sync live run of a ~40-page site takes ~18 min wall clock.** cjfl (34 content pages, 7 platform pages) under `QUEUE_CONNECTION=sync` + always-chunked IR + sequential Sonnet block-fill: measured 1,092s on 2026-10-02 against a reasonably warm Firecrawl cache. The parallel Horizon path is faster (10-way concurrency on block-fill) but requires a running Redis + Horizon supervisor. Plan accordingly for live-dev iteration vs. production conversion.

---

## 7. Build + test commands

- `composer dev` — serve + queue:listen + pail + vite (combined dev loop).
- `composer test` — clears config then `php artisan test`.
- Single test file / filter: `php artisan test tests/Feature/Foo.php` or `php artisan test --filter=SomeTest`.
- `vendor/bin/phpstan analyse --memory-limit=1G` — Larastan L8.
- `vendor/bin/pint` — code style.
- `php artisan horizon` — queue supervisor (Redis-backed in prod).
- `php artisan engine:emit-contract-fixture --source-fixture=tbirdhoops --org-type=club` — emit on-disk envelope + sidecar from a captured `ConversionResult`.
- `php artisan engine:reconcile-stuck-conversions` — the sweeper (also runs on schedule every minute).
