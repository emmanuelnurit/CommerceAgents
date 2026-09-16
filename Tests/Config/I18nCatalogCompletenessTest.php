<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * MYO-436: several source strings passed through trans('...', [], 'commerceagents')
 * (or the Twig |trans({}, 'commerceagents') filter) but had no entry in the
 * I18n/*.php catalogs, so Symfony fell back to the raw English source no matter
 * the active locale — even fr_FR, a locale the module otherwise fully supports.
 * A manual UX pass (MYO-430) is what caught it, not CI. This scans every call
 * site once so a forgotten key fails the test suite instead.
 */
class I18nCatalogCompletenessTest extends TestCase
{
    private const LOCALES = ['en_US', 'es_ES', 'fr_FR', 'it_IT'];

    private static function moduleRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        return require self::moduleRoot()."/I18n/{$locale}.php";
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $root = self::moduleRoot();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!preg_match('/\.(twig|php)$/', $path)) {
                continue;
            }
            if (str_contains($path, '/I18n/') || str_contains($path, '/Tests/') || str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, list<string>> source string => list of "file:line" it was found at
     */
    private static function usedKeys(): array
    {
        $found = [];
        $record = static function (string $key, string $path, int $line) use (&$found): void {
            $found[$key][] = basename($path).':'.$line;
        };

        // Twig: '...'|trans({...}, 'commerceagents') or "..."|trans(...).
        $twigPattern = '/(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*\|\s*trans\s*\([^)]*\'commerceagents\'\s*\)/s';
        // PHP: ->trans('...', [...], 'commerceagents') / CommerceAgents::DOMAIN_NAME.
        $phpTransPattern = '/trans\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")([^;]{0,300})/s';
        // CapabilityCatalog.php / TriggerCatalog.php: definitions translated indirectly via a
        // variable (->trans($definition['label'], ...)), so the literal itself never matches
        // $phpTransPattern above.
        $phpLabelPattern = '/\'label\'\s*=>\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';

        foreach (self::sourceFiles() as $path) {
            $content = (string) file_get_contents($path);

            if (str_ends_with($path, '.twig')) {
                if (preg_match_all($twigPattern, $content, $matches, \PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $i => $wholeMatch) {
                        $key = '' !== $matches[1][$i][0] ? $matches[1][$i][0] : $matches[2][$i][0];
                        $key = str_replace(["\\'", '\\"'], ["'", '"'], $key);
                        $record($key, $path, 1 + substr_count($content, "\n", 0, $wholeMatch[1]));
                    }
                }
                continue;
            }

            if (str_contains($path, 'CapabilityCatalog.php') || str_contains($path, 'TriggerCatalog.php')) {
                if (preg_match_all($phpLabelPattern, $content, $matches, \PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[1] as $m) {
                        $key = str_replace("\\'", "'", $m[0]);
                        $record($key, $path, 1 + substr_count($content, "\n", 0, $m[1]));
                    }
                }
                continue;
            }

            if (preg_match_all($phpTransPattern, $content, $matches, \PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $i => $wholeMatch) {
                    $rest = $matches[3][$i][0];
                    if (!str_contains($rest, 'commerceagents') && !str_contains($rest, 'DOMAIN_NAME')) {
                        continue;
                    }
                    $key = '' !== $matches[1][$i][0] ? $matches[1][$i][0] : $matches[2][$i][0];
                    $key = str_replace(["\\'", '\\"'], ["'", '"'], $key);
                    $record($key, $path, 1 + substr_count($content, "\n", 0, $wholeMatch[1]));
                }
            }
        }

        return $found;
    }

    public function testAllFourCatalogsShareTheSameKeys(): void
    {
        $reference = array_keys(self::catalog('fr_FR'));
        sort($reference);

        foreach (self::LOCALES as $locale) {
            $keys = array_keys(self::catalog($locale));
            sort($keys);
            $this->assertSame($reference, $keys, "I18n/{$locale}.php has a different key set than fr_FR.php");
        }
    }

    public function testEveryTranslatedSourceStringHasACatalogEntry(): void
    {
        $catalog = self::catalog('fr_FR');

        $missing = [];
        foreach (self::usedKeys() as $key => $locations) {
            if (!\array_key_exists($key, $catalog)) {
                $missing[$key] = $locations;
            }
        }

        $report = array_map(
            static fn (string $key, array $locations): string => "  - '{$key}' (".implode(', ', $locations).')',
            array_keys($missing),
            array_values($missing),
        );

        $this->assertSame(
            [],
            $missing,
            "trans() source string(s) missing from the commerceagents I18n catalogs:\n".implode("\n", $report)
        );
    }
}
