<?php
namespace App\Exceptions;
class QuotaExceededException extends \RuntimeException {
    public function __construct(string $message='Quota exceeded') { parent::__construct($message,429); }
}
