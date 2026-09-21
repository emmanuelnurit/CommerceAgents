<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\NewsletterGatewayInterface;
use Thelia\Model\NewsletterQuery;

final readonly class TheliaNewsletterGateway implements NewsletterGatewayInterface
{
    public function isSubscribed(string $email): bool
    {
        $newsletter = NewsletterQuery::create()->findOneByEmail($email);

        return $newsletter !== null && !$newsletter->getUnsubscribed();
    }
}
