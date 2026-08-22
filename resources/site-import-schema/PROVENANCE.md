# Schema catalogue provenance

**Status:** live import (Slice 14). `site-import-schema.json` is engineering's
own file — JSON Schema draft 2020-12, generated from
`core/storage/app/ai-website-builder-schema.json`, 45 blocks defined
across 39 emittable + 6 never-emit. It landed 2026-08-22.

`ContractSchema::load()` consumes this file directly. The hand-encoded
19-block `blocks.json` is retired.

## Inference diff — what we hand-encoded vs. what shipped

Diffed programmatically at import time.

### What we got RIGHT (verified — no divergence)

- Every enum value we claimed:
  - `Text.as`: `["p","h1","h2","h3"]` ✓
  - `Hero.layout`: `["overlay","split","text","image"]` ✓
  - `Gallery.columns`: `[2,3,4,5]` (numbers) ✓
  - `Gallery.aspectRatio`: `["1/1","4/3","3/2","16/9","auto"]` (auto included) ✓
  - `Gallery.lightbox` / `showCaptions`: `[true,false]` (booleans) ✓
  - `Grid.columns`: `["2","3","4"]` (strings — deliberately different from `Gallery.columns` numbers) ✓
  - `TeamMembers.columns`: `[2,3,4]` (numbers) ✓
  - `TeamMembers.showImage`: `[true,false]` ✓
  - `Button.size`: `["1","2","3","4"]` (strings) ✓
  - `Button.variant`: `["solid","soft","outline","ghost"]` ✓
  - `Locations.columns`: `[1,2,3]` (numbers) ✓
  - `Locations.items[].environment`: `[null,"indoor","outdoor"]` (null in enum) ✓
  - `Scores.mode` / `Schedule.mode`: `["recent","upcoming","all"]` ✓
  - `Schedule.dateGrouping`: `["none","day","week"]` ✓
- Nested-object shapes:
  - `Hero.primaryButton`/`secondaryButton`: `{label, href}` ✓
  - `TeamMembers.items[]`: `{photo, name, role, email, bio}` ✓
  - `Locations.items[]`: `{name, address, lat, lng, environment, surfaceType, capacity, description}` ✓
  - `Gallery.images[]`: `{src, alt, caption}` ✓
- Prop-name choices where we replaced an earlier draft's names:
  - `Hero.imageUrl` (not `background_image`) ✓
  - `Gallery.images` (not `items`) ✓
- Every numeric range (min/max) we encoded ✓
- Every `defaults` block we encoded (spot-checked Text + Hero) ✓
- `required: ["id"]` at every block ✓
- `additionalProperties: false` at every block-props map ✓ — Contract Part VI rule 2 pinned.
- Chrome / never-emit set: 6 blocks (`FooterColumns`, `FooterLogo`, `FooterSocial`, `IntakeForm`, `NavMenu`, `SiteNotice`) ✓

### What we got WRONG (nothing invented; everything is omission)

**Zero props extra** — nothing we hand-encoded was invented. Every prop name we wrote exists in the real schema.

**Omitted props on blocks we DID know about:**

| Block | Props we missed | Impact |
|---|---|---|
| `Hero` | `visibility` (stored-only, `{showPreheading, showHeading, showSubheading}`) | We could not have emitted it either way; would have hit the stored-only escape hatch |
| `Sponsors` | `resolvedSponsors` (server-owned) | We had `resolvedSponsors` in `doNotAuthor` — Slice 15 lets us read this from `serverOwnedProps` directly |
| `NewsList` | `resolvedItems` (server-owned) | Same as above |
| `Standings` | 6 props: `preheadingColor`, `headingColor`, `subheadingColor`, `maxWidth`, `horizontalPadding`, `verticalPadding` | We couldn't emit them; if the mapper wanted to, it would've been flagged `unknown_prop_key` |
| `Scores` | 18 props inc. `division`, `showLocation`, `showLogos`, `dateGrouping`, `showGamesOnly`, `showPagination`, `moreLinkHref/Label`, `accentColor`, styling six | Same — undercount narrowed what we could emit; nothing wrong got past |
| `Schedule` | Same 18 props as Scores | Same |

**Blocks we DID NOT KNOW ABOUT** (26 emittable, 0 covered):

`Accordion`, `CTABanner`, `ContactForm`, `EventMarquee`, `Executives`,
`FAQ`, `FeatureGrid`, `FileDownload`, `Fundraisers`, `PhotosRotator`,
`ScoresSchedule`, `Section`, `Slider`, `Spacer`, `Statistics`,
`StatsCounter`, `SubOrganizations`, `Suspensions`, `Table`, `Tabs`,
`TeamRoster`, `Teams`, `Testimonials`, `TwoColumn`, `Video`.

Impact: our block-fill mapper never emitted any of these, but the
validator would have rejected them as `unknown_block_type` if the LLM
had. The new schema surface makes them all available.

**Defaults deltas (values we shipped vs the file):**

Only one — `Locations.items` — where we shipped `[]` and the real
schema declares a 3-item sample default. That's not a bug: the schema's
`defaults` blob is a **sample**, not an authoritative default, per its
own description on similar props ("their sample default"). Our empty
array is the correct emit shape.

### Adapter shape

`ContractSchema` internally normalizes each block prop to one of these
shapes so the existing validator's switch doesn't have to know about
JSON Schema conventions:

- `{type: 'string'}` / `{type: 'string', nullable: true}`
- `{type: 'number', minimum?, maximum?}`
- `{type: 'boolean'}`
- `{type: 'enum', enum: [...]}` — JSON types preserved
- `{type: 'richtext'}` — from `x-teamlinkt.vocabularies.richtext.props`
- `{type: 'opaque'}` — from `x-teamlinkt.vocabularies.opaqueProps`
- `{type: 'slot'}` — props with `x-teamlinkt.slot: true`
- `{type: 'string_or_number'}` — `knownDiscrepancies` (Statistics.items[].value etc.)
- `{type: 'array', items: <normalized>}`
- `{type: 'object', keys: {name → normalized}}`

Adapter also exposes x-teamlinkt vocabularies for Slice 15 to consume:

- `orgTypeGating(): array<string, string[]>` from `x-teamlinkt.orgTypeGating.restrictedBlocks`
- `neverEmitBlocks(): array<string, string>` from `x-teamlinkt.neverEmitBlocks`
- `serverOwnedProps() / storedOnlyProps() / opaqueProps() / assetBearingProps() / richtextProps()`
- `slotPaths(): array<string, string[]>` from `x-teamlinkt.vocabularies.slotPaths`
- `reservedTopLevelSlugs(): array<string>`
- `stockMediaDefaults(): array<string, string[]>` (Slice 16a)
- `knownDiscrepancies(): array<string, string[]>` (Slice 16b)

### Post-swap TODO

None from Slice 14. Slice 15 consumes the vocabularies. Slice 16
adds the stock-media validator rule and knownDiscrepancies tolerance
(already partially covered — see `ContractSchema::normalizeProp` for
the `string_or_number` mapping).
