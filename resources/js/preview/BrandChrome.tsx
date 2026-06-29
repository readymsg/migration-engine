import { BrandData, StyleBrief } from './types';

// PREVIEW CHROME — NOT page content, NOT a Puck block, NOT a claim the
// landed draft is branded. Same posture as <StatusRibbon>: it describes
// what the engine extracted, sits outside the page frame, and is
// visually styled as metadata.
//
// Why this isn't dressed up to look like a site header:
//   In v1 the landed draft (what createDraftSite receives) does NOT
//   carry brand. The preview's job here is to make the EXTRACTED brand
//   visible so a reviewer can see what the engine captured — NOT to
//   pretend the rebuilt site is branded. If we styled this as a real
//   header it'd misrepresent the engine's output.

interface Props {
    brand: BrandData;
    styleBrief: StyleBrief;
    orgId: string;
}

function paletteAsObject(p: Record<string, string> | never[]): Record<string, string> {
    return Array.isArray(p) ? {} : p;
}

// Brand.logo_asset_ref is an S3 KEY (s3://bucket/path/file.png), not an
// http(s) URL. In the offline replay it's the FakeAssetUploader's fake
// key (`s3://fake/...`); even in a real production conversion it'd be
// the S3 key, not a publicly resolvable URL. Either way the browser
// can't fetch it — the honest render is a placeholder + the org's name
// + the key string for visibility.
function isResolvableImageUrl(ref: string | null): boolean {
    if (!ref) return false;
    return /^https?:\/\//i.test(ref);
}

export default function BrandChrome({ brand, styleBrief, orgId }: Props) {
    const palette = paletteAsObject(styleBrief.palette);
    const paletteEntries = Object.entries(palette);
    const brandPalette = paletteAsObject(brand.palette);
    const brandPaletteEntries = Object.entries(brandPalette);

    return (
        <div className="preview-brand-chrome">
            <div className="preview-brand-chrome__label">
                Engine-extracted brand metadata
                <span className="preview-brand-chrome__sublabel">
                    preview chrome — NOT carried into the landed draft in v1
                </span>
            </div>

            <div className="preview-brand-chrome__grid">
                <div className="preview-brand-chrome__cell preview-brand-chrome__cell--logo">
                    <div className="preview-brand-chrome__cell-title">Logo</div>
                    {isResolvableImageUrl(brand.logo_asset_ref) ? (
                        <img
                            className="preview-brand-chrome__logo"
                            src={brand.logo_asset_ref ?? ''}
                            alt={`${orgId} logo`}
                        />
                    ) : (
                        <div className="preview-brand-chrome__logo-fallback">
                            <div className="preview-brand-chrome__logo-fallback-org">{orgId}</div>
                            <div className="preview-brand-chrome__logo-fallback-note">
                                logo extracted, not yet rehosted
                            </div>
                            <div className="preview-brand-chrome__logo-fallback-ref">
                                {brand.logo_asset_ref ?? '(no logo_asset_ref)'}
                            </div>
                        </div>
                    )}
                    <div className="preview-brand-chrome__cell-foot">
                        source: <code>{brand.logo_source}</code>
                    </div>
                </div>

                <div className="preview-brand-chrome__cell preview-brand-chrome__cell--voice">
                    <div className="preview-brand-chrome__cell-title">Brand voice (LLM-inferred)</div>
                    <div className="preview-brand-chrome__voice">
                        {styleBrief.brand_voice
                            ? styleBrief.brand_voice
                            : '(no brand voice — IR pass produced an empty brief)'}
                    </div>
                    {brand.voice_hint ? (
                        <div className="preview-brand-chrome__cell-foot">
                            extractor hint: <em>{brand.voice_hint}</em>
                        </div>
                    ) : null}
                </div>

                <div className="preview-brand-chrome__cell preview-brand-chrome__cell--palette">
                    <div className="preview-brand-chrome__cell-title">
                        Palette {paletteEntries.length > 0 ? '(LLM-inferred)' : ''}
                    </div>
                    {paletteEntries.length > 0 ? (
                        <div className="preview-brand-chrome__swatches">
                            {paletteEntries.map(([name, hex]) => (
                                <div key={name} className="preview-brand-chrome__swatch">
                                    <div
                                        className="preview-brand-chrome__swatch-chip"
                                        style={{ background: hex }}
                                        title={`${name}: ${hex}`}
                                    />
                                    <div className="preview-brand-chrome__swatch-name">{name}</div>
                                    <div className="preview-brand-chrome__swatch-hex">{hex}</div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="preview-brand-chrome__empty">
                            (style_brief.palette empty — IR pass produced no palette)
                        </div>
                    )}
                    {brandPaletteEntries.length > 0 ? (
                        <div className="preview-brand-chrome__cell-foot">
                            extractor also reported: {brandPaletteEntries.map(([k, v]) => `${k}=${v}`).join(', ')}
                        </div>
                    ) : (
                        <div className="preview-brand-chrome__cell-foot">
                            (BrandExtractor doesn't populate palette today — palette here is the IR-pass-inferred one)
                        </div>
                    )}
                </div>

                <div className="preview-brand-chrome__cell preview-brand-chrome__cell--layouts">
                    <div className="preview-brand-chrome__cell-title">
                        Layout conventions ({styleBrief.layout_conventions.length})
                    </div>
                    {styleBrief.layout_conventions.length > 0 ? (
                        <ul className="preview-brand-chrome__layouts">
                            {styleBrief.layout_conventions.map((c, i) => (
                                <li key={i}>{c}</li>
                            ))}
                        </ul>
                    ) : (
                        <div className="preview-brand-chrome__empty">
                            (no layout conventions in this brief)
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
