interface Props {
    blockType: string;
    org_id?: string;
}

// Single React component used to render all 9 PlatformBlockType variants
// (PlatformSchedule, PlatformScores, ..., PlatformNews). Per CLAUDE.md
// GENERATE-2e contract: the engine emits a structurally-valid platform
// block; the runtime React component owns the empty-state. The throwaway
// preview's job is to render the empty-state placeholder — NOT to fake
// teams/games data, NOT to invent rows.
const COPY: Record<string, string> = {
    PlatformSchedule: 'The schedule will appear here once games are added in TeamLinkt.',
    PlatformScores: 'Scores will appear here once games are reported in TeamLinkt.',
    PlatformStandings: 'Standings will appear here once games are reported in TeamLinkt.',
    PlatformRoster: 'The roster will appear here once teams are added in TeamLinkt.',
    PlatformTeams: 'Teams will appear here once they are added in TeamLinkt.',
    PlatformDivisions: 'Divisions will appear here once they are added in TeamLinkt.',
    PlatformContacts: 'Contacts will appear here once they are added in TeamLinkt.',
    PlatformCalendar: 'The calendar will appear here once events are added in TeamLinkt.',
    PlatformNews: 'News articles will appear here once they are published in TeamLinkt.',
};

const LABEL: Record<string, string> = {
    PlatformSchedule: 'Schedule',
    PlatformScores: 'Scores',
    PlatformStandings: 'Standings',
    PlatformRoster: 'Roster',
    PlatformTeams: 'Teams',
    PlatformDivisions: 'Divisions',
    PlatformContacts: 'Contacts',
    PlatformCalendar: 'Calendar',
    PlatformNews: 'News',
};

export default function PlatformBlockStub({ blockType, org_id }: Props) {
    return (
        <div className={`preview-block preview-platform preview-platform--${blockType}`}>
            <div className="preview-platform__label">{LABEL[blockType] ?? blockType}</div>
            <div className="preview-platform__copy">
                {COPY[blockType] ?? 'Platform content will appear here once data is added in TeamLinkt.'}
            </div>
            {org_id ? (
                <div className="preview-platform__meta">org_id: <code>{org_id}</code></div>
            ) : null}
        </div>
    );
}
