<?php

declare(strict_types=1);

namespace CommerceAgents;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

use function Symfony\Component\DependencyInjection\Loader\Configurator\expr;

class CommerceAgents extends BaseModule
{
    public const DOMAIN_NAME = 'commerceagents';

    public function postActivation(?ConnectionInterface $con = null): void
    {
        if (!self::getConfigValue('is_initialized', false)) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);
            self::setConfigValue('is_initialized', true);
        }

        $this->seedModelCatalog();
        $this->seedAgentDefinitions();
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $finder = Finder::create()->name('*.sql')->depth(0)->sortByName()->in(__DIR__.'/Config/update');
        $database = new Database($con);

        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }

        $configService = $this->getContainer()->get(Service\AgentConfigService::class);

        // Freeze first: migrateLegacySingleProviderSettings() reads getProvider(),
        // whose fallback is now Mistral — a 0.1.x install implicitly on Anthropic
        // must be pinned before that fallback is consulted.
        if (version_compare($currentVersion, '0.3.0', '<')) {
            $configService->freezeImplicitProviderBeforeMistralDefault();
        }

        if (version_compare($currentVersion, '0.2.0', '<')) {
            $configService->migrateLegacySingleProviderSettings();
        }

        $this->seedModelCatalog();
        $this->seedAgentDefinitions();
    }

    private function seedModelCatalog(): void
    {
        if (!$this->hasContainer() || !$this->getContainer()->has(Service\ModelCatalog::class)) {
            return;
        }

        $this->getContainer()->get(Service\ModelCatalog::class)->seedFromBundledCatalog();
    }

    private function seedAgentDefinitions(): void
    {
        if (!$this->hasContainer() || !$this->getContainer()->has(Service\AgentDefinitionSeeder::class)) {
            return;
        }

        $this->getContainer()->get(Service\AgentDefinitionSeeder::class)->seed();
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->instanceof(Agent\Tool\ToolInterface::class)
            ->tag('commerce_agents.tool');

        $servicesConfigurator->instanceof(StagedChange\ChangeApplierInterface::class)
            ->tag('commerce_agents.change_applier');

        $servicesConfigurator->instanceof(Channel\ChannelConnectorInterface::class)
            ->tag('commerce_agents.channel_connector');

        $servicesConfigurator->instanceof(Agent\Proactive\ProactiveScenarioResolverInterface::class)
            ->tag('commerce_agents.proactive_scenario_resolver');

        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__.'/I18n/*', __DIR__.'/Config/*', __DIR__.'/Model/*', __DIR__.'/Tests/*'])
            ->autowire(true)
            ->autoconfigure(true);

        $servicesConfigurator->alias(Tool\Shopping\Gateway\CatalogGatewayInterface::class, Service\Shopping\TheliaCatalogGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\CartGatewayInterface::class, Service\Shopping\TheliaCartGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\CategoryGatewayInterface::class, Service\Shopping\TheliaCategoryGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\OptionGatewayInterface::class, Service\Shopping\TheliaOptionGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\FeatureGatewayInterface::class, Service\Shopping\TheliaFeatureGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\OrderGatewayInterface::class, Service\Shopping\TheliaOrderGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\PolicyGatewayInterface::class, Service\Shopping\TheliaPolicyGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\CheckoutUrlProviderInterface::class, Service\Shopping\CheckoutUrlProvider::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\SitePagesGatewayInterface::class, Service\Shopping\TheliaSitePagesGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\CustomerGatewayInterface::class, Service\Shopping\TheliaCustomerGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\SiteUrlValidatorInterface::class, Service\Shopping\TheliaSiteUrlValidator::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\AdminPagesGatewayInterface::class, Service\Merchant\TheliaAdminPagesGateway::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\AnalyticsGatewayInterface::class, Service\Merchant\TheliaAnalyticsGateway::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\CatalogAdminGatewayInterface::class, Service\Merchant\TheliaCatalogAdminGateway::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\CampaignGatewayInterface::class, Service\Merchant\TheliaCampaignGateway::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\StagingGatewayInterface::class, Service\Merchant\TheliaStagingGateway::class);
        $servicesConfigurator->alias(StagedChange\StagedChangeRepositoryInterface::class, Service\Merchant\TheliaStagedChangeRepository::class);
        $servicesConfigurator->alias(Agent\Llm\LlmClientFactoryInterface::class, Agent\Llm\LlmClientFactory::class);
        $servicesConfigurator->alias(Tool\Channel\Gateway\ChannelGatewayInterface::class, Service\Channel\TheliaChannelGateway::class);

        $servicesConfigurator->set(Service\Channel\ChannelSettingsEncryptor::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$appSecret', '%kernel.secret%');

        // Reached from the module lifecycle (postActivation / update) through the container.
        $servicesConfigurator->set(Service\ModelCatalog::class)->autowire(true)->autoconfigure(true)->public();
        $servicesConfigurator->set(Service\AgentConfigService::class)->autowire(true)->autoconfigure(true)->public();
        $servicesConfigurator->set(Service\AgentDefinitionSeeder::class)->autowire(true)->autoconfigure(true)->public();

        $configServiceRef = str_replace('\\', '\\\\', Service\AgentConfigService::class);
        $servicesConfigurator->set(Tool\Shopping\AddToCartTool::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$cartEnabled', expr(\sprintf("service('%s').isCartEnabled()", $configServiceRef)));
        $servicesConfigurator->set(Tool\Shopping\PrepareCheckoutTool::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$checkoutEnabled', expr(\sprintf("service('%s').isCheckoutEnabled()", $configServiceRef)));
        $servicesConfigurator->set(Tool\Shopping\GetOrdersTool::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$ordersEnabled', expr(\sprintf("service('%s').areOrdersEnabled()", $configServiceRef)));
    }
}
