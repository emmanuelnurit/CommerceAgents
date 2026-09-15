<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Admin;

use Comment\Service\BackOffice\CommentListFilters;
use Comment\Service\BackOffice\CommentListPresenter;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Service\Merchant\ReviewReplyLookup;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Twig\Environment;

/**
 * Dedicated "Avis" tab on the product edit page (MYO-352/MYO-358).
 *
 * The core Comment module only subscribes to product.tab-content, which the
 * theme renders inside the generic "Modules" tab (vendor/thelia/modules/Comment/Hook/BackHook.php)
 * — it never subscribes to product.tab, so reviews never got their own tab
 * button. This class fills that gap for CommerceAgents, the only module that
 * needs reviews outside "Modules": it owns the merchant reply overlay
 * (agent_review_reply) that has to render alongside each review.
 *
 * Reuses Comment's own CommentListPresenter for the listing rather than
 * re-querying comment/comment_i18n, then joins in the approved reply per
 * comment_id from CommerceAgents' own table — Comment has no native reply
 * concept.
 */
final class ReviewsTabHook extends BaseHook
{
    private const PRODUCT_REF = 'product';

    public function __construct(
        private readonly CommentListPresenter $commentListPresenter,
        private readonly ReviewReplyLookup $reviewReplyLookup,
        private readonly RequestStack $requestStack,
        private readonly Environment $twig,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'product.tab' => [
                ['type' => 'back', 'method' => 'onProductTab'],
            ],
        ];
    }

    public function onProductTab(HookRenderBlockEvent $event): void
    {
        $productId = (int) ($event->getArgument('product') ?: $event->getArgument('id'));
        if ($productId <= 0) {
            return;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en_US';

        $filters = new CommentListFilters(ref: self::PRODUCT_REF, refId: $productId, limit: 200);
        $presented = $this->commentListPresenter->present($filters, $locale);

        $commentIds = array_map(static fn (array $row): int => $row['id'], $presented['rows']);

        $event->add([
            'id' => 'commerceagents_reviews',
            'title' => $this->trans('Reviews', [], CommerceAgents::DOMAIN_NAME),
            'content' => $this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/reviews-tab.html.twig', [
                'reviews' => $presented['rows'],
                'replies' => $this->reviewReplyLookup->findByCommentIds($commentIds),
            ]),
        ]);
    }
}
