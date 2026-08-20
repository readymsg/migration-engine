<?php

declare(strict_types=1);

namespace App\Data;

// Confirmed TeamLinkt builder-bundle block vocabulary, tagged by bucket.
//
// Content bucket carries migrated data. Platform bucket blocks render live
// from TeamLinkt's own database — the engine places the block and the
// runtime component reads its own rows. Form bucket is structure only.
//
// The migration engine only assigns block TYPES here. Prop mapping is
// deliberately out of scope for the internal demo — we don't have the
// real per-block prop schemas yet, so anything beyond type assignment
// would either be a fabrication or a private guess.
enum TeamlinktBlockType: string
{
    // --- Content bucket ---------------------------------------------
    case Hero = 'Hero';
    case Text = 'Text';
    case Image = 'Image';
    case Button = 'Button';
    case Section = 'Section';
    case Grid = 'Grid';
    case Table = 'Table';
    case Spacer = 'Spacer';
    case TwoColumn = 'TwoColumn';
    case Tabs = 'Tabs';
    case Accordion = 'Accordion';
    case Slider = 'Slider';
    case CTABanner = 'CTABanner';
    case StatsCounter = 'StatsCounter';
    case FeatureGrid = 'FeatureGrid';
    case Testimonials = 'Testimonials';
    case Gallery = 'Gallery';
    case PhotosRotator = 'PhotosRotator';
    case Video = 'Video';
    case FAQ = 'FAQ';
    case ListSection = 'ListSection';
    case Locations = 'Locations';
    case Sponsors = 'Sponsors';
    case FileDownload = 'FileDownload';
    case Link = 'Link';
    case LogoLink = 'LogoLink';
    case SiteNotice = 'SiteNotice';
    case FooterColumns = 'FooterColumns';
    case FooterLogo = 'FooterLogo';
    case FooterSocial = 'FooterSocial';

    // --- Platform bucket --------------------------------------------
    case Standings = 'Standings';
    case Scores = 'Scores';
    case ScoresSchedule = 'ScoresSchedule';
    case Schedule = 'Schedule';
    case Statistics = 'Statistics';
    case Suspensions = 'Suspensions';
    case TeamRoster = 'TeamRoster';
    case TeamCard = 'TeamCard';
    case Teams = 'Teams';
    case SubOrganizations = 'SubOrganizations';
    case Executives = 'Executives';
    case TeamMembers = 'TeamMembers';
    case NewsList = 'NewsList';
    case NewsRotator = 'NewsRotator';
    case EventMarquee = 'EventMarquee';
    case Fundraisers = 'Fundraisers';

    // --- Form bucket ------------------------------------------------
    case ContactForm = 'ContactForm';
    case IntakeForm = 'IntakeForm';

    public function bucket(): TeamlinktBlockBucket
    {
        return match ($this) {
            self::Standings,
            self::Scores,
            self::ScoresSchedule,
            self::Schedule,
            self::Statistics,
            self::Suspensions,
            self::TeamRoster,
            self::TeamCard,
            self::Teams,
            self::SubOrganizations,
            self::Executives,
            self::TeamMembers,
            self::NewsList,
            self::NewsRotator,
            self::EventMarquee,
            self::Fundraisers => TeamlinktBlockBucket::Platform,

            self::ContactForm,
            self::IntakeForm => TeamlinktBlockBucket::Form,

            default => TeamlinktBlockBucket::Content,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function contentTypes(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $t) => $t->bucket() === TeamlinktBlockBucket::Content,
        ));
    }

    /**
     * @return array<int, self>
     */
    public static function platformTypes(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $t) => $t->bucket() === TeamlinktBlockBucket::Platform,
        ));
    }
}
