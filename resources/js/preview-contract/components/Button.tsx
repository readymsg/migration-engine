import type { Block } from '../types';

export default function Button({ block }: { block: Block }) {
    const props = block.props as {
        label?: string;
        href?: string;
        variant?: 'solid' | 'soft' | 'outline' | 'ghost';
        alignment?: 'left' | 'center' | 'right';
    };
    if (!props.label) return null;
    return (
        <div className={`cp-button-row cp-button-row--${props.alignment ?? 'left'}`}>
            <a
                className={`cp-button cp-button--${props.variant ?? 'solid'}`}
                href={props.href || '#'}
            >
                {props.label}
            </a>
        </div>
    );
}
