<?php
namespace App\Support;

/**
 * AES-256-GCM encryption for LlmProviderProfile.api_key — deliberately NOT
 * Laravel's Crypt facade. Python's rag_service reads this column via a raw
 * SQL query (see python-ai-service/app/services/llm_provider_service.py) to
 * call Groq/xAI directly, with no Laravel round-trip — so whatever scheme
 * encrypts it here must also be decryptable from Python using the same key.
 * Laravel's Crypt envelope is a documented format but replicating its exact
 * PHP-specific serialization in Python is its own source of subtle bugs; a
 * plain, minimal AES-256-GCM envelope (nonce:ciphertext+tag, both base64) is
 * simple enough to implement identically on both sides and easy to verify.
 *
 * Key material: APP_KEY's raw 32 bytes (already present on both sides — see
 * HAMMAN_ENCRYPTION_KEY in python-ai-service/.env, which must be kept equal
 * to this app's APP_KEY). Not a new secret to provision or rotate separately.
 */
class LlmKeyCrypto {
    public static function encrypt(string $plaintext): string {
        $key = self::key();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('LlmKeyCrypto: encryption failed');
        }
        return base64_encode($nonce) . ':' . base64_encode($ciphertext . $tag);
    }

    /** Null on any failure (including "this isn't our format" — callers decide the fallback). */
    public static function decrypt(string $encoded): ?string {
        if (!str_contains($encoded, ':')) return null;
        [$nonceB64, $dataB64] = explode(':', $encoded, 2);
        $nonce = base64_decode($nonceB64, true);
        $data  = base64_decode($dataB64, true);
        if ($nonce === false || $data === false || strlen($nonce) !== 12 || strlen($data) < 16) {
            return null;
        }
        $tag        = substr($data, -16);
        $ciphertext = substr($data, 0, -16);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
    }

    private static function key(): string {
        $appKey = config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }
        // AES-256 needs exactly 32 bytes; Laravel's default APP_KEY already is
        // (generated via `php artisan key:generate`), but hash() as a safety
        // net rather than fail if it's ever a different length.
        return strlen($appKey) === 32 ? $appKey : hash('sha256', $appKey, true);
    }
}
