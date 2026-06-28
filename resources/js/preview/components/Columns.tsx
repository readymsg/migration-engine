import { PuckBlock } from '../types';
import { renderBlock } from './render-block';

interface Column {
    width?: 'auto' | '1/2' | '1/3' | '2/3' | '1/4' | '3/4';
    children?: PuckBlock[];
}

interface ColumnsProps {
    columns?: Column[];
}

const WIDTH_CLASS: Record<NonNullable<Column['width']>, string> = {
    auto: 'preview-column--auto',
    '1/2': 'preview-column--w-1-2',
    '1/3': 'preview-column--w-1-3',
    '2/3': 'preview-column--w-2-3',
    '1/4': 'preview-column--w-1-4',
    '3/4': 'preview-column--w-3-4',
};

export default function Columns({ columns = [] }: ColumnsProps) {
    return (
        <div className="preview-block preview-columns">
            {columns.map((col, i) => {
                const widthClass = WIDTH_CLASS[col.width ?? 'auto'];
                return (
                    <div key={i} className={`preview-column ${widthClass}`}>
                        {(col.children ?? []).map((child, j) => renderBlock(child, j))}
                    </div>
                );
            })}
        </div>
    );
}
