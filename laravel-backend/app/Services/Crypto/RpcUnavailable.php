<?php
namespace App\Services\Crypto;

/** No configured BSC endpoint answered (or none is on chain 56). The scanner records it and tries again next minute. */
class RpcUnavailable extends \RuntimeException {}
