// TypeScript mirror of App\Data\SiteImport\Envelope + friends. Kept
// in lockstep with the PHP DTOs by code review — no schema codegen
// for the M1 preview.

export interface Source {
    url: string;
    scrapedAt: string;
    pagesDiscovered: number;
    pagesMapped: number;
}

export interface SocialLinks {
    facebook?: string;
    twitter?: string;
    instagram?: string;
    tiktok?: string;
    youtube?: string;
    linkedin?: string;
}

export interface SiteSettings {
    siteName?: string;
    logoUrl?: string;
    favicon?: string;
    primaryColor?: string;
    neutralColor?: string;
    headerColor?: string;
    headerTextColor?: string;
    pageBackground?: string;
    footerCopyright?: string;
    contactEmail?: string;
    socialLinks?: SocialLinks;
}

export interface Block {
    type: string;
    props: {
        id: string;
        [key: string]: unknown;
    };
}

export interface PageData {
    content: Block[];
    root: Record<string, unknown>;
    zones: Record<string, unknown>;
}

export interface Page {
    id: string;
    slug: string;
    title: string;
    parentId: string | null;
    navOrder: number;
    showInNav: boolean;
    data: PageData;
}

export interface Asset {
    ref: string;
    sourceUrl: string;
    filename: string;
    mimeType: string;
    alt?: string;
    usage?: string;
}

export interface Diagnostic {
    severity: 'info' | 'warning' | 'error';
    code: string;
    message: string;
    sourceUrl?: string;
    droppedContent?: string;
}

export interface Envelope {
    schemaVersion: number;
    source: Source;
    site: SiteSettings;
    pages: Page[];
    assets: Asset[];
    diagnostics: Diagnostic[];
}
