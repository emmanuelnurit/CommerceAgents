<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\ModuleAvailabilityInterface;
use CommerceAgents\Tool\Admin\Gateway\ReviewsGatewayInterface;
use CommerceAgents\Tool\Admin\GetProductReviewsTool;
use PHPUnit\Framework\TestCase;

final class FakeModuleAvailability implements ModuleAvailabilityInterface
{
    public function __construct(private readonly bool $active = true)
    {
    }

    public function isActive(string $moduleCode): bool
    {
        return $this->active;
    }
}

final class FakeReviewsGateway implements ReviewsGatewayInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $lastCall = [];

    public function __construct(private readonly array $reviews = [])
    {
    }

    public function getProductReviews(bool $onlyWithoutReply, int $limit, ToolContext $ctx): array
    {
        $this->lastCall = ['getProductReviews', $onlyWithoutReply, $limit];

        return $this->reviews;
    }

    public function findReview(int $commentId): ?array
    {
        return null;
    }
}

class GetProductReviewsToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new GetProductReviewsTool(new FakeReviewsGateway(), new FakeModuleAvailability());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testRefusesWhenCommentModuleIsInactive(): void
    {
        $gateway = new FakeReviewsGateway();
        $tool = new GetProductReviewsTool($gateway, new FakeModuleAvailability(active: false));

        $result = $tool->execute([], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testListsReviewsWhenCommentModuleIsActive(): void
    {
        $reviews = [['id' => 1, 'productTitle' => 'T-shirt', 'rating' => 5]];
        $gateway = new FakeReviewsGateway($reviews);
        $tool = new GetProductReviewsTool($gateway, new FakeModuleAvailability(active: true));

        $result = $tool->execute(['limit' => 5, 'only_without_reply' => false], $this->adminContext());

        $this->assertSame(['getProductReviews', false, 5], $gateway->lastCall);
        $this->assertSame(1, $result['count']);
        $this->assertSame($reviews, $result['reviews']);
    }

    public function testLimitDefaultsAndIsCapped(): void
    {
        $gateway = new FakeReviewsGateway();
        $tool = new GetProductReviewsTool($gateway, new FakeModuleAvailability());

        $tool->execute([], $this->adminContext());
        $this->assertSame(['getProductReviews', true, 10], $gateway->lastCall);

        $tool->execute(['limit' => 999], $this->adminContext());
        $this->assertSame(['getProductReviews', true, 50], $gateway->lastCall);
    }
}
