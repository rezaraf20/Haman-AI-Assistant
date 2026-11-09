<?php
namespace App\Exceptions;

/** The invoice was paid or voided by someone else first (BillingService); the charge it was about to make rolls back. */
class InvoiceNotOpen extends \RuntimeException {}
