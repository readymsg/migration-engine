import { ComponentType } from 'react';
import Hero from './Hero';
import Heading from './Heading';
import Text from './Text';
import Image from './Image';
import Columns from './Columns';
import Card from './Card';
import ButtonGroup from './ButtonGroup';
import PlatformBlockStub from './PlatformBlockStub';

// THROWAWAY (BUILD.md step 7). Mirrors DefaultPuckComponentSchema 1:1.
// Hand-maintained — not generated from the PHP schema — and the
// contract that "every ComponentDefinition has a matching entry here"
// lives in code review. If a 17th component lands in
// DefaultPuckComponentSchema and is missed here, the bundle renders an
// "unknown block type" placeholder for it (see render-block.tsx) — loud,
// not silent.

const platformStub = (blockType: string): ComponentType<Record<string, unknown>> =>
    (props) => <PlatformBlockStub blockType={blockType} {...(props as { org_id?: string })} />;

export const componentRegistry: Record<string, ComponentType<Record<string, unknown>>> = {
    // Content components (7) — DefaultPuckComponentSchema::all()
    Hero: Hero as ComponentType<Record<string, unknown>>,
    Heading: Heading as ComponentType<Record<string, unknown>>,
    Text: Text as ComponentType<Record<string, unknown>>,
    Image: Image as ComponentType<Record<string, unknown>>,
    Columns: Columns as ComponentType<Record<string, unknown>>,
    Card: Card as ComponentType<Record<string, unknown>>,
    ButtonGroup: ButtonGroup as ComponentType<Record<string, unknown>>,

    // Platform components (9) — DefaultPuckComponentSchema::platformBlocks().
    // All render via one shared <PlatformBlockStub> showing the empty-state.
    PlatformSchedule: platformStub('PlatformSchedule'),
    PlatformScores: platformStub('PlatformScores'),
    PlatformStandings: platformStub('PlatformStandings'),
    PlatformRoster: platformStub('PlatformRoster'),
    PlatformTeams: platformStub('PlatformTeams'),
    PlatformDivisions: platformStub('PlatformDivisions'),
    PlatformContacts: platformStub('PlatformContacts'),
    PlatformCalendar: platformStub('PlatformCalendar'),
    PlatformNews: platformStub('PlatformNews'),
};
