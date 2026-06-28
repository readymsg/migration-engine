import { PuckBlock } from '../types';
import { componentRegistry } from './registry';

// Renders one nested {type, props} block using the same React-component
// registry the Puck config uses for top-level blocks. Lives outside
// puck-config.tsx so the Columns component (which renders nested
// children itself) can import it without a circular dep.
export function renderBlock(block: PuckBlock, key: number | string) {
    const entry = componentRegistry[block.type];
    if (!entry) {
        return (
            <div key={key} className="preview-block preview-unknown">
                unknown block type: <code>{block.type}</code>
            </div>
        );
    }
    const Component = entry as React.ComponentType<Record<string, unknown>>;
    return <Component key={key} {...(block.props ?? {}) } />;
}
