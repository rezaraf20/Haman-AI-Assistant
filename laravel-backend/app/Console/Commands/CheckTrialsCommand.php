<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Tenant;
class CheckTrialsCommand extends Command {
    protected $signature   = 'hamman:check-trials';
    protected $description = 'Suspend tenants with expired trials and no active subscription';
    public function handle(): void {
        $expired = Tenant::where('status','trial')->where('trial_ends_at','<',now())->get();
        foreach($expired as $t) {
            $active = $t->subscriptions()->where('status','active')->exists();
            if(!$active) { $t->update(['status'=>'suspended']); $this->line("Suspended: {$t->email}"); }
        }
        $this->info("Checked {$expired->count()} expired trials");
    }
}
