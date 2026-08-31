<?php
namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
<<<<<<< HEAD
=======
use App\Models\Tenant;
use Filament\Actions\CreateAction;
>>>>>>> origin/develop
use Filament\Resources\Pages\ListRecords;

class ListTenants extends ListRecords {
    protected static string $resource = TenantResource::class;
<<<<<<< HEAD
=======

    // Opening this list is what "seeing" new customers means for the
    // navigation badge (see TenantResource::getNavigationBadge()) — simpler
    // and good enough than tracking which individual row the admin actually
    // looked at, since they all appear in the table right here.
    public function mount(): void {
        parent::mount();
        Tenant::whereNull('admin_seen_at')->update(['admin_seen_at' => now()]);
    }

    // Explicit instead of relying on ListRecords' implicit default header
    // action — that default silently produced no button at all for this
    // resource despite canCreate() reporting true and the create route
    // existing, for reasons that weren't worth chasing further.
    protected function getHeaderActions(): array {
        return [
            CreateAction::make(),
        ];
    }
>>>>>>> origin/develop
}
