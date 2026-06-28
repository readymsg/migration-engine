import { ResolvedNavItem } from './types';

interface Props {
    nav: ResolvedNavItem[];
    activeSlug: string | null;
    onSelect: (slug: string) => void;
}

export default function Nav({ nav, activeSlug, onSelect }: Props) {
    return (
        <nav className="preview-nav">
            <ul>
                {nav.map((item) => {
                    if (item.status === 'unresolved') {
                        return (
                            <li
                                key={item.label + item.order}
                                className="preview-nav__item preview-nav__item--unresolved"
                                title={item.note ?? 'Unresolved nav entry — engine produced no PuckOutput for this page'}
                            >
                                <span>{item.label}</span>
                                <span className="preview-nav__flag" aria-hidden>!</span>
                            </li>
                        );
                    }
                    if (item.status === 'unmatched_external') {
                        // External nav (LinkNode / Dibs toolsLink) — no
                        // page_map entry; render inert. Note tooltip
                        // surfaces what the engine did know about it.
                        return (
                            <li
                                key={item.label + item.order}
                                className="preview-nav__item preview-nav__item--external"
                                title={item.note ?? 'External link — not rendered in preview'}
                            >
                                <span>{item.label}</span>
                                <span className="preview-nav__flag preview-nav__flag--ext" aria-hidden>↗</span>
                            </li>
                        );
                    }
                    const isActive = activeSlug === item.page_slug;
                    return (
                        <li
                            key={item.label + item.order}
                            className={`preview-nav__item${isActive ? ' preview-nav__item--active' : ''}`}
                        >
                            <a
                                href={`#${item.page_slug}`}
                                onClick={(e) => {
                                    e.preventDefault();
                                    onSelect(item.page_slug);
                                }}
                            >
                                {item.label}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
