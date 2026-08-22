import type { Asset, Block } from '../types';
import { resolveContractAsset } from '../asset-resolver';

export default function Image({ block, assets }: { block: Block; assets: Asset[] }) {
    const props = block.props as {
        src?: string;
        alt?: string;
        caption?: string;
        aspectRatio?: string;
    };
    const src = resolveContractAsset(props.src, assets);
    if (!src) return null;
    return (
        <figure className="cp-image">
            <img src={src} alt={props.alt ?? ''} loading="lazy" />
            {props.caption ? <figcaption>{props.caption}</figcaption> : null}
        </figure>
    );
}
