<?php

namespace Elrayn\AutoNumber\Tests;

use DateTimeImmutable;
use Elrayn\AutoNumber\AutoNumber;
use PDO;
use PHPUnit\Framework\TestCase;

class AutoNumberTest extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testFirstNumberStartsAtOne(): void
    {
        $number = AutoNumber::make($this->pdo)->key('invoice')->digits(4)->next();

        $this->assertSame('0001', $number);
    }

    public function testConsecutiveCallsIncrement(): void
    {
        $generator = AutoNumber::make($this->pdo)->key('invoice')->digits(3);

        $this->assertSame('001', $generator->next());
        $this->assertSame('002', $generator->next());
        $this->assertSame('003', $generator->next());
    }

    public function testDifferentKeysDoNotShareACounter(): void
    {
        $invoice = AutoNumber::make($this->pdo)->key('invoice')->digits(3);
        $po = AutoNumber::make($this->pdo)->key('po')->digits(3);

        $this->assertSame('001', $invoice->next());
        $this->assertSame('001', $po->next());
        $this->assertSame('002', $invoice->next());
    }

    public function testPrefixAndSeparatorAreApplied(): void
    {
        $number = AutoNumber::make($this->pdo)->key('invoice')->prefix('INV')->digits(4)->next();

        $this->assertSame('INV-0001', $number);
    }

    public function testMonthlyResetStartsANewCounterInANewMonth(): void
    {
        $generator = AutoNumber::make($this->pdo)->key('invoice')->digits(3)->resetMonthly();

        $september = $generator->at(new DateTimeImmutable('2026-09-30'))->next();
        $stillSeptember = $generator->at(new DateTimeImmutable('2026-09-30'))->next();
        $october = $generator->at(new DateTimeImmutable('2026-10-01'))->next();

        $this->assertSame('202609-001', $september);
        $this->assertSame('202609-002', $stillSeptember);
        $this->assertSame('202610-001', $october);
    }

    public function testNoResetKeepsIncrementingAcrossMonths(): void
    {
        $generator = AutoNumber::make($this->pdo)->key('member')->digits(3);

        $first = $generator->at(new DateTimeImmutable('2026-09-30'))->next();
        $second = $generator->at(new DateTimeImmutable('2026-10-01'))->next();

        $this->assertSame('001', $first);
        $this->assertSame('002', $second);
    }
}
