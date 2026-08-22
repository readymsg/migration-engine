import type { Block } from '../types';

export default function NewsList({ block }: { block: Block }) {
    const props = block.props as { heading?: string };
    return (
        <section className="cp-widget cp-newslist">
            <div className="cp-widget__label">TeamLinkt Widget · News</div>
            <h2 className="cp-widget__heading">{props.heading ?? 'Latest news'}</h2>
            <div className="cp-widget__placeholder">
                Recent articles will render live from TeamLinkt's news feed.
            </div>
        </section>
    );
}
