<?php
namespace App\Support;

/**
 * The limits a WordPress plugin must respect when it sends content, in the
 * shape it reads them (connection test and GET/PUT sync-settings both
 * include `limits`).
 *
 * The plugin used to hard-code its batch size (by bytes only) while this
 * side enforced an item count (limits.sync_items_per_request, default 50),
 * so a shop with many small products could never finish a full sync —
 * khonehrangi.ir delivered 6 of its 173 products. Read from here, a change
 * made in Admin → Settings reaches every installed plugin from 2.7.2 on,
 * without a release.
 */
class SyncLimits
{
    public static function forApi(): array
    {
        return [
            'sync_items_per_request' => max(1, (int) Settings::get('limits.sync_items_per_request')),
        ];
    }
}
