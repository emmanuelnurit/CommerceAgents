<?php

declare(strict_types=1);

namespace CommerceAgents\Command;

use Comment\Model\Comment;
use Comment\Model\CommentQuery;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\ModuleAvailabilityInterface;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\TriggerCatalogMapping;
use CommerceAgents\Service\TriggerCatalog;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Condition\ConditionCollection;
use Thelia\Condition\ConditionFactory;
use Thelia\Condition\Implementation\MatchForTotalAmount;
use Thelia\Condition\Operators;
use Thelia\Domain\Promotion\Coupon\FacadeInterface as CouponFacadeInterface;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\AdminQuery;
use Thelia\Model\Cart;
use Thelia\Model\Coupon;
use Thelia\Model\CouponQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddress;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\SaleOffsetCurrency;
use Thelia\Model\SaleProductQuery;
use Thelia\Model\SaleQuery;
use Thelia\Module\BaseModule;
use Thelia\Tools\URL;
use Symfony\Component\Routing\RouterInterface;

/**
 * Populates the CommerceAgents demo environment (MYO-468) on top of a fresh
 * `bin/install --with-demo`: credible product reviews (AC3), a low-stock
 * active promo campaign with a sales-velocity trail (AC4), and a queue of
 * already-approved-quality staged changes (AC6) so the client demo never
 * depends on a live LLM call. Everything here is additive to the core
 * `thelia:demo:import` catalog/customers/orders — never re-implements them
 * (orders blocked >24h/>48h already come out of core's OrdersImporter, see
 * docs/demo-2026-09-22.md).
 *
 * Idempotent: every insertion is guarded so re-running this command against
 * the same database (e.g. after a fresh `safe-install.sh --with-demo`) never
 * duplicates rows.
 */
#[AsCommand(
    name: 'commerce-agents:demo-seed',
    description: 'Seeds credible demo data for CommerceAgents: reviews, a low-stock promo campaign, and pending staged changes (MYO-468)',
)]
final class DemoSeedCommand extends Command
{
    private const HERO_PRODUCT_REF = 'PROD006';
    private const HERO_STOCK = 4.0;
    private const HERO_RESTOCK_QTY = 40.0;
    private const VELOCITY_WEEKS = 4;
    private const VELOCITY_UNITS_PER_WEEK = 6;
    private const REVIEW_ORDER_STATUS = OrderStatus::CODE_SENT;
    private const VELOCITY_CART_TOKEN_PREFIX = 'demo-seed-velocity-';
    private const RUN_DEDUP_KEY = 'myo468_demo_seed';

    private const WELCOME_COUPON_CODE = 'WELCOME10';
    private const WELCOME_COUPON_MIN_AMOUNT = 25.0;
    private const TIER_COUPONS = [
        ['code' => 'PALIER50', 'percentage' => 10, 'threshold' => 50.0, 'titleFr' => 'Palier fidélité : -10% dès 50€', 'titleEn' => 'Loyalty tier: -10% from €50'],
        ['code' => 'PALIER90', 'percentage' => 15, 'threshold' => 90.0, 'titleFr' => 'Palier fidélité : -15% dès 90€', 'titleEn' => 'Loyalty tier: -15% from €90'],
    ];

    public function __construct(
        private readonly ModuleAvailabilityInterface $moduleAvailability,
        private readonly AgentDefinitionManager $agentManager,
        private readonly ConversationService $conversationService,
        private readonly StagingGatewayInterface $stagingGateway,
        private readonly RouterInterface $router,
        private readonly ConditionFactory $conditionFactory,
        private readonly CouponFacadeInterface $couponFacade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('admin-login', null, InputOption::VALUE_REQUIRED, 'Login of the administrator credited as the author of the seeded proposals', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // CLI-only boot (like DemoImportCommand): Sale/RewritingUrlTrait
        // calls URL::getInstance() on save, which is only lazily initialized
        // by TheliaHttpKernel on a real HTTP request.
        new URL($this->router);

        if (!$this->moduleAvailability->isActive('CommerceAgents')) {
            $output->writeln('<comment>CommerceAgents is not active — nothing to seed.</comment>');

            return Command::SUCCESS;
        }
        if (!$this->moduleAvailability->isActive('Comment')) {
            $output->writeln('<comment>Comment module is not active — reviews cannot be seeded.</comment>');

            return Command::SUCCESS;
        }

        $adminId = AdminQuery::create()->findOneByLogin((string) $input->getOption('admin-login'))?->getId();

        $reviewCount = $this->seedReviews($output);
        [$heroProduct, $heroPse] = $this->seedHeroCampaign($output);
        $stagedCount = $this->seedStagedChanges($output, $adminId, $heroPse);
        $this->seedCouponScenarios($output);

        $output->writeln('');
        $output->writeln(\sprintf(
            '<info>Demo seed complete:</info> %d review(s) present, hero product "%s" at %.0f unit(s) with an active campaign, %d pending staged change(s).',
            $reviewCount,
            $heroProduct?->setLocale('fr_FR')->getTitle() ?? self::HERO_PRODUCT_REF,
            $heroPse?->getQuantity() ?? 0.0,
            $stagedCount,
        ));

        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------
    // AC3 — product reviews
    // ------------------------------------------------------------------

    /**
     * @return int total number of "product" reviews present after seeding
     */
    private function seedReviews(OutputInterface $output): int
    {
        // Excludes this command's own velocity-history orders (seeded later
        // in the same run, or by a previous run): without this, re-running
        // the command changes which "sent" order is picked as soon as
        // seedHeroCampaign() has added more recent "sent" orders than the
        // original pick, breaking idempotency (a different customer+product
        // pair, so a second review gets created instead of none).
        $velocityCartIds = \Thelia\Model\CartQuery::create()
            ->filterByToken(self::VELOCITY_CART_TOKEN_PREFIX.'%', Criteria::LIKE)
            ->select('Id')
            ->find()
            ->getData();

        $lateOrderQuery = OrderQuery::create()
            ->useOrderStatusQuery()->filterByCode(self::REVIEW_ORDER_STATUS)->endUse()
            ->filterByCustomerId(1, Criteria::NOT_EQUAL)
            ->filterByCreatedAt(new \DateTime('-9 days'), Criteria::LESS_THAN)
            ->orderByCreatedAt(Criteria::DESC);
        if ($velocityCartIds !== []) {
            $lateOrderQuery->filterByCartId($velocityCartIds, Criteria::NOT_IN);
        }
        $lateOrder = $lateOrderQuery->findOne();
        $lateOrderProduct = $lateOrder !== null
            ? \Thelia\Model\OrderProductQuery::create()->filterByOrderId($lateOrder->getId())->findOne()
            : null;
        $lateProduct = $lateOrderProduct !== null
            ? ProductQuery::create()->filterByRef($lateOrderProduct->getProductRef())->findOne()
            : null;

        $reviews = [];
        if ($lateOrder !== null && $lateProduct !== null) {
            $deliveredAt = (clone $lateOrder->getCreatedAt())->modify('+9 days');
            $reviews[] = [
                'customer' => $lateOrder->getCustomer(),
                'product' => $lateProduct,
                'rating' => 2,
                'title' => 'Livraison très en retard',
                'content' => \sprintf(
                    "Le produit est correct une fois reçu, mais la livraison de ma commande %s a pris presque deux semaines au lieu des 3-5 jours annoncés, sans aucune notification entre-temps. C'est le point à corriger, le reste du service est sérieux.",
                    $lateOrder->getRef(),
                ),
                'createdAt' => $deliveredAt,
            ];
        } else {
            $output->writeln('<comment>No eligible "sent" order found to correlate the 2-star review — skipping it.</comment>');
        }

        $reviews = array_merge($reviews, [
            [
                'customerEmail' => 'sofia.rossi@example.com',
                'productRef' => 'PROD003',
                'rating' => 5,
                'title' => 'Exactement ce qu\'il me fallait',
                'content' => "Assise très confortable, le tissu a l'air solide et les coloris sont fidèles aux photos. Montage en 15 minutes sans outils particuliers. Je recommande sans hésiter.",
                'daysAgo' => 6,
            ],
            [
                'customerEmail' => 'mohamed.diallo@example.com',
                'productRef' => self::HERO_PRODUCT_REF,
                'rating' => 4,
                'title' => 'Très beau canapé, dépêchez-vous',
                'content' => "Superbe rapport qualité/prix, le tissu déhoussable est un vrai plus avec des enfants à la maison. Il ne restait presque plus de stock quand j'ai commandé, je comprends pourquoi.",
                'daysAgo' => 3,
            ],
            [
                'customerEmail' => 'kenji.tanaka@example.com',
                'productRef' => 'PROD010',
                'rating' => 3,
                'title' => 'Bien mais notice perfectible',
                'content' => "Le canapé est agréable et bien fini, mais la notice de montage manque clairement d'étapes intermédiaires. Il m'a fallu regarder une vidéo en ligne pour comprendre l'assemblage des accoudoirs.",
                'daysAgo' => 12,
            ],
            [
                'customerEmail' => 'greta.muller@example.com',
                'productRef' => 'PROD001',
                'rating' => 5,
                'title' => 'Un coup de cœur',
                'content' => "Design réussi, très stable, et le SAV a répondu en quelques heures à une question avant achat. Livraison rapide en plus. Rien à redire.",
                'daysAgo' => 8,
            ],
            [
                'customerEmail' => 'carla.garcia@example.com',
                'productRef' => 'PROD014',
                'rating' => 4,
                'title' => 'Très satisfaite',
                'content' => "Belle qualité de fabrication. Seul bémol, le carton d'emballage était un peu abîmé à la réception (le produit n'a heureusement rien eu).",
                'daysAgo' => 4,
            ],
        ]);

        $created = 0;
        foreach ($reviews as $review) {
            $customer = $review['customer'] ?? CustomerQuery::create()->findOneByEmail($review['customerEmail']);
            $product = $review['product'] ?? ProductQuery::create()->filterByRef($review['productRef'])->findOne();
            if ($customer === null || $product === null) {
                $output->writeln(\sprintf('<comment>Skipping a review: customer or product not found.</comment>'));
                continue;
            }

            if (CommentQuery::create()->filterByRef('product')->filterByRefId($product->getId())->filterByCustomerId($customer->getId())->exists()) {
                continue;
            }

            $createdAt = $review['createdAt'] ?? new \DateTime(\sprintf('-%d days', $review['daysAgo']));

            (new Comment())
                ->setRef('product')
                ->setRefId($product->getId())
                ->setCustomerId($customer->getId())
                ->setUsername($customer->getFirstname().' '.mb_substr((string) $customer->getLastname(), 0, 1).'.')
                ->setEmail((string) $customer->getEmail())
                ->setTitle($review['title'])
                ->setContent($review['content'])
                ->setRating($review['rating'])
                ->setStatus(Comment::ACCEPTED)
                ->setVerified(1)
                ->setAbuse(0)
                ->setLocale('fr_FR')
                ->setCreatedAt($createdAt)
                ->save();
            ++$created;
        }

        $output->writeln(\sprintf('Reviews: %d newly created.', $created));

        return CommentQuery::create()->filterByRef('product')->count();
    }

    // ------------------------------------------------------------------
    // AC4 — low-stock active promo campaign + sales velocity trail
    // ------------------------------------------------------------------

    /**
     * @return array{0: ?Product, 1: ?ProductSaleElements}
     */
    private function seedHeroCampaign(OutputInterface $output): array
    {
        $product = ProductQuery::create()->filterByRef(self::HERO_PRODUCT_REF)->findOne();
        if ($product === null) {
            $output->writeln(\sprintf('<comment>Hero product %s not found — skipping the promo campaign.</comment>', self::HERO_PRODUCT_REF));

            return [null, null];
        }

        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(1)->findOne()
            ?? ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        if ($pse === null) {
            return [$product, null];
        }

        $pse->setQuantity(self::HERO_STOCK)->setPromo(1)->save();

        $currencies = CurrencyQuery::create()->find();
        foreach ($currencies as $currency) {
            $price = ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->filterByCurrencyId($currency->getId())->findOne();
            if ($price !== null) {
                $price->setPromoPrice((string) round((float) $price->getPrice() * 0.85, 2))->save();
            }
        }

        $this->ensureDedicatedSale($output, $product);
        $this->ensureVelocityHistory($output, $product, $pse);

        $output->writeln(\sprintf(
            'Hero campaign: "%s" (ref %s) at %.0f unit(s) left, -15%% promo active.',
            $product->setLocale('fr_FR')->getTitle(),
            self::HERO_PRODUCT_REF,
            self::HERO_STOCK,
        ));

        return [$product, $pse];
    }

    private function ensureDedicatedSale(OutputInterface $output, Product $product): void
    {
        foreach (SaleProductQuery::create()->filterByProductId($product->getId())->find() as $link) {
            if (SaleProductQuery::create()->filterBySaleId($link->getSaleId())->count() === 1) {
                // A sale exclusively covering this product already exists (a
                // previous seed run) — nothing to do.
                return;
            }
        }

        $sale = (new \Thelia\Model\Sale())
            ->setActive(1)
            ->setStartDate((new \DateTime())->modify('-2 days'))
            ->setEndDate((new \DateTime())->modify('+5 days'))
            ->setPriceOffsetType(20)
            ->setDisplayInitialPrice(true)
            ->setLocale('fr_FR')->setTitle('Semaine promo — '.$product->setLocale('fr_FR')->getTitle())
                ->setChapo('Offre limitée, stock restreint.')
                ->setDescription('Campagne de démonstration : promotion active sur un produit à faible stock.')
            ->setLocale('en_US')->setTitle('Promo week — '.$product->setLocale('en_US')->getTitle())
                ->setChapo('Limited offer, low stock.')
                ->setDescription('Demo campaign: active promotion on a low-stock product.');
        $sale->save();

        foreach (CurrencyQuery::create()->find() as $currency) {
            (new SaleOffsetCurrency())
                ->setCurrencyId($currency->getId())
                ->setSaleId($sale->getId())
                ->setPriceOffsetValue(15.0)
                ->save();
        }

        (new \Thelia\Model\SaleProduct())
            ->setSaleId($sale->getId())
            ->setProductId($product->getId())
            ->setAttributeAvId(null)
            ->save();

        $output->writeln('Dedicated sale campaign created for the hero product.');
    }

    private function ensureVelocityHistory(OutputInterface $output, Product $product, ProductSaleElements $pse): void
    {
        if (\Thelia\Model\CartQuery::create()->filterByToken(self::VELOCITY_CART_TOKEN_PREFIX.'%', Criteria::LIKE)->exists()) {
            return;
        }

        $payment = $this->resolveModule(BaseModule::PAYMENT_MODULE_TYPE, 'Cheque');
        $delivery = $this->resolveModule(BaseModule::DELIVERY_MODULE_TYPE, 'CustomDelivery');
        $currency = CurrencyQuery::create()->filterByByDefault(1)->findOne() ?? CurrencyQuery::create()->findOne();
        $lang = LangQuery::create()->filterByByDefault(1)->findOne() ?? LangQuery::create()->findOne();
        $price = $currency !== null ? ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->filterByCurrencyId($currency->getId())->findOne() : null;
        $customers = CustomerQuery::create()->orderById()->find()->getData();

        if ($payment === null || $delivery === null || $currency === null || $lang === null || $price === null || $customers === []) {
            $output->writeln('<comment>Missing module/currency/lang/price/customer — skipping the sales velocity history.</comment>');

            return;
        }

        $customerCount = \count($customers);
        $orderIndex = 0;
        $created = 0;
        // 3 orders/week x 2 units = 6 units/week, oldest week first so the
        // trend reads as "steady ~6 sales/week" rather than a one-off spike.
        for ($week = self::VELOCITY_WEEKS; $week >= 1; --$week) {
            foreach ([2, 5, 8] as $dayOffsetInWeek) {
                $daysAgo = ($week - 1) * 7 + $dayOffsetInWeek;
                $customer = $customers[$orderIndex % $customerCount];
                $statusCode = $week === 1 ? OrderStatus::CODE_PAID : OrderStatus::CODE_SENT;
                $createdAt = (new \DateTime())->modify(\sprintf('-%d days', $daysAgo))->setTime(11, ($orderIndex * 17) % 60);

                $order = $this->createVelocityOrder($customer, $statusCode, $payment, $delivery, $currency, $lang, $createdAt, $orderIndex);
                $this->addVelocityOrderProduct($order, $product, $pse, $price, 2);
                ++$orderIndex;
                ++$created;
            }
        }

        $output->writeln(\sprintf('Sales velocity history: %d order(s) created (~%d units/week over %d weeks).', $created, self::VELOCITY_UNITS_PER_WEEK, self::VELOCITY_WEEKS));
    }

    private function createVelocityOrder(Customer $customer, string $statusCode, Module $payment, Module $delivery, Currency $currency, Lang $lang, \DateTime $createdAt, int $index): Order
    {
        $status = OrderStatusQuery::create()->filterByCode($statusCode)->findOne();
        $address = AddressQuery::create()->filterByCustomerId($customer->getId())->filterByIsDefault(1)->findOne()
            ?? AddressQuery::create()->filterByCustomerId($customer->getId())->findOne();

        $cart = new Cart();
        $cart->setCustomerId((int) $customer->getId());
        $cart->setCurrencyId((int) $currency->getId());
        $cart->setToken(self::VELOCITY_CART_TOKEN_PREFIX.$customer->getId().'-'.$index);
        $cart->save();

        $order = new Order();
        $order->setCustomer($customer);
        $order->setInvoiceOrderAddressId($this->createOrderAddress($customer, $address)->getId());
        $order->setDeliveryOrderAddressId($this->createOrderAddress($customer, $address)->getId());
        $order->setCurrencyId((int) $currency->getId());
        $order->setCurrencyRate(1.0);
        $order->setPaymentModuleId((int) $payment->getId());
        $order->setDeliveryModuleId((int) $delivery->getId());
        $order->setStatusId((int) $status->getId());
        $order->setLangId((int) $lang->getId());
        $order->setCartId((int) $cart->getId());
        $order->setPostage('0');
        $order->setPostageTax('0');
        $order->setCreatedAt($createdAt);
        if (\in_array($statusCode, [OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING, OrderStatus::CODE_SENT], true)) {
            $order->setInvoiceDate($createdAt);
        }
        $order->save();

        return $order;
    }

    private function createOrderAddress(Customer $customer, ?Address $address): OrderAddress
    {
        $orderAddress = new OrderAddress();
        $orderAddress->setCustomerTitleId($address?->getTitleId() ?? $customer->getTitleId());
        $orderAddress->setFirstname((string) $customer->getFirstname());
        $orderAddress->setLastname((string) $customer->getLastname());
        $orderAddress->setAddress1($address?->getAddress1() ?? '');
        $orderAddress->setAddress2('');
        $orderAddress->setAddress3('');
        $orderAddress->setZipcode($address?->getZipcode() ?? '');
        $orderAddress->setCity($address?->getCity() ?? '');
        $orderAddress->setCountryId($address?->getCountryId() ?? 64);
        $orderAddress->save();

        return $orderAddress;
    }

    private function addVelocityOrderProduct(Order $order, Product $product, ProductSaleElements $pse, \Thelia\Model\ProductPrice $price, int $quantity): void
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setOrderId((int) $order->getId());
        $orderProduct->setProductRef((string) $product->getRef());
        $orderProduct->setProductSaleElementsRef((string) $pse->getRef());
        $orderProduct->setProductSaleElementsId($pse->getId());
        $orderProduct->setTitle($product->setLocale('en_US')->getTitle());
        $orderProduct->setQuantity((float) $quantity);
        $orderProduct->setPrice($price->getPrice());
        $orderProduct->setPromoPrice('0');
        $orderProduct->setWasNew(0);
        $orderProduct->setWasInPromo(0);
        $orderProduct->save();

        $taxAmount = round((float) $price->getPrice() * 0.20, 2);
        (new OrderProductTax())
            ->setOrderProductId((int) $orderProduct->getId())
            ->setTitle('VAT 20%')
            ->setAmount((string) $taxAmount)
            ->setPromoAmount('0')
            ->save();
    }

    private function resolveModule(int $type, string $preferredCode): ?Module
    {
        return ModuleQuery::create()->filterByActivate(1)->filterByType($type)->filterByCode($preferredCode)->findOne()
            ?? ModuleQuery::create()->filterByActivate(1)->filterByType($type)->orderByPosition()->findOne();
    }

    // ------------------------------------------------------------------
    // AC5 documentation helper — AC6: agents + pending staged changes
    // ------------------------------------------------------------------

    private function seedStagedChanges(OutputInterface $output, ?int $adminId, ?ProductSaleElements $heroPse): int
    {
        $reviewsAgent = $this->ensurePresetAgent(AgentPresets::CUSTOMER_REVIEWS_REPLY);
        $stockAgent = $this->ensurePresetAgent(AgentPresets::STOCK_WATCH_RESTOCK);
        $blockedOrdersAgent = $this->ensureBlockedOrdersAgent();

        $created = 0;

        if ($reviewsAgent !== null) {
            $reviewTargets = $this->selectReviewTargets(3);
            $ctx = $this->contextFor($reviewsAgent, $adminId, 'reviews');
            foreach ($reviewTargets as $comment) {
                if ($this->hasPendingChange('review_reply', $comment->getId())) {
                    continue;
                }
                $reply = $this->draftReviewReply($comment);
                $result = $this->stagingGateway->stageReviewReply($comment->getId(), $reply, $ctx);
                if (!isset($result['error'])) {
                    ++$created;
                }
            }
            $this->ensureSeedRun($reviewsAgent, $ctx, 'Démo — brouillons de réponses aux avis clients générés');
        }

        if ($stockAgent !== null && $heroPse !== null) {
            $ctx = $this->contextFor($stockAgent, $adminId, 'stock');
            if (!$this->hasPendingChange('pse_stock', $heroPse->getId())) {
                $result = $this->stagingGateway->stageStockUpdate($heroPse->getId(), self::HERO_RESTOCK_QTY, $ctx);
                if (!isset($result['error'])) {
                    ++$created;
                }
            }
            $this->ensureSeedRun($stockAgent, $ctx, 'Démo — proposition de réassort sur produit en rupture imminente');
        }

        if ($blockedOrdersAgent !== null) {
            $ctx = $this->contextFor($blockedOrdersAgent, $adminId, 'blocked_orders');

            $notPaidOrder = OrderQuery::create()
                ->useOrderStatusQuery()->filterByCode(OrderStatus::CODE_NOT_PAID)->endUse()
                ->filterByCreatedAt(new \DateTime('-24 hours'), Criteria::LESS_THAN)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();
            if ($notPaidOrder !== null && !$this->hasPendingChange('order_coupon', $notPaidOrder->getId())) {
                $result = $this->stagingGateway->stageCouponApplication($notPaidOrder->getId(), 'THANKYOU5', $ctx);
                if (!isset($result['error'])) {
                    ++$created;
                }
            }

            $processingOrder = OrderQuery::create()
                ->useOrderStatusQuery()->filterByCode(OrderStatus::CODE_PROCESSING)->endUse()
                ->filterByCreatedAt(new \DateTime('-48 hours'), Criteria::LESS_THAN)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();
            if ($processingOrder !== null && !$this->hasPendingChange('customer_email', $processingOrder->getCustomerId())) {
                $customer = $processingOrder->getCustomer();
                $body = \sprintf(
                    "Bonjour %s,\n\nVotre commande %s n'a pas encore été expédiée alors qu'elle a été passée il y a plus de 48 heures. "
                    ."Nous préparons votre colis en priorité et vous tiendrons informé(e) dès son départ. "
                    ."Toutes nos excuses pour ce délai.\n\nL'équipe boutique",
                    $customer->getFirstname(),
                    $processingOrder->getRef(),
                );
                $result = $this->stagingGateway->stageCustomerEmail(
                    (int) $customer->getId(),
                    (string) $customer->getEmail(),
                    \sprintf('Votre commande %s est en cours de préparation', $processingOrder->getRef()),
                    $body,
                    $ctx,
                );
                if (!isset($result['error'])) {
                    ++$created;
                }
            }

            $this->ensureSeedRun($blockedOrdersAgent, $ctx, 'Démo — alertes commandes bloquées (paiement en attente / expédition en retard)');
        }

        $output->writeln(\sprintf('Staged changes: %d newly created.', $created));

        return AgentStagedChangeQuery::create()->filterByStatus(StagedChangeData::STATUS_PENDING)->count();
    }

    /**
     * MYO-502 AC-b: a plain "3 most recent" pick can miss the low-rated
     * review entirely once enough newer 4/5-star reviews exist (exactly
     * what happened on `myo468_demo` — the only <=2-star review, the one
     * the demo script narrates, had no proposal in the queue). Negative
     * reviews are reserved a slot first — that's the one Acte 3 needs a
     * `review_reply` for — the rest of the quota is filled by recency as
     * before.
     *
     * @return list<Comment>
     */
    private function selectReviewTargets(int $limit): array
    {
        $negative = iterator_to_array(
            CommentQuery::create()
                ->filterByRef('product')
                ->filterByStatus(Comment::ACCEPTED)
                ->filterByRating(2, Criteria::LESS_EQUAL)
                ->orderByCreatedAt(Criteria::DESC)
                ->find(),
            false,
        );

        $remainingSlots = max(0, $limit - \count($negative));
        if ($remainingSlots === 0) {
            return $negative;
        }

        $othersQuery = CommentQuery::create()
            ->filterByRef('product')
            ->filterByStatus(Comment::ACCEPTED)
            ->orderByCreatedAt(Criteria::DESC)
            ->limit($remainingSlots);
        if ($negative !== []) {
            $othersQuery->filterById(array_map(static fn (Comment $c): int => $c->getId(), $negative), Criteria::NOT_IN);
        }

        return array_merge($negative, iterator_to_array($othersQuery->find(), false));
    }

    private function draftReviewReply(Comment $comment): string
    {
        if ((int) $comment->getRating() <= 2) {
            return "Bonjour, et merci pour votre retour honnête. Nous sommes désolés pour le délai de livraison : ce n'est pas le niveau de service que nous visons. "
                ."Nous avons transmis votre remarque à notre transporteur et restons à votre disposition si besoin. Excellente journée à vous.";
        }

        return "Merci beaucoup pour ce retour, cela nous fait très plaisir ! Nous transmettons vos compliments à l'équipe. "
            ."N'hésitez pas à revenir vers nous si vous avez la moindre question. À très bientôt !";
    }

    private function ensurePresetAgent(string $presetCode): ?\CommerceAgents\Model\AgentDefinition
    {
        $existing = \CommerceAgents\Model\AgentDefinitionQuery::create()->filterByPresetCode($presetCode)->findOne();
        if ($existing !== null) {
            return $existing;
        }

        $preset = AgentPresets::find($presetCode);
        if ($preset === null) {
            return null;
        }
        if ($preset['requiresModule'] !== null && !$this->moduleAvailability->isActive($preset['requiresModule'])) {
            return null;
        }

        return $this->agentManager->save(null, [
            'title' => $preset['title'],
            'description' => $preset['subtitle'],
            'rolePrompt' => $preset['rolePrompt'],
            'presetCode' => $presetCode,
            'model' => '',
            'provider' => null,
            'monthlyBudgetUsd' => null,
            'enabled' => true,
            'capabilities' => $preset['capabilities'],
            'triggers' => array_map(fn (array $trigger): array => $this->technicalTrigger($trigger), $preset['triggers']),
            'channels' => $preset['channel'] !== null ? [['connectorCode' => $preset['channel'], 'enabled' => true]] : [],
        ]);
    }

    /**
     * No preset covers "blocked orders" (plan MYO-467 §3 case C2) yet — built
     * directly, like AgentPresets::FROM_SCRATCH, idempotent on exact title
     * match since a freshly created definition has no presetCode to key on.
     */
    private function ensureBlockedOrdersAgent(): ?\CommerceAgents\Model\AgentDefinition
    {
        $title = 'Suivi des commandes bloquées';
        $existing = \CommerceAgents\Model\AgentDefinitionQuery::create()->filterByTitle($title)->findOne();
        if ($existing !== null) {
            return $existing;
        }

        return $this->agentManager->save(null, [
            'title' => $title,
            'description' => 'Repère les paiements en attente depuis plus de 24h et les commandes non expédiées depuis plus de 48h, et propose un geste commercial ou un e-mail explicatif',
            'rolePrompt' => 'Tu surveilles les commandes bloquées de la boutique : paiement en attente depuis plus de 24 heures, ou commande payée mais non expédiée depuis plus de 48 heures. '
                .'Pour un paiement en attente, propose un geste commercial raisonnable (ex. code de bienvenue) pour encourager le règlement. '
                ."Pour une expédition en retard, rédige un e-mail bref et honnête d'excuse au client, sans promettre de date que tu ne peux pas garantir. "
                .'Tu ne modifies jamais une commande toi-même : chaque proposition attend une validation humaine dans les changements proposés.',
            'presetCode' => null,
            'model' => '',
            'provider' => null,
            'monthlyBudgetUsd' => null,
            'enabled' => true,
            'capabilities' => [\CommerceAgents\Agent\Tool\Capability::ORDERS_READ, \CommerceAgents\Agent\Tool\Capability::ORDERS_WRITE, \CommerceAgents\Agent\Tool\Capability::CUSTOMER_PROFILE_READ],
            'triggers' => [['type' => AgentTriggerType::CRON, 'eventName' => null, 'cronExpression' => '0 8 * * *', 'conditions' => null]],
            'channels' => [],
        ]);
    }

    /**
     * @param array{type: string, hours?: int, time?: string, threshold?: int} $trigger
     *
     * @return array{type: string, cronExpression: ?string, eventName: ?string, conditions: ?array<string, mixed>}
     */
    private function technicalTrigger(array $trigger): array
    {
        if ($trigger['type'] === TriggerCatalog::SCHEDULE) {
            $time = $trigger['time'] ?? '09:00';
            [$hour, $minute] = array_map('intval', explode(':', $time) + [0, 0]);

            return ['type' => AgentTriggerType::CRON, 'eventName' => null, 'cronExpression' => \sprintf('%d %d * * *', $minute, $hour), 'conditions' => null];
        }

        $conditions = match (true) {
            isset($trigger['hours']) => ['delay_hours' => $trigger['hours']],
            isset($trigger['threshold']) => ['threshold' => $trigger['threshold']],
            default => null,
        };

        return TriggerCatalogMapping::technicalFor($trigger['type']) + ['conditions' => $conditions];
    }

    private function contextFor(\CommerceAgents\Model\AgentDefinition $definition, ?int $adminId, string $suffix): ToolContext
    {
        $conversation = $this->conversationService->getOrCreate('agent', 'demo_seed:'.$suffix, null, 'fr_FR', $adminId);

        return new ToolContext(
            isAdmin: true,
            adminId: $adminId,
            conversationId: $conversation->getId(),
            sessionId: 'demo_seed:'.$suffix,
            locale: 'fr_FR',
            agentDefinitionId: $definition->getId(),
            channel: ToolContext::CHANNEL_RUN,
        );
    }

    private function hasPendingChange(string $targetType, int $targetId): bool
    {
        return AgentStagedChangeQuery::create()
            ->filterByTargetType($targetType)
            ->filterByTargetId($targetId)
            ->filterByStatus(StagedChangeData::STATUS_PENDING)
            ->exists();
    }

    private function ensureSeedRun(\CommerceAgents\Model\AgentDefinition $definition, ToolContext $ctx, string $summary): void
    {
        if (AgentRunQuery::create()->filterByAgentDefinitionId($definition->getId())->filterByDedupKey(self::RUN_DEDUP_KEY)->exists()) {
            return;
        }

        $now = new \DateTime();
        (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setConversationId($ctx->conversationId)
            ->setStatus(AgentRunQueue::STATUS_DONE)
            ->setDedupKey(self::RUN_DEDUP_KEY)
            ->setContext(json_encode(['seed' => 'myo468'], \JSON_THROW_ON_ERROR))
            ->setSummary($summary)
            ->setStartedAt($now)
            ->setFinishedAt($now)
            ->save();
    }

    // ------------------------------------------------------------------
    // Opt-in visitor scenarios (board request on MYO-467, 2026-09-21 evening):
    // WelcomeCouponScenarioResolver / CartCouponScenarioResolver read real
    // Coupon rows — nothing to build here, only credible data. The core
    // demo (`thelia:demo:import` -> CouponsImporter) already creates
    // WELCOME10 with a "welcome" keyword in its title, but with an empty
    // condition collection (no minimum-amount condition); the board asked
    // for one explicitly. The 2 amount-tier coupons it also asked for do
    // not exist anywhere in the core demo data.
    // ------------------------------------------------------------------

    private function seedCouponScenarios(OutputInterface $output): void
    {
        $currencyCode = CurrencyQuery::create()->filterByByDefault(1)->findOne()?->getCode() ?? 'EUR';

        $welcome = CouponQuery::create()->filterByCode(self::WELCOME_COUPON_CODE)->findOne();
        if ($welcome !== null) {
            $welcome->setSerializedConditions($this->amountConditions(self::WELCOME_COUPON_MIN_AMOUNT, $currencyCode))->save();
            $output->writeln(\sprintf('Welcome coupon "%s": minimum-amount condition set (>= %.0f%s).', self::WELCOME_COUPON_CODE, self::WELCOME_COUPON_MIN_AMOUNT, $currencyCode));
        } else {
            $output->writeln(\sprintf('<comment>Coupon %s not found (was "thelia:demo:import" run with --with-demo?) — welcome scenario left unconfigured.</comment>', self::WELCOME_COUPON_CODE));
        }

        $created = 0;
        foreach (self::TIER_COUPONS as $tier) {
            if (CouponQuery::create()->filterByCode($tier['code'])->exists()) {
                continue;
            }

            $coupon = new Coupon();
            $coupon->setCode($tier['code']);
            $coupon->setType('thelia.coupon.type.remove_x_percent');
            $coupon->setSerializedEffects(json_encode(['percentage' => $tier['percentage']], \JSON_THROW_ON_ERROR));
            $coupon->setIsEnabled(true);
            $coupon->setExpirationDate(new \DateTime('+1 year'));
            $coupon->setMaxUsage(Coupon::UNLIMITED_COUPON_USE);
            $coupon->setIsCumulative(false);
            $coupon->setIsRemovingPostage(false);
            $coupon->setIsAvailableOnSpecialOffers(true);
            $coupon->setIsUsed(false);
            $coupon->setPerCustomerUsageCount(false);
            $coupon->setSerializedConditions($this->amountConditions((float) $tier['threshold'], $currencyCode));
            $coupon
                ->setLocale('fr_FR')->setTitle($tier['titleFr'])->setShortDescription($tier['titleFr'])->setDescription('')
                ->setLocale('en_US')->setTitle($tier['titleEn'])->setShortDescription($tier['titleEn'])->setDescription('');
            $coupon->save();
            ++$created;
        }

        $output->writeln(\sprintf('Amount-tier coupons: %d newly created (target: %s).', $created, implode(', ', array_column(self::TIER_COUPONS, 'code'))));
    }

    private function amountConditions(float $threshold, string $currencyCode): string
    {
        $condition = (new MatchForTotalAmount($this->couponFacade))->setValidatorsFromForm(
            [
                MatchForTotalAmount::CART_TOTAL => Operators::SUPERIOR_OR_EQUAL,
                MatchForTotalAmount::CART_CURRENCY => Operators::EQUAL,
            ],
            [
                MatchForTotalAmount::CART_TOTAL => (string) $threshold,
                MatchForTotalAmount::CART_CURRENCY => $currencyCode,
            ],
        );

        $collection = new ConditionCollection();
        $collection[] = $condition;

        return $this->conditionFactory->serializeConditionCollection($collection);
    }
}
