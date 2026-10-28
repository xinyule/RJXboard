<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RegisterService
{
    /**
     * 验证用户注册请求
     *
     * @param Request $request 请求对象
     * @return array [是否通过, 错误消息]
     */
    public function validateRegister(Request $request): array
    {
        // 检查IP注册限制
        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int) $registerCountByIP >= (int) admin_setting('register_limit_count', 3)) {
                return [
                    false,
                    [
                        429,
                        __('Register frequently, please try again after :minute minute', [
                            'minute' => admin_setting('register_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 检查验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return [false, $captchaError];
        }

        // 检查邮箱白名单
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            if (
                !Helper::emailSuffixVerify(
                    $request->input('email'),
                    admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)
                )
            ) {
                return [false, [400, __('Email suffix is not in the Whitelist')]];
            }
        }

        // 检查Gmail限制
        if ((int) admin_setting('email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                return [false, [400, __('Gmail alias is not supported')]];
            }
        }

        // 检查是否关闭注册
        if ((int) admin_setting('stop_register', 0)) {
            return [false, [400, __('Registration has closed')]];
        }

        // 检查邀请码要求
        if ((int) admin_setting('invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                return [false, [422, __('You must use the invitation code to register')]];
            }
        }

        // 检查邮箱验证
        if ((int) admin_setting('email_verify', 0)) {
            $emailCode = $request->input('email_code');
            if (!is_scalar($emailCode) || !preg_match('/^\d{6}$/', (string) $emailCode)) {
                return [false, [422, __('Email verification code cannot be empty')]];
            }

            $cachedEmailCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
            if ($cachedEmailCode === null || !hash_equals((string) $cachedEmailCode, (string) $emailCode)) {
                return [false, [400, __('Incorrect email verification code')]];
            }
        }

        // 检查邮箱是否存在
        $exist = User::byEmail($request->input('email'))->first();
        if ($exist) {
            return [false, [400201, __('Email already exists')]];
        }

        return [true, null];
    }

    /**
     * 处理邀请码
     *
     * @param string $inviteCode 邀请码
     * @return int|null 邀请人ID
     */
    public function handleInviteCode(string $inviteCode): int|null
    {
        // Fix (v1.0.1): lock the code row. This only serialises callers
        // when register() runs it inside a transaction (see below); a
        // lock outside a transaction would be released immediately.
        $inviteCodeModel = InviteCode::lockForUpdate()
            ->where('code', $inviteCode)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->first();

        if (!$inviteCodeModel) {
            if ((int) admin_setting('invite_force', 0)) {
                throw new ApiException(__('Invalid invitation code'));
            }
            return null;
        }

        if (!(int) admin_setting('invite_never_expire', 0)) {
            $inviteCodeModel->status = InviteCode::STATUS_USED;
            $inviteCodeModel->save();
        }

        return $inviteCodeModel->user_id;
    }



    /**
     * 注册用户
     *
     * @param Request $request 请求对象
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function register(Request $request): array
    {
        // 验证注册数据
        [$valid, $error] = $this->validateRegister($request);
        if (!$valid) {
            return [false, $error];
        }

        HookManager::call('user.register.before', $request);

        $email = $request->input('email');
        $password = $request->input('password');
        $inviteCode = $request->input('invite_code');

        // Fix (v1.0.1): mark the invite code and create the user in one
        // transaction. Previously the code was marked USED before the
        // user was created, so two concurrent requests could both read
        // it as unused, and a failed user save burned the code for good.
        $user = null;
        try {
            $user = DB::transaction(function () use ($email, $password, $inviteCode) {
                $inviteUserId = null;
                if ($inviteCode) {
                    $inviteUserId = $this->handleInviteCode($inviteCode);
                }

                $userService = app(UserService::class);
                $created = $userService->createUser([
                    'email' => $email,
                    'password' => $password,
                    'invite_user_id' => $inviteUserId,
                ]);

                if (!$created->save()) {
                    throw new ApiException(__('Register failed'), 500);
                }
                return $created;
            });
        } catch (ApiException $e) {
            return [false, [$e->getCode() ?: 500, $e->getMessage()]];
        }

        HookManager::call('user.register.after', $user);

        // 清除邮箱验证码
        if ((int) admin_setting('email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        }

        // 更新最近登录时间
        $user->last_login_at = time();
        $user->save();

        // 更新IP注册计数
        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int) $registerCountByIP + 1,
                (int) admin_setting('register_limit_expire', 60) * 60
            );
        }

        return [true, $user];
    }
}