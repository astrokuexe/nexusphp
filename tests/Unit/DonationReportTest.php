<?php

namespace Tests\Unit;

use App\Services\DonationReport;
use PHPUnit\Framework\TestCase;

class DonationReportTest extends TestCase
{
    public function testAcceptsPositiveAmountsAtEachCoinsPrecision(): void
    {
        $transactionId = str_repeat('a', 64);

        $this->assertTrue(DonationReport::isValid('XMR', '0.000000000001', $transactionId));
        $this->assertTrue(DonationReport::isValid('LTC', '0.00000001', $transactionId));
    }

    public function testRejectsInvalidCoinAmountsAndTransactionIds(): void
    {
        $transactionId = str_repeat('a', 64);

        $this->assertFalse(DonationReport::isValid('BTC', '1', $transactionId));
        $this->assertFalse(DonationReport::isValid('XMR', '0', $transactionId));
        $this->assertFalse(DonationReport::isValid('LTC', '0.000000001', $transactionId));
        $this->assertFalse(DonationReport::isValid('XMR', '1e-3', $transactionId));
        $this->assertFalse(DonationReport::isValid('LTC', '1', str_repeat('g', 64)));
    }
}
