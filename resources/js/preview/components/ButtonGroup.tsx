interface Button {
    label?: string;
    href?: string;
    variant?: 'primary' | 'secondary' | 'ghost';
}

interface ButtonGroupProps {
    buttons?: Button[];
}

export default function ButtonGroup({ buttons = [] }: ButtonGroupProps) {
    return (
        <div className="preview-block preview-buttongroup">
            {buttons.map((btn, i) => (
                <a
                    key={i}
                    className={`preview-button preview-button--${btn.variant ?? 'primary'}`}
                    href={btn.href ?? '#'}
                >
                    {btn.label ?? ''}
                </a>
            ))}
        </div>
    );
}
