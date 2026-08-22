# TeamLinkt Website Builder — Site Import Contract

**Received:** 2026-08-21 from TeamLinkt engineering.
**Schema bundle version:** `1`.
**Full contract:** received in-conversation on 2026-08-21; the load-bearing summary
is below. The complete text (Parts I–VII + Appendix A) is the authoritative source
and is expected to be committed alongside `ai-website-builder-schema.json` once
that file arrives from engineering.

---

## What this document is

The complete specification for the payload produced by this engine and consumed by
TeamLinkt's Website Builder ingest. Two producers of this format exist —
this engine's scrape → normalize → translate pipeline, and TeamLinkt's own internal
legacy V1 migration job — feeding the same ingest. That is why the format is
versioned and treated as a contract, not a convention.

## Envelope shape

```json
{
  "schemaVersion": 1,
  "source": {
    "url": "...",
    "scrapedAt": "ISO-8601 UTC",
    "pagesDiscovered": <int>,
    "pagesMapped": <int>
  },
  "site": { <SiteSettings, may be {}> },
  "pages": [ <Page>, ... ],
  "assets": [ <Asset>, ... ],
  "diagnostics": [ <Diagnostic>, ... ]
}
```

All six top-level keys required; four (`site`, `pages`, `assets`, `diagnostics`) may
be empty, though `pages` must contain at least one page with `slug: ""`.

## The 11 hard rules (Contract Part I)

1. **Never invent a block type.** Only the 45 documented exist. Unknown `type`
   renders a grey "No configuration for X" placeholder.
2. **Six of the 45 must never be emitted:** `IntakeForm` + `NavMenu` + `SiteNotice`
   + `FooterColumns` + `FooterLogo` + `FooterSocial`. That leaves **39 emittable.**
3. **Never invent a prop name.** Props are a storage contract; a typo'd prop is
   silently stored forever and the block falls back to its default.
4. **Never author `resolved*` props or `formUuid`.** These are filled by the server
   at render or save time.
5. **Every block needs a unique `props.id`** within its page.
6. **Emit `siteSettings.zones` as empty.** Chrome comes from the template.
7. **Never emit a raw scraped image URL into a block prop.** Use `tl-asset:<ref>`
   and declare in `assets[]`.
8. **The homepage has an empty slug.**
9. **Slugs must be unique per site,** case-insensitively.
10. **Do not use `view` as a top-level slug** — reserved route prefix.
11. **Respect org-type gating.** A `club` must not receive a `Standings` block.

## Forbidden `site` keys

- `zones` (chrome comes from the template)
- `templateId`, `templateChosen`, `templateSettings` (org picks)
- `theme` (Radix overrides are template-owned)
- `showTeamRosters` (privacy setting, never machine-decided)

## Rich text — five props total accept HTML

Everything else is plain text. HTML sent anywhere else is rendered literally
(tags visible) or silently dropped on first admin edit (TipTap parses and
discards unrecognised nodes — a silent-loss trap).

The five permitted:

| Block | Prop |
|---|---|
| `Text` | `body` |
| `TwoColumn` | `leftBody`, `rightBody` |
| `Accordion` | `items[].body` |
| `FAQ` | `items[].body` |

Supported TipTap subset: `p, h1-h6, strong, em, u, s, ul, ol, li, blockquote,
a[href], code, pre, hr, br` + `style="text-align: …"` on block elements.

Forbidden everywhere: `<table>`, `<div>`, `<span>`, `<img>`, `<iframe>`, `<form>`,
`<script>`, `class=`, `id=`, event handlers, inline styles other than text-align.

## Assets — inversion rule

- Emit `tl-asset:<ref>` tokens in props; `<ref>` is `[a-z0-9-]{1,64}`, unique
  within the payload.
- Declare each in `assets[]` with the ORIGINAL sourceUrl (the third-party CDN
  URL, NOT our S3 key).
- TeamLinkt fetches server-side, stores against the org, rewrites tokens.
- Accepted mimeTypes: `image/jpeg`, `image/png`, `image/webp`, `image/gif`,
  `application/pdf`. **SVG rejected** — stored-XSS vector; rasterise to PNG
  ≥ 512 px on the long edge before declaring.

## Org types (gate block legality)

| Value | Restrictions |
|---|---|
| `club` | + `EventMarquee`; NO `Standings/Scores/Schedule/ScoresSchedule/Statistics/Suspensions/TeamRoster/Teams` |
| `association` | all blocks |
| `league` | all blocks |
| `high_school` | all blocks |
| `civic` | NO `Standings/Scores/Schedule/ScoresSchedule/Statistics/Suspensions/TeamRoster/Teams/EventMarquee` |
| `multi_location` | same as civic |

## The 39 emittable blocks, grouped

- **Layout:** `CTABanner`, `Grid`, `Hero`, `Section`, `Spacer`, `Table`, `Tabs`, `TwoColumn`
- **Content:** `Button`, `FAQ`, `FeatureGrid`, `FileDownload`, `Gallery`, `Image`, `StatsCounter`, `TeamMembers`, `Testimonials`, `Text`, `Video`
- **Interactive:** `Accordion`, `ContactForm`, `PhotosRotator`, `Slider`
- **TeamLinkt Widgets** (place, don't fill): `EventMarquee`, `Executives`, `Fundraisers`, `Locations`, `NewsList`, `NewsRotator`, `Schedule`, `Scores`, `ScoresSchedule`, `Sponsors`, `Standings`, `Statistics`, `SubOrganizations`, `Suspensions`, `TeamRoster`, `Teams`

## Change management (Contract Part VI)

- Additive-only, going forward. Payload valid at N stays valid at N+1.
- No prop renames, no prop deletions, no enum-value removals — those orphan
  stored content (they've been burned by this before; 4,600+ orphaned prop
  occurrences documented in Part VI).
- Version handoff during PoC is manual: TeamLinkt regenerates the bundle +
  re-renders the doc + sends the new document. Our obligation is to echo
  `schemaVersion` on every payload.

## Where the details live

The block catalogue (Part III of the contract) is generated from
`core/storage/app/ai-website-builder-schema.json` on the TeamLinkt side.
Prop-level detail — types, enums, ranges, defaults, per-block do-not-author
lists, org-type gating — lives there. When we receive that JSON it becomes the
authoritative source for `ContractSchemaValidator` (Slice 2); until then we
work from the block-by-block prop tables in the full contract text.
