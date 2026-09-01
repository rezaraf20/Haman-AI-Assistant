<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Tenant\{Chatbot,AnalyticsDaily,Message,Conversation};
use Illuminate\Support\Facades\DB;
use App\Models\Tenant as TenantModel;

class AggregateAnalyticsJob implements ShouldQueue {
    use Dispatchable, Queueable;
    public int $timeout = 300;
    public function handle(): void {
        $date = now()->subDay()->toDateString();
        // Run for each tenant
        \App\Models\Tenant::active()->with('plan')->chunk(20, function($tenants) use($date) {
            foreach($tenants as $tenant) {
                DB::statement("SET search_path TO {$tenant->schema_name},public");
                $chatbots = Chatbot::active()->get();
                foreach($chatbots as $chatbot) {
                    $msgs  = Message::where('chatbot_id',$chatbot->id)->whereDate('created_at',$date)->get();
                    $convs = Conversation::where('chatbot_id',$chatbot->id)->whereDate('started_at',$date)->count();
                    if($msgs->isEmpty()) continue;
                    AnalyticsDaily::updateOrCreate(['chatbot_id'=>$chatbot->id,'date'=>$date],[
                        'total_conversations'     => $convs,
                        'total_messages'          => $msgs->count(),
                        'user_messages'           => $msgs->where('role','user')->count(),
                        'assistant_messages'      => $msgs->where('role','assistant')->count(),
                        'total_tokens'            => $msgs->sum('total_tokens'),
                        'prompt_tokens'           => $msgs->sum('prompt_tokens'),
                        'completion_tokens'       => $msgs->sum('completion_tokens'),
                        'avg_response_latency_ms' => (int)$msgs->where('role','assistant')->avg('latency_ms'),
                        'fallback_count'          => $msgs->where('is_fallback',true)->count(),
                        'updated_at'              => now(),
                    ]);
                }
                DB::statement("SET search_path TO public");
            }
        });
    }
}
