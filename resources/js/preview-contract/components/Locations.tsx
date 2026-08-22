import type { Block } from '../types';

export default function Locations({ block }: { block: Block }) {
    const props = block.props as { heading?: string };
    return (
        <section className="cp-widget cp-locations">
            <div className="cp-widget__label">TeamLinkt Widget · Locations</div>
            <h2 className="cp-widget__heading">{props.heading ?? 'Our Venues'}</h2>
            <div className="cp-widget__placeholder">
                Venue cards + embedded maps will render live from TeamLinkt's location data.
            </div>
        </section>
    );
}
