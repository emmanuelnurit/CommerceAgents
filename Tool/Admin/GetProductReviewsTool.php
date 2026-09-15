<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Service\ModuleAvailabilityInterface;
use CommerceAgents\Tool\Admin\Gateway\ReviewsGatewayInterface;

final readonly class GetProductReviewsTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private ReviewsGatewayInterface $reviewsGateway,
        private ModuleAvailabilityInterface $moduleAvailability,
    ) {
    }

    public function getName(): string
    {
        return 'get_product_reviews';
    }

    public function getDescription(): string
    {
        return 'List accepted product reviews, most recent first. By default only returns reviews '
            .'that do not have a drafted reply yet, so you never draft a duplicate.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'only_without_reply' => ['type' => 'boolean', 'description' => 'Default true: skip reviews already replied to'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default 10, max 50)'],
            ],
            'required' => [],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::REVIEWS_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        if (!$this->moduleAvailability->isActive('Comment')) {
            return ['error' => 'The "Comment" module is not active on this shop; product reviews are unavailable.'];
        }

        $limit = min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $onlyWithoutReply = (bool) ($args['only_without_reply'] ?? true);

        $reviews = $this->reviewsGateway->getProductReviews($onlyWithoutReply, $limit, $ctx);

        return ['count' => \count($reviews), 'reviews' => $reviews];
    }
}
