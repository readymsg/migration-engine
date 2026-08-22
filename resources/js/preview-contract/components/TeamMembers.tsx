import type { Asset, Block } from '../types';
import { resolveContractAsset } from '../asset-resolver';

// Contract TeamMembers block. Slice 13 folds Board/Contacts
// Columns-of-Cards into a single TeamMembers with items[]:
//   { photo, name, role, email, bio }
export default function TeamMembers({ block, assets }: { block: Block; assets: Asset[] }) {
    const props = block.props as {
        columns?: 2 | 3 | 4;
        showImage?: boolean;
        heading?: string;
        preheading?: string;
        items?: Array<{
            photo?: string;
            name?: string;
            role?: string;
            email?: string;
            bio?: string;
        }>;
    };
    const items = props.items ?? [];
    if (items.length === 0) return null;
    const columns = props.columns ?? 3;
    const showImage = props.showImage !== false;

    return (
        <section className="cp-team-members">
            {props.preheading ? <div className="cp-team-members__preheading">{props.preheading}</div> : null}
            {props.heading ? <h2 className="cp-team-members__heading">{props.heading}</h2> : null}
            <div
                className="cp-team-members__grid"
                style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}
            >
                {items.map((item, i) => {
                    const photoUrl = showImage ? resolveContractAsset(item.photo, assets) : undefined;
                    return (
                        <div key={i} className="cp-team-members__card">
                            {photoUrl ? (
                                <img className="cp-team-members__photo" src={photoUrl} alt={item.name ?? ''} loading="lazy" />
                            ) : showImage ? (
                                <div className="cp-team-members__photo-fallback">
                                    {(item.name ?? '?').split(' ').map((w) => w[0]).slice(0, 2).join('')}
                                </div>
                            ) : null}
                            {item.name ? <div className="cp-team-members__name">{item.name}</div> : null}
                            {item.role ? <div className="cp-team-members__role">{item.role}</div> : null}
                            {item.email ? (
                                <a className="cp-team-members__email" href={`mailto:${item.email}`}>
                                    {item.email}
                                </a>
                            ) : null}
                            {item.bio ? <div className="cp-team-members__bio">{item.bio}</div> : null}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
