import type { Asset, Block } from '../types';
import Hero from './Hero';
import Text from './Text';
import ImageBlock from './Image';
import Gallery from './Gallery';
import Button from './Button';
import TeamMembers from './TeamMembers';
import Sponsors from './Sponsors';

// Contract Grid: 2-4 columns with slot props column1..4. Each slot
// holds an array of nested Block objects. Layout preserved from
// source Columns via Slice 15c's emitter.
export default function Grid({ block, assets }: { block: Block; assets: Asset[] }) {
    const props = block.props as {
        columns?: '2' | '3' | '4';
        column1?: Block[];
        column2?: Block[];
        column3?: Block[];
        column4?: Block[];
    };
    const columnCount = parseInt(props.columns ?? '3', 10);

    return (
        <section className="cp-grid" style={{ gridTemplateColumns: `repeat(${columnCount}, 1fr)` }}>
            {(['column1', 'column2', 'column3', 'column4'] as const).slice(0, columnCount).map((slot) => (
                <div key={slot} className="cp-grid__column">
                    {(props[slot] ?? []).map((child, i) => (
                        <GridChild key={i} block={child} assets={assets} />
                    ))}
                </div>
            ))}
        </section>
    );
}

function GridChild({ block, assets }: { block: Block; assets: Asset[] }) {
    switch (block.type) {
        case 'Hero':
            return <Hero block={block} assets={assets} />;
        case 'Text':
            return <Text block={block} />;
        case 'Image':
            return <ImageBlock block={block} assets={assets} />;
        case 'Gallery':
            return <Gallery block={block} assets={assets} />;
        case 'Button':
            return <Button block={block} />;
        case 'TeamMembers':
            return <TeamMembers block={block} assets={assets} />;
        case 'Sponsors':
            return <Sponsors block={block} />;
    }
    return (
        <div className="cp-unknown">
            <code>{block.type}</code> in Grid slot (not rendered)
        </div>
    );
}
