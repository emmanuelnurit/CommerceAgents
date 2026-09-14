<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Service\Run\CronSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testNextRunDate(string $expression, string $from, string $expected): void
    {
        $next = CronSchedule::nextRunDate($expression, new \DateTimeImmutable($from));

        $this->assertNotNull($next);
        $this->assertSame($expected, $next->format('Y-m-d H:i'));
    }

    public static function expressions(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-09-14 10:30:00', '2026-09-14 10:31'],
            'seconds are ignored, next minute wins' => ['* * * * *', '2026-09-14 10:30:45', '2026-09-14 10:31'],
            'daily at 4am' => ['0 4 * * *', '2026-09-14 10:30:00', '2026-09-15 04:00'],
            'daily at 4am, before 4am' => ['0 4 * * *', '2026-09-14 03:59:00', '2026-09-14 04:00'],
            'every 15 minutes' => ['*/15 * * * *', '2026-09-14 10:31:00', '2026-09-14 10:45'],
            'hourly on the hour' => ['0 * * * *', '2026-09-14 10:00:00', '2026-09-14 11:00'],
            'mondays at 8' => ['0 8 * * 1', '2026-09-14 09:00:00', '2026-09-21 08:00'],
            'sunday as 7' => ['0 8 * * 7', '2026-09-14 09:00:00', '2026-09-20 08:00'],
            'first day of month' => ['30 6 1 * *', '2026-09-14 10:00:00', '2026-10-01 06:30'],
            'specific month' => ['0 0 1 1 *', '2026-09-14 10:00:00', '2027-01-01 00:00'],
            'range of hours' => ['0 9-11 * * *', '2026-09-14 10:30:00', '2026-09-14 11:00'],
            'list of minutes' => ['5,35 * * * *', '2026-09-14 10:06:00', '2026-09-14 10:35'],
            'dom OR dow when both set' => ['0 0 15 * 1', '2026-09-14 10:00:00', '2026-09-15 00:00'],
        ];
    }

    public function testMalformedExpressionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CronSchedule::nextRunDate('not a cron', new \DateTimeImmutable('2026-09-14 10:00:00'));
    }

    public function testTooFewFieldsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CronSchedule::nextRunDate('* * *', new \DateTimeImmutable('2026-09-14 10:00:00'));
    }

    public function testOutOfBoundsValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CronSchedule::nextRunDate('61 * * * *', new \DateTimeImmutable('2026-09-14 10:00:00'));
    }
}
