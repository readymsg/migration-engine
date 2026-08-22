<?php

declare(strict_types=1);

namespace Tests\Feature\ContractEmitter;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the engine:contract-audit static gate. Two properties:
//   1. Clean scan of the real emitter code returns exit 0 (no
//      drift today).
//   2. A synthetic bad-prop authoring in a test fixture directory
//      returns exit 1 with the offending prop name reported.
final class ContractAuditCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/contract-audit-'.uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // Clean up the temp directory.
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*.php') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function real_emitter_code_scans_clean(): void
    {
        $exit = Artisan::call('engine:contract-audit');
        $this->assertSame(0, $exit, Artisan::output());
    }

    #[Test]
    public function bad_prop_name_authoring_returns_failure_and_names_the_key(): void
    {
        // Write a fake PHP file into a temp dir that authors an
        // invalid prop key.
        file_put_contents($this->tempDir.'/BadAuthoring.php', <<<'PHP'
<?php
class Fake {
    public function x() {
        return new \App\Data\SiteImport\Block(
            type: 'Hero',
            props: [
                'id' => 'hero-abc',
                'background_image' => 'x',  // WRONG: should be imageUrl
                'heading' => 'ok',
            ],
        );
    }
}
PHP);

        $exit = Artisan::call('engine:contract-audit', ['--path' => $this->tempDir]);
        $output = Artisan::output();
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('background_image', $output);
        $this->assertStringContainsString('Hero', $output);
    }

    #[Test]
    public function server_owned_props_are_not_reported(): void
    {
        // resolved* and formUuid are server-owned; ContractSchemaValidator
        // catches them at runtime with a specific `server_owned_prop_authored`
        // error, so contract-audit skips them to avoid double-reporting.
        file_put_contents($this->tempDir.'/ServerOwned.php', <<<'PHP'
<?php
class Fake {
    public function x() {
        return new \App\Data\SiteImport\Block(
            type: 'NewsList',
            props: [
                'id' => 'nl',
                'resolvedItems' => [1, 2, 3],
            ],
        );
    }
}
PHP);

        $exit = Artisan::call('engine:contract-audit', ['--path' => $this->tempDir]);
        $this->assertSame(0, $exit);
    }
}
