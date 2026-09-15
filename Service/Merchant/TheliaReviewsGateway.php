<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use Comment\Model\Comment;
use Comment\Model\CommentQuery;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentReviewReplyQuery;
use CommerceAgents\Tool\Admin\Gateway\ReviewsGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductQuery;

/**
 * Reads product reviews from the core "Comment" module (MYO-301 preset). Only
 * ever called for an agent granted the reviews.* capability, which the
 * "Répondeur d'avis clients" preset only offers when Comment is active — see
 * ModuleAvailability.
 *
 * Resolves the product directly via ProductQuery rather than Comment's own
 * CommentReferenceResolver: that resolver dispatches an event whose listener
 * (Comment\Action\CommentAction::getRefrence()) builds an admin edit URL via
 * URL::getInstance(), which is only initialized on a real HTTP request and
 * throws when a configurable agent runs from a cron/CLI context.
 */
final readonly class TheliaReviewsGateway implements ReviewsGatewayInterface
{
    private const PRODUCT_REF = 'product';

    public function getProductReviews(bool $onlyWithoutReply, int $limit, ToolContext $ctx): array
    {
        $repliedCommentIds = [];
        if ($onlyWithoutReply) {
            foreach (AgentReviewReplyQuery::create()->find() as $reply) {
                $repliedCommentIds[] = $reply->getCommentId();
            }
        }

        $query = CommentQuery::create()
            ->filterByRef(self::PRODUCT_REF)
            ->filterByStatus(Comment::ACCEPTED)
            ->orderByCreatedAt(Criteria::DESC);

        if ($repliedCommentIds !== []) {
            $query->filterById($repliedCommentIds, Criteria::NOT_IN);
        }

        $reviews = [];
        foreach ($query->limit($limit)->find() as $comment) {
            $reviews[] = $this->toArray($comment, $ctx->locale);
        }

        return $reviews;
    }

    public function findReview(int $commentId): ?array
    {
        $comment = CommentQuery::create()->filterByRef(self::PRODUCT_REF)->findPk($commentId);
        if ($comment === null) {
            return null;
        }

        return [
            'id' => $comment->getId(),
            'productTitle' => $this->productTitle($comment, 'fr_FR'),
            'rating' => $comment->getRating(),
            'content' => $comment->getContent(),
        ];
    }

    /**
     * @return array{id: int, productRef: ?string, productTitle: ?string, rating: ?int,
     *               title: ?string, content: ?string, author: string, createdAt: ?string, hasReply: bool}
     */
    private function toArray(Comment $comment, string $locale): array
    {
        $locale = $comment->getLocale() ?? $locale;
        $product = $comment->getRefId() !== null ? ProductQuery::create()->findPk($comment->getRefId()) : null;

        $createdAt = $comment->getCreatedAt();

        return [
            'id' => $comment->getId(),
            'productRef' => $product?->getRef(),
            'productTitle' => $product?->setLocale($locale)->getTitle(),
            'rating' => $comment->getRating(),
            'title' => $comment->getTitle(),
            'content' => $comment->getContent(),
            'author' => $comment->getUsername() ?? 'Anonymous',
            'createdAt' => $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d') : null,
            'hasReply' => AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->exists(),
        ];
    }

    private function productTitle(Comment $comment, string $locale): ?string
    {
        $product = $comment->getRefId() !== null ? ProductQuery::create()->findPk($comment->getRefId()) : null;

        return $product?->setLocale($comment->getLocale() ?? $locale)->getTitle();
    }
}
