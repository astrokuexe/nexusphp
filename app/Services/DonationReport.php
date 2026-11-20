<?php

namespace App\Services;

class DonationReport
{
    public static function isValid(string $coin, string $amount, string $transactionId): bool
    {
        if (!in_array($coin, ['XMR', 'LTC'], true)) {
            return false;
        }

        $decimalPlaces = $coin === 'XMR' ? 12 : 8;
        $amountPattern = '/\A\d{1,18}(?:\.\d{1,' . $decimalPlaces . '})?\z/';

        return preg_match($amountPattern, $amount) === 1
            && preg_match('/[1-9]/', $amount) === 1
            && preg_match('/\A[a-f0-9]{64}\z/i', $transactionId) === 1;
    }
}
