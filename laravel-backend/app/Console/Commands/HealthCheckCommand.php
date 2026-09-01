<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Services\AiGatewayService;
class HealthCheckCommand extends Command {
    protected $signature   = 'hamman:health-check';
    protected $description = 'Check health of all platform services';
    public function handle(AiGatewayService $ai): int {
        $ok = true;
        try { DB::select('SELECT 1'); $this->info('✅ PostgreSQL: OK'); } catch(\Throwable $e) { $this->error('❌ PostgreSQL: '.$e->getMessage()); $ok=false; }
        try { Redis::ping(); $this->info('✅ Redis: OK'); } catch(\Throwable $e) { $this->error('❌ Redis: '.$e->getMessage()); $ok=false; }
        $h = $ai->healthCheck();
        if(($h['status']??'down')==='ok') { $this->info('✅ AI Service: OK'); } else { $this->error('❌ AI Service: '.($h['error']??'down')); $ok=false; }
        return $ok ? 0 : 1;
    }
}
