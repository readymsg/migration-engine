<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContractEmitter\ContractSchema;
use Illuminate\Console\Command;

// engine:contract-audit — static scan for prop-name drift.
//
// The user's Slice-17 ask verbatim:
//   "Prop-name correctness gets a validator + test gate, not just
//    care. A typo'd prop is silently stored forever — that's a
//    silent-loss channel and we treat it like every other one."
//
// The RUNTIME validator (ContractSchemaValidator) catches every
// invalid prop-key when the emitter actually runs. This STATIC
// gate scans emitter source code for Block-authoring patterns —
// so a code change that adds a typo'd prop key surfaces AT
// COMMIT TIME rather than "the first time some test happens to
// exercise that path."
//
// Detection pattern: matches
//     new Block(type: 'BlockType', props: [ ...keys... ])
// authoring sites in emitter source. Extracts prop keys and
// checks each against the contract catalogue via ContractSchema.
//
// Not a full PHP parser — targeted regex over the code the
// emitter actually uses to construct Blocks. If a future
// refactor moves Block construction outside this pattern (e.g.
// a Block::for() builder), extend the scanner.
//
// Exit code: 0 on clean scan, 1 on any violation. Meant for a
// pre-commit hook or CI step.
final class ContractAuditCommand extends Command
{
    protected $signature = 'engine:contract-audit
        {--path=app/Services/ContractEmitter : Directory to scan}
        {--strict : Fail on unknown block types too (default: only prop-key mismatches)}';

    protected $description = 'Static scan for prop-name drift against the contract catalogue.';

    public function handle(): int
    {
        $rawPath = (string) $this->option('path');
        // Absolute paths pass through; relative paths resolve
        // against the project root.
        $path = str_starts_with($rawPath, '/') ? $rawPath : base_path($rawPath);
        if (! is_dir($path)) {
            $this->error("Path not found: {$path}");

            return self::FAILURE;
        }

        $schema = ContractSchema::load();
        $violations = 0;
        $sitesChecked = 0;
        $unknownTypes = [];

        foreach ($this->phpFilesIn($path) as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            foreach ($this->extractBlockAuthorings($source) as $authoring) {
                $sitesChecked++;
                $type = $authoring['type'];
                $keys = $authoring['keys'];
                $line = $authoring['line'];
                $relPath = str_replace(base_path().'/', '', $file);

                if (! $schema->hasBlock($type)) {
                    $unknownTypes[$type] = true;
                    if ($this->option('strict')) {
                        $this->line("  ✗ Unknown block type <fg=red>{$type}</> at <fg=cyan>{$relPath}:{$line}</>");
                        $violations++;
                    }

                    continue;
                }

                $known = array_merge(
                    array_keys($schema->propProperties($type)),
                    array_keys($schema->defaults($type)),
                    ['id'], // always allowed
                );
                $unknownKeys = [];
                foreach ($keys as $key) {
                    if (in_array($key, $known, true)) {
                        continue;
                    }
                    // Server-owned props are declared elsewhere; the
                    // schema validator catches them at runtime with a
                    // specific error. Don't double-report here.
                    if (str_starts_with($key, 'resolved') || $key === 'formUuid') {
                        continue;
                    }
                    $unknownKeys[] = $key;
                }
                if ($unknownKeys !== []) {
                    $this->line(sprintf(
                        '  ✗ <fg=red>%s</> at <fg=cyan>%s:%d</> — unknown prop key(s): <fg=yellow>%s</>',
                        $type,
                        $relPath,
                        $line,
                        implode(', ', $unknownKeys),
                    ));
                    $violations += count($unknownKeys);
                }
            }
        }

        $this->line('');
        $this->line(sprintf(
            'Scanned %d Block authoring site(s) in %s.',
            $sitesChecked,
            (string) $this->option('path'),
        ));
        if ($unknownTypes !== [] && ! $this->option('strict')) {
            $this->comment(sprintf(
                'Note: %d unknown block type(s) skipped (run with --strict to fail on these): %s',
                count($unknownTypes),
                implode(', ', array_keys($unknownTypes)),
            ));
        }

        if ($violations > 0) {
            $this->error(sprintf('FAILED: %d prop-name violation(s).', $violations));

            return self::FAILURE;
        }
        $this->info('Clean — every emitted prop key matches the contract catalogue.');

        return self::SUCCESS;
    }

    /**
     * @return \Generator<string>
     */
    private function phpFilesIn(string $path): \Generator
    {
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($iter as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }

    /**
     * Extracts each `new Block(type: '<type>', props: [...])`
     * authoring site plus the prop KEY names authored in place.
     *
     * @return array<int, array{type: string, keys: array<int, string>, line: int}>
     */
    private function extractBlockAuthorings(string $source): array
    {
        $results = [];
        // Match "new Block(type: '<type>', props: [ … ]" with a
        // greedy props body that we then parse for top-level keys.
        // Only matches the emitter's canonical constructor call
        // shape; if a future refactor moves to a builder, this
        // needs updating.
        // Matches `new Block(...)` and fully-namespaced variants like
        // `new \App\Data\SiteImport\Block(...)`.
        $pattern = '/new\s+(?:\\\\[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?Block\s*\(\s*type:\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*,\s*props:\s*(\[)/';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $results;
        }
        foreach ($matches[0] as $i => $whole) {
            $type = $matches[1][$i][0];
            $offset = (int) $whole[1];
            $line = substr_count($source, "\n", 0, $offset) + 1;
            // Find the balanced `]` for the props array.
            $arrStart = $matches[2][$i][1];
            $body = $this->extractBalanced($source, (int) $arrStart);
            if ($body === null) {
                continue;
            }
            $keys = $this->extractTopLevelStringKeys($body);
            $results[] = ['type' => $type, 'keys' => $keys, 'line' => $line];
        }

        return $results;
    }

    /**
     * Given the offset of an opening `[`, return the substring
     * between it and the matching `]`, respecting nested arrays
     * and string quotes. Returns null on malformed input.
     */
    private function extractBalanced(string $source, int $bracketOffset): ?string
    {
        $len = strlen($source);
        $depth = 0;
        $inString = null; // "'" or '"'
        for ($i = $bracketOffset; $i < $len; $i++) {
            $ch = $source[$i];
            // Skip escaped chars inside strings.
            if ($inString !== null) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;

                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }

                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;

                continue;
            }
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $bracketOffset + 1, $i - $bracketOffset - 1);
                }
            }
        }

        return null;
    }

    /**
     * Extract 'key' => ... top-level entries from a props-array
     * body. Handles quoted keys, nested arrays (skipped), string
     * escapes.
     *
     * @return array<int, string>
     */
    private function extractTopLevelStringKeys(string $body): array
    {
        $keys = [];
        $len = strlen($body);
        $depth = 0;
        $inString = null;
        $atStart = true; // are we at a position that could start a key?
        $tokenStart = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inString !== null) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;

                    continue;
                }
                if ($ch === $inString) {
                    // End of string. If we were tracking a key at
                    // depth 0 (top-level), the collected content
                    // might be a prop-name.
                    if ($depth === 0 && $atStart && $tokenStart !== null) {
                        $keyLiteral = substr($body, $tokenStart + 1, $i - $tokenStart - 1);
                        // Look ahead for "=>" to confirm it's a key.
                        $rest = substr($body, $i + 1);
                        if (preg_match('/^\s*=>/', $rest) === 1) {
                            $keys[] = $keyLiteral;
                            $atStart = false;
                        }
                    }
                    $inString = null;
                    $tokenStart = null;
                }

                continue;
            }
            if ($ch === "'" || $ch === '"') {
                if ($depth === 0 && $atStart) {
                    $tokenStart = $i;
                }
                $inString = $ch;

                continue;
            }
            if ($ch === '[' || $ch === '(') {
                $depth++;

                continue;
            }
            if ($ch === ']' || $ch === ')') {
                $depth--;

                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $atStart = true;

                continue;
            }
        }

        return array_values(array_unique($keys));
    }
}
