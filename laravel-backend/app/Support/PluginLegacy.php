<?php
namespace App\Support;

/**
 * The plugin's old spelling, as far as the platform still has to know it.
 *
 * The product is Haman, one "m". The WordPress plugin shipped as "Hamman"
 * through 1.9.0, which fixed the old spelling into two things that cross the
 * wire: the REST namespace the platform calls, and the HMAC header both sides
 * sign with.
 *
 * The platform and each customer's plugin update independently and in either
 * order, so for a while every combination exists: a new platform calling a
 * 1.9.0 site, and a 1.9.0 site calling a new platform. Both have to work, or
 * a customer's shop silently stops answering stock and price questions at the
 * moment they update one side.
 *
 * TODO(2027-03-01): delete this class and its call sites, once no site is
 * still on 1.x. Mirrors Haman_Legacy in the plugin, which has the same date.
 *
 * This is deliberately the only PHP file that contains the old spelling.
 */
class PluginLegacy
{
    /** The 1.x REST namespace, tried after the current one returns 404. */
    public const REST_NAMESPACE = 'hamman/v1';

    /** The 1.x HMAC header, accepted on inbound requests from a 1.x plugin. */
    public const SIGNATURE_HEADER = 'X-Hamman-Signature';

    /** The current namespace first, the old one as the fallback. */
    public static function namespaces(): array
    {
        return ['haman/v1', self::REST_NAMESPACE];
    }
}
