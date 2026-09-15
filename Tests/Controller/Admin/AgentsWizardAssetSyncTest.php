<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Guards against MYO-346: `process_assets=0` on this environment means editing
 * templates/backOffice/default-twig/assets/**.js does NOT republish
 * public/assets/backOffice/default-twig/CommerceAgents/**.js automatically.
 * A stale published asset fails silently in the browser (no JS error).
 */
final class AgentsWizardAssetSyncTest extends TestCase
{
    private const SOURCE_DIR = __DIR__.'/../../../templates/backOffice/default-twig/assets/js';
    private const PUBLISHED_DIR = __DIR__.'/../../../../../../public/assets/backOffice/default-twig/CommerceAgents/assets/js';

    public function testPublishedAssetsMatchSource(): void
    {
        $sourceDir = realpath(self::SOURCE_DIR);
        self::assertNotFalse($sourceDir, 'Source asset directory not found: '.self::SOURCE_DIR);

        $sourceFiles = glob($sourceDir.'/*.js');
        self::assertNotEmpty($sourceFiles, 'No source JS assets found in '.$sourceDir);

        $mismatches = [];
        foreach ($sourceFiles as $sourceFile) {
            $filename = basename($sourceFile);
            $publishedFile = self::PUBLISHED_DIR.'/'.$filename;

            if (!is_file($publishedFile)) {
                $mismatches[] = "$filename: missing from published assets ($publishedFile)";
                continue;
            }

            if (hash_file('sha256', $sourceFile) !== hash_file('sha256', $publishedFile)) {
                $mismatches[] = "$filename: published asset differs from source, resync it manually (process_assets=0 on this environment)";
            }
        }

        self::assertSame([], $mismatches, implode("\n", $mismatches));
    }
}
