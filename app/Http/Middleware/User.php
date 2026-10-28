<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Auth;
use Closure;
use Illuminate\Support\Facades\Cache;

class User
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!Auth::guard('sanctum')->check()) {
            throw new ApiException('未登录或登陆已过期', 403);
        }
        // Fix (v1.0.1): a ban must take effect immediately, not only at the
        // next login. Previously a banned user with a live Sanctum token
        // could keep calling user APIs until the token expired.
        $user = Auth::guard('sanctum')->user();
        if ($user && $user->banned) {
            throw new ApiException('账号已被封禁', 403);
        }
        return $next($request);
    }
}
