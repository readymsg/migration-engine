<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\OrgType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the OrgType enum's serialised values against the six the
// contract accepts — Site Import Contract Part II "Org types". A
// wrong wire value would fail ingest validation.
final class OrgTypeTest extends TestCase
{
    #[Test]
    public function enum_values_match_the_contract(): void
    {
        $expected = ['club', 'association', 'league', 'high_school', 'civic', 'multi_location'];
        $actual = array_map(fn (OrgType $t) => $t->value, OrgType::cases());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, 'OrgType enum values must EXACTLY match the six contract values');
    }
}
