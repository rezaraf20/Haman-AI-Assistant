<?php namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class BaseApiController extends Controller {
    protected function ok(mixed $data): JsonResponse      { return response()->json(['status'=>'ok','data'=>$data]); }
    protected function created(mixed $data): JsonResponse  { return response()->json(['status'=>'created','data'=>$data],201); }
    protected function accepted(mixed $data): JsonResponse { return response()->json(['status'=>'accepted','data'=>$data],202); }
    protected function noContent(): JsonResponse           { return response()->json(null,204); }
    protected function badRequest(string $m='Bad request'): JsonResponse      { return response()->json(['error'=>$m],400); }
    protected function unauthorized(string $m='Unauthenticated'): JsonResponse { return response()->json(['error'=>$m],401); }
    protected function forbidden(string $m='Forbidden'): JsonResponse          { return response()->json(['error'=>$m],403); }
    protected function notFound(string $m='Not found'): JsonResponse           { return response()->json(['error'=>$m],404); }
    protected function tooManyRequests(string $m='Too many requests', int $retryAfter=0): JsonResponse {
        return response()->json(['error'=>$m,'retry_after_seconds'=>$retryAfter],429);
    }
}
