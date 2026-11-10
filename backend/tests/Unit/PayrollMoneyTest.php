<?php

namespace Tests\Unit;

use App\Payroll\Money;
use App\Payroll\PayrollCalculator;
use App\Payroll\PeriodGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PayrollMoneyTest extends TestCase
{
    public function test_parsing_and_formatting_are_exact(): void
    {
        $this->assertSame(123450, Money::parse('1234.5', 'USD'));
        $this->assertSame(5, Money::parse('0.05', 'MXN'));
        $this->assertSame(-1999, Money::parse('-19.99', 'CAD'));
        $this->assertSame(100, Money::parse('1', 'AED'));
        $this->assertSame('1234.50', Money::toDecimal(123450, 'USD'));
        $this->assertSame('-0.05', Money::toDecimal(-5, 'USD'));
        $this->assertSame('1,234,567.89', Money::format(123456789, 'USD'));
        // Classic float trap: 0.1 + 0.2 stays exact in minor units.
        $this->assertSame(30, Money::parse('0.1', 'USD') + Money::parse('0.2', 'USD'));
    }

    public function test_invalid_amounts_and_currencies_are_rejected(): void
    {
        foreach (['1.234', '1e3', 'abc', '', '1,000.00'] as $bad) {
            try {
                Money::parse($bad, 'USD');
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Money::parse('1.00', 'EUR');
    }

    public function test_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame(3, Money::mulDiv(5, 1, 2));    // 2.5 → 3
        $this->assertSame(-3, Money::mulDiv(-5, 1, 2));  // -2.5 → -3
        $this->assertSame(2, Money::mulDiv(7, 1, 3));    // 2.33 → 2
        $this->assertSame(1013, Money::percent(20250, 500)); // 5% of 202.50 = 10.125 → 10.13
        $this->assertSame(0, Money::percent(9, 500));    // 0.45 cents → 0
    }

    public function test_period_amount_conversion(): void
    {
        $this->assertSame(500000, PayrollCalculator::periodAmount(6000000, 'annual', 'monthly', 'monthly'));
        $this->assertSame(230769, PayrollCalculator::periodAmount(6000000, 'annual', 'monthly', 'biweekly')); // 60000/26 = 2307.692
        $this->assertSame(250000, PayrollCalculator::periodAmount(500000, 'monthly', 'monthly', 'semimonthly'));
        $this->assertSame(230769, PayrollCalculator::periodAmount(500000, 'period', 'monthly', 'biweekly'));
        $this->assertSame(500000, PayrollCalculator::periodAmount(500000, 'period', 'monthly', 'monthly'));
    }

    public function test_period_bounds(): void
    {
        $this->assertSame(['2027-02-01', '2027-02-28'], PeriodGenerator::bounds('monthly', '2027-02-01'));
        $this->assertSame(['2028-02-16', '2028-02-29'], PeriodGenerator::bounds('semimonthly', '2028-02-16'));
        $this->assertSame(['2027-01-01', '2027-01-15'], PeriodGenerator::bounds('semimonthly', '2027-01-01'));
        $this->assertSame(['2027-01-04', '2027-01-17'], PeriodGenerator::bounds('biweekly', '2027-01-04'));
        $this->assertSame(['2027-01-31', '2027-02-27'], PeriodGenerator::bounds('monthly', '2027-01-31'));
    }
}
