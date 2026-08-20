import { BrandData, ResolvedNavItem } from './types';
import { resolvePreviewAsset } from './asset-resolver.js';

// THROWAWAY (BUILD.md step 7). PREVIEW-ONLY site header that mimics
// what a rebuilt club site's chrome would look like — logo, org name,
// horizontal main nav. Renders ABOVE .preview-page.
//
// CRITICAL: this is NOT part of createDraftSite's payload. The engine
// does not emit site chrome today; header/nav/footer live in the
// product's siteSettings.zones once the draft lands, populated by
// the product's own defaults. This component exists so a reviewer
// looking at http://127.0.0.1:8000/preview/tbirdhoops can see the
// rebuilt content in a context that reads like a real website —
// nothing here changes what ProductClient::createDraftSite receives.
//
// Distinct from <Nav>, which is the PILL PAGE SWITCHER (a debug
// affordance for jumping between rebuilt pages). This header's nav
// is styled as a real site nav: text links across a bar, only
// resolved pages, no unresolved / external decorations.

interface Props {
    brand: BrandData;
    orgId: string;
    nav: ResolvedNavItem[];
    activeSlug: string | null;
    onSelect: (slug: string) => void;
}

export default function SiteHeader({ brand, orgId, nav, activeSlug, onSelect }: Props) {
    const logoSrc = brand.logo_asset_ref ? resolvePreviewAsset(brand.logo_asset_ref) : undefined;
    const resolvedNav = nav.filter((n) => n.status === 'resolved');

    return (
        <header className="preview-site-header" role="banner">
            <a
                className="preview-site-header__brand"
                href={`#${activeSlug ?? ''}`}
                onClick={(e) => {
                    e.preventDefault();
                    if (resolvedNav[0]) onSelect(resolvedNav[0].page_slug);
                }}
            >
                {logoSrc ? (
                    <img className="preview-site-header__logo" src={logoSrc} alt={`${orgId} logo`} />
                ) : (
                    <span className="preview-site-header__logo-fallback" aria-hidden="true">
                        {orgId.replace(/^ngin-/, '').slice(0, 2).toUpperCase()}
                    </span>
                )}
                <span className="preview-site-header__org">{orgId}</span>
            </a>
            <nav className="preview-site-header__nav" aria-label="Site navigation">
                {resolvedNav.map((item) => (
                    <a
                        key={item.page_slug}
                        href={`#${item.page_slug}`}
                        className={
                            'preview-site-header__nav-link' +
                            (item.page_slug === activeSlug ? ' preview-site-header__nav-link--active' : '')
                        }
                        onClick={(e) => {
                            e.preventDefault();
                            onSelect(item.page_slug);
                        }}
                    >
                        {item.label}
                    </a>
                ))}
            </nav>
        </header>
    );
}
