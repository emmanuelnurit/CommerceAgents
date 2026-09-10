<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Config;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use PHPUnit\Framework\TestCase;

class ModelCatalogFileTest extends TestCase
{
    public function testBundledCatalogIsConsistent(): void
    {
        $catalog = require __DIR__.'/../../Config/models.php';

        $this->assertSame(LlmClientFactory::PROVIDERS, array_keys($catalog), 'one catalog section per provider');

        foreach ($catalog as $provider => $section) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $section['priced_at'], $provider);
            $this->assertNotEmpty($section['models'], $provider);

            $ids = [];
            foreach ($section['models'] as $model) {
                $this->assertMatchesRegularExpression('/^[a-z0-9.\-]+$/', $model['id'], $provider);
                $this->assertNotContains($model['id'], $ids, 'duplicate '.$model['id']);
                $ids[] = $model['id'];
                $this->assertIsFloat($model['input'], $model['id']);
                $this->assertIsFloat($model['output'], $model['id']);
                $this->assertGreaterThanOrEqual(0.0, $model['input']);
                $this->assertGreaterThanOrEqual($model['input'], $model['output'], $model['id'].': output is never cheaper than input');
            }

            $this->assertContains(LlmClientFactory::DEFAULT_MODELS[$provider], $ids, 'default model of '.$provider.' must be in the catalog');
        }
    }
}
