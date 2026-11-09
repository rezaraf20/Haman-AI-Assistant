<?php
namespace App\Services\Crypto;

use App\Support\{Settings, Usdt};
use Illuminate\Support\Facades\Http;

/**
 * JSON-RPC to BNB Smart Chain: the primary endpoint, then the fallback.
 *
 * Every endpoint proves it is BSC mainnet (eth_chainId = 56) before it is
 * used for anything else — a misconfigured URL pointing at a testnet or
 * another EVM chain would otherwise "find" payments that do not exist.
 * Plain HTTP via Guzzle; no web3 library is needed for these five calls.
 */
class BscRpc
{
    /** @var array<string, bool> endpoint => passed the chain check */
    private array $verified = [];
    public ?string $lastEndpoint = null;

    /** @param string[]|null $endpoints */
    public function __construct(private ?array $endpoints = null) {}

    /** @return string[] HTTPS only — what these endpoints answer decides whether money is credited. */
    public function endpoints(): array
    {
        $list = $this->endpoints ?? [Settings::get('crypto.bsc_rpc_primary'), Settings::get('crypto.bsc_rpc_fallback')];
        return array_values(array_unique(array_filter(array_map(fn ($u) => trim((string) $u), $list),
            fn ($u) => str_starts_with($u, 'https://'))));
    }

    /** @throws RpcUnavailable when no endpoint answers */
    public function call(string $method, array $params = []): mixed
    {
        return $this->ask($method, $params, nullMeansAskNext: false);
    }

    /**
     * Like call(), but a `null` result is not taken as the answer: the next
     * endpoint is asked too. For "is this transaction / block there?" —
     * public BSC endpoints are load-balanced pools, and a replica a few
     * blocks behind answers null for something that very much exists. Null
     * comes back only when every endpoint that answered said null.
     *
     * @throws RpcUnavailable when no endpoint answers at all
     */
    public function callAny(string $method, array $params = []): mixed
    {
        return $this->ask($method, $params, nullMeansAskNext: true);
    }

    private function ask(string $method, array $params, bool $nullMeansAskNext): mixed
    {
        $errors = [];
        $answered = false;
        foreach ($this->endpoints() as $url) {
            try {
                if (!isset($this->verified[$url])) {
                    $chain = $this->raw($url, 'eth_chainId', []);
                    if (hexdec((string) $chain) !== Usdt::CHAIN_ID) {
                        $this->verified[$url] = false;
                        $errors[] = self::host($url) . ": wrong chain {$chain}";
                        continue;
                    }
                    $this->verified[$url] = true;
                }
                if ($this->verified[$url] === false) continue;

                $result = $this->raw($url, $method, $params);
                $answered = true;
                if ($result === null && $nullMeansAskNext) continue;
                $this->lastEndpoint = $url;
                return $result;
            } catch (\Throwable $e) {
                $errors[] = self::host($url) . ': ' . $e->getMessage();
            }
        }
        if ($answered) return null;
        throw new RpcUnavailable($errors ? implode(' | ', $errors) : 'no RPC endpoint configured');
    }

    private function raw(string $url, string $method, array $params): mixed
    {
        $response = Http::timeout(20)->acceptJson()->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}");
        }
        $body = $response->json();
        if (isset($body['error'])) {
            throw new \RuntimeException('RPC error ' . ($body['error']['code'] ?? '?') . ': ' . ($body['error']['message'] ?? ''));
        }
        if (!is_array($body) || !array_key_exists('result', $body)) {
            throw new \RuntimeException('malformed RPC response');
        }
        return $body['result'];
    }

    public function blockNumber(): int
    {
        return (int) hexdec((string) $this->call('eth_blockNumber'));
    }

    /** The latest block BSC's fast finality has made final, or null if this RPC does not know the tag. */
    public function finalizedBlock(): ?int
    {
        try {
            $block = $this->call('eth_getBlockByNumber', ['finalized', false]);
        } catch (RpcUnavailable) {
            return null;
        }
        return isset($block['number']) ? (int) hexdec($block['number']) : null;
    }

    public function blockTime(int $number): ?int
    {
        $block = $this->call('eth_getBlockByNumber', ['0x' . dechex($number), false]);
        return isset($block['timestamp']) ? (int) hexdec($block['timestamp']) : null;
    }

    /** USDT Transfer logs whose recipient is $to, in [from, to]. */
    public function transferLogs(int $from, int $to, string $recipient): array
    {
        return (array) $this->call('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($from),
            'toBlock'   => '0x' . dechex($to),
            'address'   => Usdt::CONTRACT,
            'topics'    => [Usdt::TRANSFER_TOPIC, null, Usdt::addressToTopic($recipient)],
        ]]);
    }

    /** Null only when no endpoint has it — see callAny(). */
    public function receipt(string $txHash): ?array
    {
        $r = $this->callAny('eth_getTransactionReceipt', [$txHash]);
        return is_array($r) ? $r : null;
    }

    /** The canonical chain's block hash at this height (lowercase), or null when no endpoint has the block. */
    public function blockHashAt(int $number): ?string
    {
        $block = $this->callAny('eth_getBlockByNumber', ['0x' . dechex($number), false]);
        return isset($block['hash']) ? strtolower($block['hash']) : null;
    }

    private static function host(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: 'rpc';
    }
}
