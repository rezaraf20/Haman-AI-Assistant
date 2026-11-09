<?php
namespace App\Exceptions;

/**
 * Thrown by WalletService::debit() when the balance — read under the row
 * lock, not the one a page loaded earlier — does not cover the charge.
 * Thrown rather than returned so the surrounding transaction rolls back
 * whatever the caller's grant had already done.
 */
class InsufficientBalance extends \RuntimeException
{
    public function __construct(public readonly int $balance, public readonly int $required)
    {
        parent::__construct("Insufficient wallet balance: {$balance} < {$required}");
    }
}
