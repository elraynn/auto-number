<?php

namespace Elrayn\AutoNumber\Tests;

use DateTimeImmutable;
use Elrayn\AutoNumber\NumberFormatter;
use PHPUnit\Framework\TestCase;

class NumberFormatterTest extends TestCase
{
    public function testFormatPadsAndJoinsPrefixPeriodAndNumber(): void
    {
        $this->assertSame('INV-202609-0007', NumberFormatter::format('INV', '202609', 7, 4, '-'));
    }

    public function testFormatWithoutPrefixOrPeriod(): void
    {
        $this->assertSame('0042', NumberFormatter::format(null, null, 42, 4, '-'));
    }

    public function testFormatDoesNotTruncateValueLargerThanDigits(): void
    {
        $this->assertSame('INV-99999', NumberFormatter::format('INV', null, 99999, 4, '-'));
    }

    public function testFormatUsesCustomSeparator(): void
    {
        $this->assertSame('INV/202609/0001', NumberFormatter::format('INV', '202609', 1, 4, '/'));
    }

    public function testPeriodSuffixDaily(): void
    {
        $now = new DateTimeImmutable('2026-09-22 10:00:00');
        $this->assertSame('20260922', NumberFormatter::periodSuffix('daily', $now));
    }

    public function testPeriodSuffixMonthly(): void
    {
        $now = new DateTimeImmutable('2026-09-22 10:00:00');
        $this->assertSame('202609', NumberFormatter::periodSuffix('monthly', $now));
    }

    public function testPeriodSuffixYearly(): void
    {
        $now = new DateTimeImmutable('2026-09-22 10:00:00');
        $this->assertSame('2026', NumberFormatter::periodSuffix('yearly', $now));
    }

    public function testPeriodSuffixNullWhenNoResetConfigured(): void
    {
        $this->assertNull(NumberFormatter::periodSuffix(null));
    }
}
