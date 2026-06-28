// THROWAWAY (BUILD.md step 7). Shape of the JSON served by
// GET /api/preview/{slug}/site — mirrors App\Data\ConversionResult so
// the bundle can be typed without runtime validation. Stays in lockstep
// with the PHP DTO by code review; no schema codegen for throwaway code.

export type ResolvedNavStatus = 'resolved' | 'unmatched_external' | 'unresolved';

export type ConversionStatus = 'completed' | 'partial' | 'failed';

export type ConversionStage =
    | 'ir-pass'
    | 'block-fill'
    | 'assembler'
    | 'platform-render'
    | 'draft-landing';

export interface ResolvedNavItem {
    label: string;
    page_slug: string;
    order: number;
    status: ResolvedNavStatus;
    note: string | null;
}

export interface ConversionFailure {
    page_slug: string;
    page_title: string;
    page_node_id: number | null;
    stage: ConversionStage;
    reason: string;
}

export interface PuckBlock {
    type: string;
    props: Record<string, unknown>;
}

export interface PuckPage {
    content: PuckBlock[];
    root: Record<string, unknown>;
    zones: Record<string, PuckBlock[]>;
}

export interface ConversionResultJson {
    conversion_id: string;
    org_id: string;
    page_map: Record<string, PuckPage>;
    nav: ResolvedNavItem[];
    failures: ConversionFailure[];
    // PHP encodes an empty assoc-array as []; runtime is either array or object
    block_issues_by_slug:
        | Record<string, Array<{ block_index: number; component_type: string; coercion: string; reason: string; path: string | null }>>
        | never[];
    status: ConversionStatus;
    draft_id: string | null;
    draft_url: string | null;
}
