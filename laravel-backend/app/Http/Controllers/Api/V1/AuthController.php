<?php namespace App\Http\Controllers\Api\V1;
use App\Services\TenantService;
use App\Models\User;
use Illuminate\Http\{Request,JsonResponse};
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController {
    public function __construct(private TenantService $svc) {}

    public function register(Request $req): JsonResponse {
        $data = $req->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
        ]);
        $r = $this->svc->register($data);
        return $this->created([
            'tenant'  => $r['tenant']->only(['id','name','email','slug','status']),
            'user'    => $r['user']->only(['id','name','email','role']),
            'api_key' => $r['api_key'],
            'note'    => 'Store API key securely — shown once only',
        ]);
    }

    public function login(Request $req): JsonResponse {
        $data = $req->validate(['email'=>'required|email','password'=>'required|string']);
        $user = User::where('email',$data['email'])->with('tenant.plan')->first();
        if (!$user || !Hash::check($data['password'], $user->password_hash ?? $user->password ?? '')) {
            if ($user) { $user->increment('failed_login_count'); }
            return $this->unauthorized('Invalid credentials');
        }
        if ($user->isLocked()) return $this->unauthorized('Account locked');
        if (!$user->tenant || !$user->tenant->isAccessible()) return $this->forbidden('Account suspended');
        $user->update(['failed_login_count'=>0,'last_login_at'=>now(),'last_login_ip'=>$req->ip()]);
        $token = $user->createToken('dashboard',['*'],now()->addDays(30))->plainTextToken;
        return $this->ok([
            'token'  => $token,
            'user'   => $user->only(['id','name','email','role']),
            'tenant' => $user->tenant->only(['id','name','slug','status']),
        ]);
    }

    public function logout(Request $req): JsonResponse {
        $req->user()->currentAccessToken()->delete();
        return $this->ok(['message'=>'Logged out']);
    }

    public function me(Request $req): JsonResponse {
        $user = $req->user()->load('tenant.plan');
        return $this->ok([
            'user'   => $user->only(['id','name','email','role']),
            'tenant' => ['id'=>$user->tenant->id,'name'=>$user->tenant->name,'status'=>$user->tenant->status,'plan'=>$user->tenant->plan?->only(['name','slug','features']),'usage'=>['tokens'=>$user->tenant->usage_tokens_current,'messages'=>$user->tenant->usage_messages_current]],
        ]);
    }
}
