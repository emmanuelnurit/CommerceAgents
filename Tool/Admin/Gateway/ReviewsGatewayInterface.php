<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface ReviewsGatewayInterface
{
    /**
     * @return list<array{id: int, productRef: ?string, productTitle: ?string, rating: ?int,
     *               title: ?string, content: ?string, author: string, createdAt: ?string, hasReply: bool}>
     */
    public function getProductReviews(bool $onlyWithoutReply, int $limit, ToolContext $ctx): array;

    /**
     * @return array{id: int, productTitle: ?string, rating: ?int, content: ?string}|null
     */
    public function findReview(int $commentId): ?array;
}
