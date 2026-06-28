import type { Config } from '@measured/puck';
import { componentRegistry } from './components/registry';

// THROWAWAY (BUILD.md step 7). Mirrors DefaultPuckComponentSchema 1:1
// (app/Services/Schema/DefaultPuckComponentSchema.php).
//
// Hand-maintained, NOT generated from the PHP schema — the contract that
// "every ComponentDefinition has a matching entry here and the field
// shapes line up" lives in code review. Premature codegen for throwaway
// code is the kind of abstraction CLAUDE.md warns against.
//
// `fields` is included only so Puck's <Render> can resolve the components;
// the preview never enters editor mode (<Puck>), so the field types here
// are functional minimum-viable shapes rather than fully-tuned editor UX.

const heroFields = {
    heading: { type: 'text' },
    subheading: { type: 'text' },
    background_image: { type: 'text' },
    cta: {
        type: 'object',
        objectFields: {
            label: { type: 'text' },
            href: { type: 'text' },
        },
    },
} as const;

const headingFields = {
    text: { type: 'text' },
    level: {
        type: 'select',
        options: [
            { label: 'h1', value: 'h1' },
            { label: 'h2', value: 'h2' },
            { label: 'h3', value: 'h3' },
            { label: 'h4', value: 'h4' },
            { label: 'h5', value: 'h5' },
            { label: 'h6', value: 'h6' },
        ],
    },
} as const;

const textFields = {
    body: { type: 'textarea' },
    align: {
        type: 'select',
        options: [
            { label: 'left', value: 'left' },
            { label: 'center', value: 'center' },
            { label: 'right', value: 'right' },
        ],
    },
} as const;

const imageFields = {
    src: { type: 'text' },
    alt: { type: 'text' },
    caption: { type: 'text' },
} as const;

const columnsFields = {
    columns: {
        type: 'array',
        arrayFields: {
            width: {
                type: 'select',
                options: [
                    { label: 'auto', value: 'auto' },
                    { label: '1/2', value: '1/2' },
                    { label: '1/3', value: '1/3' },
                    { label: '2/3', value: '2/3' },
                    { label: '1/4', value: '1/4' },
                    { label: '3/4', value: '3/4' },
                ],
            },
            // Columns.children is rendered recursively by the Columns
            // component via render-block.tsx (it walks the nested {type,
            // props} entries). Puck's editor view doesn't need a DropZone
            // here for the preview's <Render>-only use.
            children: { type: 'array', arrayFields: {} },
        },
    },
} as const;

const cardFields = {
    title: { type: 'text' },
    body: { type: 'textarea' },
    image: { type: 'text' },
    href: { type: 'text' },
} as const;

const buttonGroupFields = {
    buttons: {
        type: 'array',
        arrayFields: {
            label: { type: 'text' },
            href: { type: 'text' },
            variant: {
                type: 'select',
                options: [
                    { label: 'primary', value: 'primary' },
                    { label: 'secondary', value: 'secondary' },
                    { label: 'ghost', value: 'ghost' },
                ],
            },
        },
    },
} as const;

const platformFields = {
    org_id: { type: 'text' },
} as const;

// Cast each entry into Puck's component-config shape. The registry maps
// every type to a React component that accepts a free-form props bag, so
// the preview's <Render> doesn't depend on Puck inferring exact prop
// types from `fields`.
function comp(fields: unknown, type: string) {
    return {
        fields,
        render: componentRegistry[type],
    };
}

export const puckConfig: Config = {
    components: {
        Hero: comp(heroFields, 'Hero'),
        Heading: comp(headingFields, 'Heading'),
        Text: comp(textFields, 'Text'),
        Image: comp(imageFields, 'Image'),
        Columns: comp(columnsFields, 'Columns'),
        Card: comp(cardFields, 'Card'),
        ButtonGroup: comp(buttonGroupFields, 'ButtonGroup'),

        PlatformSchedule: comp(platformFields, 'PlatformSchedule'),
        PlatformScores: comp(platformFields, 'PlatformScores'),
        PlatformStandings: comp(platformFields, 'PlatformStandings'),
        PlatformRoster: comp(platformFields, 'PlatformRoster'),
        PlatformTeams: comp(platformFields, 'PlatformTeams'),
        PlatformDivisions: comp(platformFields, 'PlatformDivisions'),
        PlatformContacts: comp(platformFields, 'PlatformContacts'),
        PlatformCalendar: comp(platformFields, 'PlatformCalendar'),
        PlatformNews: comp(platformFields, 'PlatformNews'),
    },
} as Config;
