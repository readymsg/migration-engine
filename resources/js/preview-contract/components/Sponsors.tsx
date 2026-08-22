import type { Block } from '../types';

// Sponsors widget: placed, never filled. The scraped logos and
// URLs are DISCARDED per contract — the org's actual sponsors
// live in TeamLinkt. Preview shows the placeholder shape so a
// reviewer can see the widget will be here on the published site.
export default function Sponsors({ block }: { block: Block }) {
    const props = block.props as { heading?: string };
    return (
        <section className="cp-widget cp-sponsors">
            <div className="cp-widget__label">TeamLinkt Widget · Sponsors</div>
            <h2 className="cp-widget__heading">{props.heading ?? 'Our Sponsors'}</h2>
            <div className="cp-widget__placeholder">
                Sponsor logos will render live from TeamLinkt's sponsor manager.
            </div>
        </section>
    );
}
