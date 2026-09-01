<?php
namespace App\Enums;
enum TenantStatus: string {
    case Trial='trial'; case Active='active'; case Suspended='suspended'; case Cancelled='cancelled'; case PastDue='past_due';
    public function isAccessible(): bool { return in_array($this,[self::Trial,self::Active]); }
}
