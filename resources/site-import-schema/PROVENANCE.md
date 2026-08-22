# Schema catalogue provenance

**Status:** hand-encoded fallback. Awaiting `ai-website-builder-schema.json` from TeamLinkt engineering.

`blocks.json` is a hand-encoding of the 13 blocks needed through Slice 15 —
content: `Text`, `Hero`, `Image`, `Gallery`, `Button`, `TeamMembers`; layout:
`Grid`; TeamLinkt Widgets: `Sponsors`, `NewsList`, `Locations`, `Standings`,
`Scores`, `Schedule` — plus the six blocks that must be REFUSED (`IntakeForm`,
`NavMenu`, `SiteNotice`, `FooterColumns`, `FooterLogo`, `FooterSocial`).

When engineering delivers `ai-website-builder-schema.json`, `ContractSchema::load()` should
switch to reading it directly. This file becomes a diff target — anything below that
turned out wrong is a validator hole worth fixing before the swap.

## Confidence key

- **VERIFIED** — copy-paste-exact from the contract markdown, no interpretation.
- **INFERRED** — I extrapolated from the contract text; the JSON likely uses a
  different shape or key name.
- **FILE-SHAPE** — my guess at how the generated JSON structures things
  (e.g. `props.properties`, `defaults`, `doNotAuthor`). Contract Part VI's
  self-check rule 2 mentions `props.properties` and `defaults` specifically:
  > "Every prop key exists in that block's `props.properties`, **or** in its `defaults`
  > (remember the three stored-but-not-editable props)."
  So those two keys are almost certainly right. Everything else in the file shape is a guess.

## What is VERIFIED

| Field | Notes |
|---|---|
| block descriptions | quoted verbatim from Part III per block |
| block `zones` (page-content vs preHeader/header/footer) | Contract Part III explicit |
| chrome-block set (6 refusals) | Contract Part II "Blocks you must never emit" |
| do-not-author (🚫) prop lists | Contract Part II "Do not author these" + Part III markers |
| `Text.as` enum: `"p" | "h1" | "h2" | "h3"` | Part III `Text` prop table |
| `Text.body` = richtext | Contract Part II "Rich text" — one of the five permitted |
| `Text` default `body: "Paragraph text."`, `as: "p"`, `color: "var(--gray-12)"`, `fontSize: 16` | Part III complete-defaults blob |
| `Hero.layout` enum: `"overlay" | "split" | "text" | "image"` | Part III `Hero` prop table |
| `Hero.imageUrl` = string (was `background_image` in our schema; contract's is `imageUrl`) | Part III |
| `Hero.primaryButton` / `secondaryButton` shape `{label, href}` | Part III sub-table |
| `Hero.height` range 200–900, default 480 | Part III |
| `Image.aspectRatio` enum incl. `"21/9"` | Part III |
| `Gallery.images[]` shape `{src, alt, caption}` (was `items[]` in our schema; contract's is `images[]`) | Part III |
| `Gallery.columns` enum values `[2, 3, 4, 5]` are NUMBERS not strings | Part III |
| `Gallery.aspectRatio` enum incl. `"auto"` (Image doesn't; slight divergence) | Part III |
| `Gallery.lightbox` / `showCaptions` enum values `[true, false]` are BOOLEANS | Part III |
| `Button.size` enum values `["1", "2", "3", "4"]` are STRINGS | Part III |
| `Button.variant` enum: `"solid" | "soft" | "outline" | "ghost"` | Part III |
| `TeamMembers.items[]` shape `{photo, name, role, email, bio}` all strings | Part III `TeamMembers` sub-table |
| `TeamMembers.columns` enum `[2, 3, 4]` are NUMBERS not strings | Part III |
| `TeamMembers.showImage` enum `[true, false]` are BOOLEANS | Part III |
| `TeamMembers` defaults (heading="Meet the team", columns=3, showImage=true, etc.) | Part III complete-defaults blob |
| `Grid.columns` enum: `"2" | "3" | "4"` are STRINGS (Grid columns are strings, FeatureGrid columns are numbers — deliberate divergence in the contract) | Part III `Grid` prop table |
| `Grid.column<N>` and `.column<N>Align` slot/enum shapes | Part III |
| `Sponsors.slidesToShow` range 2-6, default 4 | Part III |
| `Sponsors.resolvedSponsors` 🚫 do-not-author | Part III |
| `NewsList.resolvedItems` 🚫 do-not-author | Part III |
| `NewsList.maxItems` range 1-20, default 6 | Part III |
| `Locations.items[]` shape `{name, address, lat, lng, environment, surfaceType, capacity, description}` | Part III `Locations` sub-table |
| `Locations.items[].environment` enum incl. `null` alongside `"indoor"|"outdoor"` (unusual — enum with null member) | Part III |
| `Locations.columns` enum `[1, 2, 3]` NUMBERS | Part III |
| `Standings / Scores / Schedule` orgType gate: `league`, `high_school`, `association` only | Part II "Org types" |
| `Standings.highlightTop` range 0-5, default 3 | Part III |
| `Scores.mode` / `Schedule.mode` enum: `recent | upcoming | all` | Part III |
| `Schedule.dateGrouping` enum: `none | day | week` | Part III
| every numeric prop's declared range (min/max) | Part III — but contract Part III also notes "Ranges are editor slider bounds, not validation — they are not enforced on save" so the validator should treat range violations as WARNINGS, not errors |
| every prop's default value | Part III complete-defaults blob per block |

## What is INFERRED

| Field | Guess | Contract shape probably calls it |
|---|---|---|
| Top-level `blocks` map | keyed by block name | probably the same — Part VI mentions "`blocks`" and "`props.properties`" by name |
| `props.properties` shape | JSON Schema-ish flat object mapping prop-name → prop-schema | same key name mentioned in Part VI |
| `type` field in prop schema | one of `string`, `number`, `enum`, `boolean`, `array`, `object`, `richtext`, `slot`, `opaque` | contract Part III uses these strings verbatim in its "Reading the type column" table, so probably the same |
| `enum` values keyed as `"enum": [...]` | array of allowed values, JSON types preserved | probably the same |
| Numeric ranges keyed as `"minimum"` and `"maximum"` | JSON Schema convention | contract calls them "min N, max N" in text so the JSON key could be `min`/`max` — I chose `minimum`/`maximum` matching JSON Schema |
| Nested `object.keys` | inline map of key → prop-schema | contract Part III shows these as separate sub-tables per parent-prop; the JSON might use `"properties"` at each level (JSON Schema recursion) instead of `"keys"` |
| Nested `array.items` | JSON Schema convention | contract Part III shows array items as separate sub-tables — the JSON likely uses `"items"` (JSON Schema style) which matches my choice |
| `chromeOnly` flag | boolean per block | contract has no explicit "chromeOnly" tag in Part VI; the JSON might instead express this via a `zones` field being empty of `page-content`, OR a separate top-level `emittable` / `never-emit` list. My `chromeOnly: true` is a fallback shape |
| `category` values | "Layout" / "Content" / "Interactive" / "TeamLinkt Widgets" / "Site chrome" | contract Part III uses these as its own H3 headings but the JSON might use different casing/naming (`"layout"` lowercase, or omit category entirely) |
| `orgTypes` array with `"all"` sentinel | inferred from Part II "Org types" | contract might spell out the six orgTypes explicitly per block rather than using an `"all"` sentinel |
| `richtextProps` list | inferred from Part II "Rich text" tabulation | contract might not carry this as a separate field; the presence of `type: "richtext"` inside `props.properties` should be sufficient signal |

## Almost certainly wrong

None yet — but the shape guesses are exposed to divergence when the real JSON lands. The
adapter is deliberately narrow so a swap is straightforward:

- `ContractSchema::load()` currently reads `resources/site-import-schema/blocks.json`.
  Swap to `core/storage/app/ai-website-builder-schema.json` (or a copy of it in-repo).
- If keys differ (`minimum` vs `min`, `items` vs different array-shape convention, etc.)
  the load method translates one → the other. That's the surface where drift lives.

## Post-swap TODO

When the real JSON arrives:

1. Save it to `resources/site-import-schema/blocks.json` (or update `ContractSchema` to read
   the engineering-supplied path).
2. Diff `defaults` for the five thin-slice blocks — regenerating what I encoded here.
3. Verify the enum-value type discipline (`Gallery.columns=[2,3,4,5]` numbers vs
   `Grid.columns=["2","3","4"]` strings, etc.) survives — that mismatch is the sort of
   thing hand-encoding gets subtly wrong.
4. Delete this file if the load succeeds cleanly against the real JSON.
