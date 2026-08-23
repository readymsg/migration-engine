<?php

declare(strict_types=1);

namespace App\Data;

// Concrete TeamLinkt Puck blocks that REGENERATE content from TeamLinkt's
// own data, replacing the source SE page entirely. A page classified as
// `platform_dynamic` carries one of these on its DecisionEntry so GENERATE
// knows which block to instantiate.
//
// Registration is intentionally NOT here — it's an external link to
// TeamLinkt's secure registration URL, not a block. See PLAN registration
// handling (kept as external + retarget note).
//
// Real-vs-placeholder block resolution is a GENERATE concern, not now.
enum PlatformBlockType: string
{
    case Schedule = 'schedule';
    case Scores = 'scores';
    case Standings = 'standings';
    case Roster = 'roster';
    case Teams = 'teams';
    case Divisions = 'divisions';
    case Contacts = 'contacts';

    // Reproduces SportsEngine's Calendar + NewsNode features as TeamLinkt
    // blocks. Routed deterministically from NavNode.node_type (Calendar /
    // NewsNode), never via the LLM. The rebuilt site reads from TeamLinkt's
    // own event + news data, NOT from SE — zero live SE dependency.
    case Calendar = 'calendar';
    case News = 'news';

    // Singular team page — one TeamInstance in SE's hierarchy becomes one
    // PlatformTeam block (team overview + roster + schedule + results,
    // rendered by the runtime component from TeamLinkt's own data). Distinct
    // from ::Teams (plural directory). Routed from NavNode kind
    // 'dynamic_team' (node_type=TeamInstance).
    case Team = 'team';

    // Does a page rendered by this block SUBSUME its descendants? A subsuming
    // block represents the whole subtree — descendants are absent from the
    // rebuilt site (present in the ledger with action=Subsumed for
    // recoverability). A non-subsuming block only represents ITSELF —
    // descendants keep their own classification and their own PuckOutput.
    //
    // This is the load-bearing gate that prevents league-hierarchy content
    // loss. Historically PlatformDynamic universally subsumed; that was
    // correct for aggregate feeds (Calendar, News) and catastrophically
    // wrong for hierarchy directories (Teams, Divisions, Team) where a
    // parent LINKS to its children as distinct user destinations. If a
    // League page subsumed, cjfl's 19 team pages and langdon's divisions
    // would silently disappear.
    //
    // Rule: subsume only when the block is a self-contained aggregate feed.
    //   - Calendar : yes (one calendar carries all events; per-month
    //                sub-pages are noise).
    //   - News     : yes (one news feed carries all articles; per-month
    //                archives are noise).
    //   - Everything else : no. Schedule/Scores/Standings/Roster/Contacts
    //                are typically leaf pages anyway (empty children if
    //                any). Teams/Divisions/Team are hierarchy directories
    //                whose children are distinct user destinations. Safer
    //                default: don't swallow.
    public function subsumesDescendants(): bool
    {
        return match ($this) {
            self::Calendar, self::News => true,
            default => false,
        };
    }

    /**
     * Does this block correspond to a page that TeamLinkt renders at a
     * RESERVED ROUTE from live data (`/view/team/{id}`, `/view/game/{id}`,
     * news article permalinks, player pages)? Contract prose (v1) —
     * "Entity detail pages":
     *
     *   "Team, game, news-article and player pages already exist at
     *    their reserved routes, rendered from live TeamLinkt data.
     *    Never scrape or recreate them."
     *
     * When this returns true, PlatformBlockRenderer SKIPS the page
     * entirely — no PuckOutput, no page shell, no near-empty page in
     * the payload. The skip surfaces as an info diagnostic naming
     * `/view/{entity}/{id}` as the destination.
     *
     * Today: only Team returns true. TeamInstance nodes are what SE
     * exposes as per-team subpages; TeamLinkt owns the equivalent
     * pages via `/view/team/{id}` reserved routes. Other cases
     * (game pages, news articles, player pages) aren't in the nav
     * tree we walk today — SE surfaces them via news feed URLs and
     * schedule links, not as NavNode entries. Add cases here if a
     * future PLAN slice starts classifying them.
     */
    public function isReservedRoutePage(): bool
    {
        return match ($this) {
            self::Team => true,
            default => false,
        };
    }
}
