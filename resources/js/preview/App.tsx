import { useEffect, useState } from 'react';
import { Render } from '@measured/puck';
import { puckConfig } from './puck-config';
import { ConversionResultJson, ResolvedNavItem } from './types';
import Nav from './Nav';
import StatusRibbon from './StatusRibbon';
import BrandChrome from './BrandChrome';
import '@measured/puck/puck.css';
import './preview.css';

interface Props {
    slug: string;
}

type LoadState =
    | { state: 'loading' }
    | { state: 'error'; message: string }
    | { state: 'ready'; data: ConversionResultJson };

export default function App({ slug }: Props) {
    const [load, setLoad] = useState<LoadState>({ state: 'loading' });
    const [activeSlug, setActiveSlug] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        fetch(`/api/preview/${slug}/site`)
            .then(async (res) => {
                if (!res.ok) {
                    const body = await res.text();
                    throw new Error(`HTTP ${res.status}: ${body}`);
                }
                return res.json() as Promise<ConversionResultJson>;
            })
            .then((data) => {
                if (cancelled) return;
                setLoad({ state: 'ready', data });
                setActiveSlug(initialSlug(data));
                applyBrandPalette(data);
            })
            .catch((err: Error) => {
                if (cancelled) return;
                setLoad({ state: 'error', message: err.message });
            });
        return () => {
            cancelled = true;
        };
    }, [slug]);

    // Hash-routing: switch active page when the URL hash changes,
    // including back/forward and direct deep-links. No router lib —
    // this is throwaway code.
    useEffect(() => {
        if (load.state !== 'ready') return;
        const sync = () => {
            const hash = window.location.hash.replace(/^#/, '');
            if (hash && load.data.page_map[hash]) {
                setActiveSlug(hash);
            }
        };
        sync();
        window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, [load]);

    const handleSelect = (slug: string) => {
        setActiveSlug(slug);
        window.location.hash = `#${slug}`;
    };

    if (load.state === 'loading') {
        return <div className="preview-shell preview-shell--loading">Loading preview…</div>;
    }
    if (load.state === 'error') {
        return (
            <div className="preview-shell preview-shell--error">
                <h1>Preview unavailable</h1>
                <pre>{load.message}</pre>
                <p>
                    Run <code>php artisan engine:emit-preview-fixture</code> to regenerate
                    the static fixture.
                </p>
            </div>
        );
    }

    const { data } = load;
    const page = activeSlug ? data.page_map[activeSlug] : undefined;

    return (
        <div className="preview-shell">
            <StatusRibbon
                status={data.status}
                failures={data.failures}
                blockIssuesBySlug={data.block_issues_by_slug}
                conversionId={data.conversion_id}
                draftUrl={data.draft_url}
            />
            <BrandChrome
                brand={data.brand}
                styleBrief={data.style_brief}
                orgId={data.org_id}
            />
            <Nav nav={sortedNav(data.nav)} activeSlug={activeSlug} onSelect={handleSelect} />
            <main className="preview-page">
                {page ? (
                    <Render config={puckConfig} data={page} />
                ) : (
                    <div className="preview-empty">
                        No page selected (or page slug <code>{activeSlug}</code> is not in page_map).
                    </div>
                )}
            </main>
        </div>
    );
}

// Applies style_brief.palette as CSS custom properties on :root so
// preview chrome can pick the org's colors. The palette is LLM-inferred
// (not measured from the source CSS) — honest about provenance via the
// BrandChrome's "LLM-inferred" label. If the palette is empty, the
// defaults in preview.css stay in effect — we never invent colors.
function applyBrandPalette(data: ConversionResultJson): void {
    const palette = data.style_brief.palette;
    if (!palette || Array.isArray(palette)) return;
    const root = document.documentElement.style;
    if (palette.primary) root.setProperty('--pv-brand-primary', palette.primary);
    if (palette.secondary) root.setProperty('--pv-brand-secondary', palette.secondary);
    if (palette.accent) root.setProperty('--pv-brand-accent', palette.accent);
    if (palette.background) root.setProperty('--pv-brand-background', palette.background);
    if (palette.text) root.setProperty('--pv-brand-text', palette.text);
}

function initialSlug(data: ConversionResultJson): string | null {
    const hash = window.location.hash.replace(/^#/, '');
    if (hash && data.page_map[hash]) return hash;
    const sorted = sortedNav(data.nav);
    const firstResolved = sorted.find((n) => n.status === 'resolved');
    if (firstResolved) return firstResolved.page_slug;
    // Fall back to first key in page_map so the bundle always renders
    // something when the fixture has pages but no nav links to them.
    const keys = Object.keys(data.page_map);
    return keys[0] ?? null;
}

function sortedNav(nav: ResolvedNavItem[]): ResolvedNavItem[] {
    return [...nav].sort((a, b) => a.order - b.order);
}
