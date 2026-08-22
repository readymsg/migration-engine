import { useEffect, useMemo, useState } from 'react';
import type { Envelope, Page } from './types';
import { resolveContractAsset } from './asset-resolver';
import Hero from './components/Hero';
import Text from './components/Text';
import ImageBlock from './components/Image';
import Gallery from './components/Gallery';
import Button from './components/Button';
import TeamMembers from './components/TeamMembers';
import Sponsors from './components/Sponsors';
import './preview-contract.css';

const KNOWN_TYPES = new Set([
    'Hero', 'Text', 'Image', 'Gallery', 'Button',
    'TeamMembers', 'Sponsors',
]);

export default function App({ slug }: { slug: string }) {
    const [envelope, setEnvelope] = useState<Envelope | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetch(`/api/preview-contract/${slug}/envelope`)
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
            .then(setEnvelope)
            .catch((e) => setError(String(e)));
    }, [slug]);

    useEffect(() => {
        if (envelope) applyBrandColors(envelope);
    }, [envelope]);

    const [activeSlug, setActiveSlug] = useState<string>('');
    const activePage = useMemo(
        () => envelope?.pages.find((p) => p.slug === activeSlug) ?? envelope?.pages[0],
        [envelope, activeSlug],
    );

    if (error) return <div className="cp-error">Preview error: {error}</div>;
    if (!envelope) return <div className="cp-loading">Loading contract payload…</div>;
    if (!activePage) return <div className="cp-error">No pages in payload.</div>;

    // Every top-level nav-visible page (matches Contract's showInNav
    // flag). Home is slug="" — display as "Home".
    const navPages = envelope.pages
        .filter((p) => p.showInNav)
        .sort((a, b) => a.navOrder - b.navOrder);

    return (
        <div className="cp-root">
            <ContractHeader envelope={envelope} navPages={navPages} activeSlug={activePage.slug} onNav={setActiveSlug} />
            <StatusRibbon envelope={envelope} activePage={activePage} />
            <main className="cp-main">
                {activePage.data.content.map((block, i) => (
                    <BlockSwitch key={block.props.id + i} block={block} envelope={envelope} />
                ))}
            </main>
            <ContractFooter envelope={envelope} />
        </div>
    );
}

function BlockSwitch({ block, envelope }: { block: import('./types').Block; envelope: Envelope }) {
    if (!KNOWN_TYPES.has(block.type)) {
        return (
            <div className="cp-unknown">
                <div className="cp-unknown__label">Block type <code>{block.type}</code> not yet rendered by the M1 preview.</div>
            </div>
        );
    }
    switch (block.type) {
        case 'Hero':
            return <Hero block={block} assets={envelope.assets} />;
        case 'Text':
            return <Text block={block} />;
        case 'Image':
            return <ImageBlock block={block} assets={envelope.assets} />;
        case 'Gallery':
            return <Gallery block={block} assets={envelope.assets} />;
        case 'Button':
            return <Button block={block} />;
        case 'TeamMembers':
            return <TeamMembers block={block} assets={envelope.assets} />;
        case 'Sponsors':
            return <Sponsors block={block} />;
    }
    return null;
}

function ContractHeader({
    envelope,
    navPages,
    activeSlug,
    onNav,
}: {
    envelope: Envelope;
    navPages: Page[];
    activeSlug: string;
    onNav: (slug: string) => void;
}) {
    const logo = resolveContractAsset(envelope.site.logoUrl, envelope.assets);
    return (
        <header className="cp-header">
            <div className="cp-header__inner">
                {logo ? (
                    <img className="cp-header__logo" src={logo} alt={envelope.site.siteName ?? ''} />
                ) : (
                    <div className="cp-header__brand">{envelope.site.siteName ?? '—'}</div>
                )}
                <nav className="cp-header__nav">
                    {navPages.map((p) => (
                        <button
                            key={p.id}
                            className={`cp-header__navitem${p.slug === activeSlug ? ' cp-header__navitem--active' : ''}`}
                            onClick={() => onNav(p.slug)}
                        >
                            {p.title}
                        </button>
                    ))}
                </nav>
            </div>
        </header>
    );
}

function ContractFooter({ envelope }: { envelope: Envelope }) {
    return (
        <footer className="cp-footer">
            <div className="cp-footer__inner">
                {envelope.site.footerCopyright ?? (envelope.site.siteName ?? 'Preview')}
                <span className="cp-footer__note"> · preview-only chrome · template supplies real header/footer at publish time</span>
            </div>
        </footer>
    );
}

function StatusRibbon({ envelope, activePage }: { envelope: Envelope; activePage: Page }) {
    // Reviewer-facing ribbon — surfaces payload health at a glance.
    const errors = envelope.diagnostics.filter((d) => d.severity === 'error').length;
    const warnings = envelope.diagnostics.filter((d) => d.severity === 'warning').length;
    return (
        <div className="cp-ribbon">
            <span>schemaVersion=<strong>{envelope.schemaVersion}</strong></span>
            <span>pages=<strong>{envelope.pages.length}</strong></span>
            <span>assets=<strong>{envelope.assets.length}</strong></span>
            <span>diagnostics=<strong>{envelope.diagnostics.length}</strong> ({errors}e / {warnings}w)</span>
            <span>page=<strong>{activePage.slug === '' ? '(home)' : activePage.slug}</strong></span>
        </div>
    );
}

// Applies site.primaryColor / neutralColor to CSS custom props on
// :root. Same technique as our old preview's applyBrandPalette,
// but reading contract keys directly.
function applyBrandColors(env: Envelope): void {
    const root = document.documentElement;
    if (env.site.primaryColor) {
        root.style.setProperty('--cp-primary', env.site.primaryColor);
    }
    if (env.site.neutralColor) {
        root.style.setProperty('--cp-neutral', env.site.neutralColor);
    }
}
