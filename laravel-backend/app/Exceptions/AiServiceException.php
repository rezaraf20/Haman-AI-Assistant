<?php namespace App\Exceptions;
class AiServiceException extends \RuntimeException {
    public function __construct(string $msg='AI service error', int $code=502, ?\Throwable $prev=null) {
        parent::__construct($msg,$code,$prev);
    }
}
