<?php

declare(strict_types=1);

namespace CommerceAgents;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\expr;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

class CommerceAgents extends BaseModule
{
    public const DOMAIN_NAME = 'commerceagents';

    public function postActivation(ConnectionInterface $con = null): void
    {
        if (!self::getConfigValue('is_initialized', false)) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);
            self::setConfigValue('is_initialized', true);
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->instanceof(Agent\Tool\ToolInterface::class)
            ->tag('commerce_agents.tool');

        $servicesConfigurator->instanceof(StagedChange\ChangeApplierInterface::class)
            ->tag('commerce_agents.change_applier');

        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__.'/I18n/*', __DIR__.'/Config/*', __DIR__.'/Model/*', __DIR__.'/Tests/*'])
            ->autowire(true)
            ->autoconfigure(true);

        $servicesConfigurator->alias(Tool\Shopping\Gateway\CatalogGatewayInterface::class, Service\Shopping\TheliaCatalogGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\CartGatewayInterface::class, Service\Shopping\TheliaCartGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\OrderGatewayInterface::class, Service\Shopping\TheliaOrderGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\PolicyGatewayInterface::class, Service\Shopping\TheliaPolicyGateway::class);
        $servicesConfigurator->alias(Tool\Shopping\Gateway\CheckoutUrlProviderInterface::class, Service\Shopping\CheckoutUrlProvider::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\AnalyticsGatewayInterface::class, Service\Merchant\TheliaAnalyticsGateway::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\CatalogAdminGatewayInterface::class, Service\Merchant\TheliaCatalogAdminGateway::class);
        $servicesConfigurator->alias(Tool\Admin\Gateway\CampaignGatewayInterface::class, Service\Merchant\TheliaCampaignGateway::class);

        $configServiceRef = str_replace('\\', '\\\\', Service\AgentConfigService::class);
        $servicesConfigurator->set(Tool\Shopping\AddToCartTool::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$cartEnabled', expr(sprintf("service('%s').isCartEnabled()", $configServiceRef)));
        $servicesConfigurator->set(Tool\Shopping\PrepareCheckoutTool::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$checkoutEnabled', expr(sprintf("service('%s').isCheckoutEnabled()", $configServiceRef)));
        $servicesConfigurator->set(Tool\Shopping\GetOrdersTool::class)
            ->autowire(true)->autoconfigure(true)
            ->arg('$ordersEnabled', expr(sprintf("service('%s').areOrdersEnabled()", $configServiceRef)));
    }
}
