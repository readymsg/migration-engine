import { ConversionResultJson, ConversionStatus } from './types';

interface Props {
    status: ConversionStatus;
    failures: ConversionResultJson['failures'];
    blockIssuesBySlug: ConversionResultJson['block_issues_by_slug'];
    conversionId: string;
    draftUrl: string | null;
}

// THROWAWAY (BUILD.md step 7). Preview chrome, NOT content. Sits OUTSIDE
// the rendered page frame so it can't be mistaken for something the
// product would render. Shows conversion metadata when status != completed
// so a reviewer doesn't have to scrub the JSON to spot Partial/Failed.
export default function StatusRibbon({
    status,
    failures,
    blockIssuesBySlug,
    conversionId,
    draftUrl,
}: Props) {
    const issueCount = countBlockIssues(blockIssuesBySlug);
    const failureCount = failures.length;

    return (
        <div className={`preview-ribbon preview-ribbon--${status}`}>
            <div className="preview-ribbon__title">
                <strong>{labelFor(status)}</strong>
                <span className="preview-ribbon__meta">
                    {conversionId}
                    {draftUrl ? (
                        <>
                            {' · '}
                            <a href={draftUrl} target="_blank" rel="noreferrer">draft</a>
                        </>
                    ) : null}
                </span>
            </div>

            {status !== 'completed' ? (
                <div className="preview-ribbon__counts">
                    {failureCount} failure{failureCount === 1 ? '' : 's'} · {issueCount} block issue{issueCount === 1 ? '' : 's'}
                </div>
            ) : null}

            {failureCount > 0 ? (
                <ul className="preview-ribbon__list">
                    {failures.map((f, i) => (
                        <li key={i}>
                            <code>{f.stage}</code> · <strong>{f.page_title || f.page_slug}</strong> — {f.reason}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

function labelFor(status: ConversionStatus): string {
    switch (status) {
        case 'completed':
            return 'Completed';
        case 'partial':
            return 'Partial';
        case 'failed':
            return 'Failed';
    }
}

function countBlockIssues(byslug: ConversionResultJson['block_issues_by_slug']): number {
    if (Array.isArray(byslug)) return 0; // PHP empty assoc → []
    let total = 0;
    for (const slug of Object.keys(byslug)) {
        total += byslug[slug].length;
    }
    return total;
}
