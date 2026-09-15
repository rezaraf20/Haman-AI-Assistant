<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
class HealthController extends Controller {
    public function index() {
        try { DB::select('SELECT 1'); $db='ok'; } catch(\Throwable $e) { $db='error: '.$e->getMessage(); }
        return response()->json(['status'=>$db==='ok'?'ok':'degraded','database'=>$db,'timestamp'=>now()->toISOString()],200);
    }

    // Public, unauthenticated on purpose — the WordPress plugin's Advanced
    // tab polls this to show an "update available" notice before the
    // customer has necessarily even entered a valid API key yet.
    public function wpPluginVersion() {
        return response()->json(['latest_version' => config('haman.wp_plugin.latest_version')], 200);
    }
}
