<?php

declare(strict_types=1);

namespace App\Services\Schema;

use App\Data\ComponentDefinition;
use App\Data\FieldDefinition;

// Hand-written default-Puck schema for v1. The demo's React preview bundle renders
// against THESE shapes, so generation and preview match exactly.
final class DefaultPuckComponentSchema implements ComponentSchema
{
    /** @var array<string, ComponentDefinition>|null */
    private ?array $cache = null;

    /** @var array<string, ComponentDefinition>|null */
    private ?array $platformCache = null;

    public function all(): array
    {
        return $this->cache ??= [
            'Hero' => new ComponentDefinition(
                type: 'Hero',
                fields: [
                    'heading' => new FieldDefinition(type: 'text', required: true),
                    'subheading' => new FieldDefinition(type: 'text'),
                    'background_image' => new FieldDefinition(type: 'image'),
                    'cta' => new FieldDefinition(
                        type: 'object',
                        object_fields: [
                            'label' => new FieldDefinition(type: 'text'),
                            'href' => new FieldDefinition(type: 'text'),
                        ],
                    ),
                ],
            ),
            'Heading' => new ComponentDefinition(
                type: 'Heading',
                fields: [
                    'text' => new FieldDefinition(type: 'text', required: true),
                    'level' => new FieldDefinition(
                        type: 'select',
                        required: true,
                        options: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                    ),
                ],
            ),
            'Text' => new ComponentDefinition(
                type: 'Text',
                fields: [
                    'body' => new FieldDefinition(type: 'textarea', required: true),
                    'align' => new FieldDefinition(
                        type: 'select',
                        options: ['left', 'center', 'right'],
                    ),
                ],
            ),
            'Image' => new ComponentDefinition(
                type: 'Image',
                fields: [
                    'src' => new FieldDefinition(type: 'image', required: true),
                    'alt' => new FieldDefinition(type: 'text', required: true),
                    'caption' => new FieldDefinition(type: 'text'),
                ],
            ),
            'Columns' => new ComponentDefinition(
                type: 'Columns',
                fields: [
                    'columns' => new FieldDefinition(
                        type: 'array',
                        required: true,
                        object_fields: [
                            'width' => new FieldDefinition(
                                type: 'select',
                                options: ['auto', '1/2', '1/3', '2/3', '1/4', '3/4'],
                            ),
                            'children' => new FieldDefinition(type: 'array'),
                        ],
                    ),
                ],
            ),
            'Card' => new ComponentDefinition(
                type: 'Card',
                fields: [
                    'title' => new FieldDefinition(type: 'text', required: true),
                    'body' => new FieldDefinition(type: 'textarea'),
                    'image' => new FieldDefinition(type: 'image'),
                    'href' => new FieldDefinition(type: 'text'),
                ],
            ),
            // Native gallery block. Populated deterministically by
            // GalleryFiller (post-assembly). Not currently produced by
            // block-fill directly — the block-fill agent approximates
            // galleries as Columns-of-Images because it doesn't know
            // this shape. GalleryFiller upgrades those approximations
            // in-place using the source markdown as the authority on
            // how many images belong.
            'Gallery' => new ComponentDefinition(
                type: 'Gallery',
                fields: [
                    'title' => new FieldDefinition(type: 'text'),
                    'items' => new FieldDefinition(
                        type: 'array',
                        required: true,
                        object_fields: [
                            'src' => new FieldDefinition(type: 'image', required: true),
                            'alt' => new FieldDefinition(type: 'text'),
                            'caption' => new FieldDefinition(type: 'text'),
                        ],
                    ),
                ],
            ),
            'ButtonGroup' => new ComponentDefinition(
                type: 'ButtonGroup',
                fields: [
                    'buttons' => new FieldDefinition(
                        type: 'array',
                        required: true,
                        object_fields: [
                            'label' => new FieldDefinition(type: 'text', required: true),
                            'href' => new FieldDefinition(type: 'text', required: true),
                            'variant' => new FieldDefinition(
                                type: 'select',
                                options: ['primary', 'secondary', 'ghost'],
                            ),
                        ],
                    ),
                ],
            ),
        ];
    }

    public function get(string $componentType): ?ComponentDefinition
    {
        return $this->all()[$componentType] ?? null;
    }

    public function types(): array
    {
        return array_keys($this->all());
    }

    // Platform-block definitions. v1 carries ONE prop — org_id — which the
    // runtime block uses to query TeamLinkt's own data. No team_id (v1
    // doesn't walk team subtrees), no layout (no source signal), no baked
    // data. Empty-state on day 1 is rendered by the runtime React component
    // when the org has no teams/games yet; the engine's job is just to
    // place a structurally-valid platform block.
    public function platformBlocks(): array
    {
        return $this->platformCache ??= [
            'PlatformSchedule' => $this->platformDefinition('PlatformSchedule'),
            'PlatformScores' => $this->platformDefinition('PlatformScores'),
            'PlatformStandings' => $this->platformDefinition('PlatformStandings'),
            'PlatformRoster' => $this->platformDefinition('PlatformRoster'),
            'PlatformTeams' => $this->platformDefinition('PlatformTeams'),
            'PlatformDivisions' => $this->platformDefinition('PlatformDivisions'),
            'PlatformContacts' => $this->platformDefinition('PlatformContacts'),
            'PlatformCalendar' => $this->platformDefinition('PlatformCalendar'),
            'PlatformNews' => $this->platformDefinition('PlatformNews'),
            // Singular team page (one TeamInstance → one PlatformTeam block).
            // Same runtime posture as the other platform blocks: engine emits
            // the {org_id} shell, the React component queries TeamLinkt's
            // data. Product-side rendering may not exist day-1 — accepted
            // per BUILD.md, the draft lands as-is and the product catches up.
            'PlatformTeam' => $this->platformDefinition('PlatformTeam'),
        ];
    }

    private function platformDefinition(string $type): ComponentDefinition
    {
        return new ComponentDefinition(
            type: $type,
            fields: [
                'org_id' => new FieldDefinition(type: 'text', required: true),
            ],
        );
    }
}
