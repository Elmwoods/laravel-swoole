<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminIpAccessService;
use App\Services\Admin\AdminLoginEventService;
use App\Services\Admin\AdminLoginThrottleService;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminPermissionRegistry;
use App\Services\Admin\AdminSessionRegistryService;
use App\Services\Admin\AdminSessionSecurityService;
use App\Services\Admin\AdminTrustedDeviceService;
use App\Services\Admin\AdminTwoFactorService;
use App\Services\Ops\AlertCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 后台管理员认证控制器。
 *
 * 作用：承载后台「登录 / 二次验证(2FA) / 会话与设备管理 / 登出」整条认证流程，
 * 服务的主要后台路由包括：
 *  - GET  password-key         下发 RSA 公钥，供前端加密密码后再提交（passwordKey）
 *  - POST login                账号密码登录，含 IP 准入、限流、2FA 分流（login）
 *  - POST two-factor/confirm   首次启用 2FA 时确认绑定并生成恢复码（confirmTwoFactor）
 *  - POST two-factor/challenge 已启用 2FA 的登录校验（TOTP 或恢复码）（challengeTwoFactor）
 *  - GET  me                   返回当前登录管理员的资料与安全信息（me）
 *  - GET  login-history        登录历史（loginHistory）
 *  - GET/DELETE trusted-devices 可信设备的查看与吊销（trustedDevices/revokeTrustedDevice）
 *  - GET/DELETE sessions       活动会话的查看与吊销（activeSessions/revokeSession/revokeOtherSessions）
 *  - POST logout               登出并作废会话（logout）
 *
 * 「为什么」：登录不是简单的密码比对，而是一条带风控的管线——IP 准入 → 登录限流 →
 * 密码解密比对 → 账号状态检查 → 2FA（可信设备免检 / 首次绑定 / 常规挑战）→ 完成登录并
 * 记录会话/登录事件/异常告警。全流程各节点都写审计日志，便于安全追溯。
 */
class AdminAuthController extends Controller
{
    /**
     * 构造函数：通过依赖注入装配认证流程所需的全部领域服务。
     *
     * @param  AdminAuditService  $audit  审计日志服务，记录每个认证动作的成功/失败
     * @param  AdminLoginThrottleService  $throttle  登录限流服务，按邮箱+IP 统计失败次数并锁定
     * @param  AdminPasswordCryptoService  $passwordCrypto  密码加解密服务（前端 RSA 加密、后端解密）
     * @param  AdminPermissionRegistry  $permissions  权限注册表，同步系统默认权限/角色
     * @param  AdminSessionSecurityService  $sessions  会话安全服务，维护会话时间戳等
     * @param  AdminTwoFactorService  $twoFactor  二次验证服务（TOTP 密钥、验证码校验、恢复码）
     * @param  AdminLoginEventService  $loginEvents  登录事件记录与历史查询
     * @param  AdminTrustedDeviceService  $trustedDevices  可信设备签发/校验/吊销
     * @param  AlertCenterService  $alerts  告警中心，触发登录异常告警
     * @param  AdminSessionRegistryService  $sessionRegistry  会话注册表，管理多端活动会话
     * @param  AdminIpAccessService  $ipAccess  IP 准入服务，判断来源 IP 是否允许访问后台
     * @return void
     *
     * 「为什么」：认证逻辑横跨风控、加密、2FA、会话、审计等多个子域，采用构造注入
     * 而非在方法内 new，便于测试替身与关注点分离。
     */
    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly AdminLoginThrottleService $throttle,
        private readonly AdminPasswordCryptoService $passwordCrypto,
        private readonly AdminPermissionRegistry $permissions,
        private readonly AdminSessionSecurityService $sessions,
        private readonly AdminTwoFactorService $twoFactor,
        private readonly AdminLoginEventService $loginEvents,
        private readonly AdminTrustedDeviceService $trustedDevices,
        private readonly AlertCenterService $alerts,
        private readonly AdminSessionRegistryService $sessionRegistry,
        private readonly AdminIpAccessService $ipAccess,
    ) {}

    /**
     * 作用：下发用于前端加密登录密码的 RSA 公钥载荷。
     *
     * @return JsonResponse 包含公钥（及可能的密钥指纹）的成功响应
     *
     * 「为什么」：前端在提交前用该公钥加密密码，避免明文密码出现在请求体中，
     * 后端登录时再用私钥解密（见 login()）。
     */
    public function passwordKey(): JsonResponse
    {
        // 委托密码加密服务生成对外公钥载荷（含公钥内容等），前端据此加密密码。
        return $this->success($this->passwordCrypto->publicKeyPayload());
    }

    /**
     * 作用：后台账号密码登录入口，执行完整的风控与 2FA 分流管线。
     *
     * @param  AdminLoginRequest  $request  已校验的登录请求（含 email、加密后的密码载荷等）
     * @return JsonResponse 登录结果：可能是完成登录后的资料、要求 2FA 挑战、要求首次 2FA 绑定，
     *                      或各类失败响应（403 IP 拒绝 / 429 限流 / 422 凭证错误或账号禁用）
     *
     * 「为什么」：登录顺序刻意分层——先 IP 准入、再限流、再验密、再查账号状态，最后按 2FA 状态分流；
     * 每一步失败都单独记审计，既能防暴力破解也便于安全追溯。
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        // 同步系统默认权限/角色，确保后续权限判定基于最新注册表。
        $this->permissions->syncDefaults();
        $email = $request->validated('email');
        $ip = (string) $request->ip();

        // 第一道关卡：来源 IP 是否被 IP 准入策略（黑白名单/自动封禁）允许访问后台。
        if (! $this->ipAccess->allowedFor($ip)) {
            // IP 被拒绝：记审计（失败/403）并直接返回，不泄露账号是否存在。
            $this->audit->record($request, 'admin.auth', 'login_denied', 'failure', 403, message: 'ip_denied');

            return response()->json([
                'code' => 403,
                'message' => '当前网络环境不允许访问后台。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 403);
        }

        // 第二道关卡：同一 邮箱+IP 是否已达失败上限而被锁定。
        if ($this->throttle->tooManyAttempts($email, $ip)) {
            // 计算还需等待的秒数（至少 1 秒），提示前端稍后再试。
            $waitSeconds = max(1, $this->throttle->availableIn($email, $ip));
            $this->audit->record($request, 'admin.auth', 'login_locked', 'failure', 429, message: 'too_many_attempts');

            return response()->json([
                'code' => 429,
                'message' => "登录失败次数过多，请 {$waitSeconds} 秒后再试。",
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 429);
        }

        // 用私钥解密前端提交的密码载荷，得到明文密码用于比对。
        $password = $this->passwordCrypto->decryptPasswordFromPayload($request->validated());
        // 邮箱大小写不敏感查询管理员（LOWER 比对），避免因大小写导致找不到账号。
        $admin = AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        // 账号不存在或密码哈希不匹配：计一次失败、记审计，抛出统一的凭证错误。
        if (! $admin || ! Hash::check($password, $admin->password)) {
            // 累加失败计数（喂给限流器），达到阈值后会触发上面的锁定。
            $this->throttle->hit($email, $ip);
            $this->audit->record($request, 'admin.auth', 'login', 'failure', 422, message: 'invalid_credentials');

            // 统一错误文案：不区分「账号不存在」与「密码错误」，防止账号枚举。
            throw ValidationException::withMessages([
                'email' => ['登录邮箱或密码不正确。'],
            ]);
        }

        // 账号存在且密码正确，但账号被禁用：拒绝登录并记审计。
        if (! $admin->is_active) {
            $this->audit->record($request, 'admin.auth', 'login', 'failure', 422, admin: $admin, message: 'disabled');

            return response()->json([
                'message' => '后台账号已被禁用。',
                'errors' => ['email' => ['后台账号已被禁用。']],
            ], 422);
        }

        // 凭证有效：清空该 邮箱+IP 的失败计数，避免影响后续登录。
        $this->throttle->clear($email, $ip);

        // 已启用 2FA 的用户：先看本机是否为「可信设备」，可信则免二次验证直接放行。
        if ($admin->twoFactorEnabled()) {
            // 依据请求携带的可信设备 Cookie 查找一条有效（未过期未吊销）的可信设备记录。
            $device = $this->trustedDevices->findValid(
                $admin,
                $request->cookie(AdminTrustedDeviceService::COOKIE_NAME),
            );

            if ($device !== null) {
                // 命中可信设备：刷新其最后使用时间，记审计并直接完成登录（trusted=true）。
                $this->trustedDevices->touch($device, $request);
                $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin, payload: [
                    'trusted_device' => true,
                ]);

                return $this->success($this->completeLogin($request, $admin, true));
            }
        }

        // 未走可信设备快速通道：在会话中标记「待二次验证」状态（记录 admin_id 与时间戳）。
        $this->putPendingTwoFactorSession($request, $admin);

        // 情况一：尚未启用 2FA，引导首次绑定——生成 TOTP 密钥并暂存于会话待确认。
        if (! $admin->twoFactorEnabled()) {
            $secret = $this->twoFactor->generateSecret();
            // 密钥暂存会话，待 confirmTwoFactor() 用验证码确认后才正式启用。
            $request->session()->put('admin_two_factor_pending_secret', $secret);
            $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin, payload: [
                'requires_two_factor_setup' => true,
            ]);

            // 返回首次绑定所需信息：密钥与 otpauth URI（供前端生成二维码）。
            return $this->success([
                'requires_two_factor_setup' => true,
                'setup' => [
                    'secret' => $secret,
                    'otpauth_uri' => $this->twoFactor->otpauthUri($admin->email, $secret),
                ],
            ]);
        }

        // 情况二：已启用 2FA 且非可信设备，要求前端进入二次验证挑战流程。
        $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin, payload: [
            'requires_two_factor' => true,
        ]);

        return $this->success([
            'requires_two_factor' => true,
        ]);
    }

    /**
     * 作用：首次启用 2FA 时，用用户输入的验证码确认绑定，并生成恢复码后完成登录。
     *
     * @param  Request  $request  含 6 位验证码 code 及可选 trust_device 的请求
     * @return JsonResponse 成功时返回登录资料与一次性恢复码；会话失效返回 401；验证码错误抛 422
     *
     * 「为什么」：密钥在 login() 阶段仅暂存于会话，只有用户成功输入一次由该密钥生成的
     * TOTP 才真正启用（enable），确保用户的认证器已正确绑定；恢复码仅此一次明文返回。
     */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        // 从会话恢复「待二次验证」的管理员，以及暂存的待启用密钥。
        $admin = $this->pendingTwoFactorAdmin($request);
        $secret = (string) $request->session()->get('admin_two_factor_pending_secret', '');

        // 会话中缺少待验证管理员或密钥（超时/被清）：提示会话失效需重新登录。
        if (! $admin || $secret === '') {
            return $this->twoFactorExpiredResponse();
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'trust_device' => ['nullable', 'boolean'],
        ]);

        // 用暂存密钥校验验证码，返回命中的时间步（用于防重放），不匹配则为 null。
        $usedStep = $this->twoFactor->matchStep($secret, $data['code']);

        if ($usedStep === null) {
            // 验证码不正确：记审计（two_factor_setup 失败）并抛出校验异常。
            $this->audit->record($request, 'admin.auth', 'two_factor_setup', 'failure', 422, admin: $admin);

            throw ValidationException::withMessages([
                'code' => ['二次验证码不正确。'],
            ]);
        }

        // 生成一组一次性恢复码，随后正式启用 2FA（保存密钥、恢复码、已用时间步）。
        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $this->twoFactor->enable($admin, $secret, $recoveryCodes, $usedStep);
        $this->audit->record($request, 'admin.auth', 'two_factor_setup', 'success', 200, admin: $admin);
        // 若用户勾选「信任此设备」，签发可信设备 Cookie（refresh 取启用 2FA 后的最新状态）。
        $this->maybeIssueTrustedDevice($request, $admin->refresh());

        return $this->success([
            // 完成登录（建会话、记事件等），并把恢复码明文一次性返回给用户妥善保存。
            'profile' => $this->completeLogin($request, $admin->refresh()),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * 作用：对已启用 2FA 的账号执行登录二次验证（TOTP 验证码或一次性恢复码二选一）。
     *
     * @param  Request  $request  含 code（TOTP）或 recovery_code（恢复码），及可选 trust_device
     * @return JsonResponse 验证通过返回登录资料；会话失效返回 401；验证失败抛 422
     *
     * 「为什么」：code 与 recovery_code 互为「required_without」二选一——优先按 TOTP 校验，
     * 否则消费一枚恢复码（消费后即失效），二者都失败才拒绝，以覆盖丢失认证器的场景。
     */
    public function challengeTwoFactor(Request $request): JsonResponse
    {
        // 恢复「待二次验证」的管理员；若未启用 2FA 则该挑战不适用。
        $admin = $this->pendingTwoFactorAdmin($request);

        if (! $admin || ! $admin->twoFactorEnabled()) {
            return $this->twoFactorExpiredResponse();
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32', 'required_without:code'],
            'trust_device' => ['nullable', 'boolean'],
        ]);

        // 提供了 TOTP 则校验 TOTP；否则消费一枚恢复码（用后失效）。
        $verified = isset($data['code']) && $data['code'] !== ''
            ? $this->twoFactor->verifyLoginTotp($admin, $data['code'])
            : $this->twoFactor->consumeRecoveryCode($admin, (string) ($data['recovery_code'] ?? ''));

        if (! $verified) {
            $this->audit->record($request, 'admin.auth', 'two_factor_challenge', 'failure', 422, admin: $admin);

            // 依据用户提交的是哪种凭证，把错误定位到对应字段。
            $field = isset($data['recovery_code']) ? 'recovery_code' : 'code';

            throw ValidationException::withMessages([
                $field => ['二次验证码或恢复码不正确。'],
            ]);
        }

        $this->audit->record($request, 'admin.auth', 'two_factor_challenge', 'success', 200, admin: $admin);
        // 验证通过：按需签发可信设备，随后完成登录。
        $this->maybeIssueTrustedDevice($request, $admin);

        return $this->success($this->completeLogin($request, $admin->refresh()));
    }

    /**
     * 作用：返回当前已登录管理员（admin 守卫）的资料与安全信息。
     *
     * @return JsonResponse 当前管理员的 profile（角色、权限、安全摘要等）
     *
     * 「为什么」：前端刷新或进入后台时用于恢复「我是谁 / 我有什么权限」的上下文。
     */
    public function me(): JsonResponse
    {
        // 从 admin 守卫取当前登录用户，序列化为对外资料结构。
        return $this->success($this->profile(request()->user('admin')));
    }

    /**
     * 作用：返回当前管理员最近的登录历史（最多 20 条）。
     *
     * @param  Request  $request  当前请求（用于取 admin 守卫用户）
     * @return JsonResponse 登录事件列表
     */
    public function loginHistory(Request $request): JsonResponse
    {
        return $this->success([
            // 查询该管理员最近 20 条登录事件（时间、IP、设备、是否可信等）。
            'events' => $this->loginEvents->history($request->user('admin'), 20),
        ]);
    }

    /**
     * 作用：列出当前管理员的全部可信设备。
     *
     * @param  Request  $request  当前请求（用于取 admin 守卫用户）
     * @return JsonResponse 可信设备列表
     */
    public function trustedDevices(Request $request): JsonResponse
    {
        return $this->success([
            'devices' => $this->trustedDevices->list($request->user('admin')),
        ]);
    }

    /**
     * 作用：吊销当前管理员名下指定的一台可信设备。
     *
     * @param  Request  $request  当前请求（用于取 admin 守卫用户）
     * @param  int  $device  待吊销的可信设备 ID
     * @return JsonResponse 布尔字段 revoked 表示是否吊销成功
     *
     * 「为什么」：吊销结果决定审计的 result 与状态码（成功 200 / 未找到 404），
     * 便于安全事件追踪「谁在何时下线了哪台设备」。
     */
    public function revokeTrustedDevice(Request $request, int $device): JsonResponse
    {
        // 仅能吊销属于当前管理员自己的设备（服务内做归属校验）。
        $revoked = $this->trustedDevices->revoke($request->user('admin'), $device);

        $this->audit->record($request, 'admin.auth', 'trusted_device_revoke', $revoked ? 'success' : 'failure', $revoked ? 200 : 404, admin: $request->user('admin'), payload: [
            'device_id' => $device,
        ]);

        return $this->success([
            'revoked' => $revoked,
        ]);
    }

    /**
     * 作用：列出当前管理员的全部活动会话（多端在线情况）。
     *
     * @param  Request  $request  当前请求（用于取 admin 用户，并标记「当前会话」）
     * @return JsonResponse 活动会话列表
     *
     * 「为什么」：传入 $request 以便会话注册表标出哪一条是本次请求所属会话，
     * 前端可据此禁止用户吊销自己当前会话。
     */
    public function activeSessions(Request $request): JsonResponse
    {
        return $this->success([
            'sessions' => $this->sessionRegistry->list($request->user('admin'), $request),
        ]);
    }

    /**
     * 作用：吊销当前管理员名下指定的一个活动会话（强制某一端下线）。
     *
     * @param  Request  $request  当前请求（用于取 admin 用户）
     * @param  int  $session  待吊销的会话 ID
     * @return JsonResponse 布尔字段 revoked 表示是否吊销成功
     */
    public function revokeSession(Request $request, int $session): JsonResponse
    {
        // 吊销指定会话（服务内校验归属），结果决定审计 result 与状态码。
        $revoked = $this->sessionRegistry->revoke($request->user('admin'), $session);

        $this->audit->record($request, 'admin.auth', 'session_revoke', $revoked ? 'success' : 'failure', $revoked ? 200 : 404, admin: $request->user('admin'), payload: [
            'session_id' => $session,
        ]);

        return $this->success([
            'revoked' => $revoked,
        ]);
    }

    /**
     * 作用：吊销当前管理员「除本次请求会话外」的所有其他会话（一键下线其他端）。
     *
     * @param  Request  $request  当前请求（用于识别并保留当前会话）
     * @return JsonResponse revoked 为被吊销的会话数量
     *
     * 「为什么」：常用于「我怀疑账号在别处登录」场景——保留当前端、踢掉其余全部。
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        // 吊销除当前会话外的其余会话，返回被吊销数量。
        $revoked = $this->sessionRegistry->revokeOthers($request->user('admin'), $request);

        $this->audit->record($request, 'admin.auth', 'session_revoke_others', 'success', 200, admin: $request->user('admin'), payload: [
            'revoked' => $revoked,
        ]);

        return $this->success([
            'revoked' => $revoked,
        ]);
    }

    /**
     * 作用：登出当前管理员，清理会话注册表并作废本地会话。
     *
     * @return JsonResponse logged_out=true
     *
     * 「为什么」：顺序为先记审计→从注册表移除本会话→守卫登出→invalidate 作废会话→
     * 重新生成 CSRF token，确保旧会话 Cookie 与令牌彻底失效，防止会话复用。
     */
    public function logout(): JsonResponse
    {
        $request = request();
        $admin = $request->user('admin');

        // 先记登出审计（此时仍能拿到当前管理员），再逐步清理会话状态。
        $this->audit->record($request, 'admin.auth', 'logout', 'success', 200, admin: $admin);
        // 从会话注册表移除当前会话记录（活动会话列表不再显示它）。
        $this->sessionRegistry->forget($request);
        // admin 守卫登出。
        auth('admin')->logout();
        // 作废服务端会话并重建 CSRF token，使旧凭证彻底失效。
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(['logged_out' => true]);
    }

    /**
     * 作用：将管理员模型序列化为对外的资料结构（账号、角色、权限、安全摘要）。
     *
     * @param  AdminUser  $admin  目标管理员
     * @return array 包含 admin 基本信息、roles、permissions、security 四部分的数组
     *
     * 「为什么」：security 段汇聚了上次登录信息、当前请求 IP/UA、会话版本号及 2FA 摘要，
     * 供前端展示安全状态；会话版本号用于服务端校验会话是否因改密等原因失效。
     */
    private function profile(AdminUser $admin): array
    {
        // 预加载角色及其权限，避免序列化时 N+1 查询。
        $admin->load('roles.permissions');

        return [
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => $admin->is_active,
                'last_login_at' => optional($admin->last_login_at)->toDateTimeString(),
            ],
            'roles' => $admin->roles
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_active' => $role->is_active,
                ])
                ->values()
                ->all(),
            'permissions' => $admin->permissionSlugs(),
            'security' => [
                'last_login_at' => optional($admin->last_login_at)->toDateTimeString(),
                'last_login_ip' => $admin->last_login_ip,
                'last_login_user_agent' => $admin->last_login_user_agent,
                'current_ip' => request()->ip(),
                'current_user_agent' => $this->userAgentSummary(request()->userAgent()),
                'session_version' => (int) $admin->session_version,
                ...$this->twoFactor->securitySummary($admin),
            ],
        ];
    }

    /**
     * 作用：在会话中写入「待二次验证」中间态（记录管理员 ID 与发起时间戳）。
     *
     * @param  Request  $request  当前请求
     * @param  AdminUser  $admin  已通过密码验证、等待 2FA 的管理员
     * @return void
     *
     * 「为什么」：密码验证与 2FA 挑战是两次独立请求，需借助会话在两步之间安全地
     * 传递「已验密的用户」，pending_at 时间戳供上层判断挑战是否超时失效；
     * 同时清掉可能残留的待启用密钥，避免脏数据串流程。
     */
    private function putPendingTwoFactorSession(Request $request, AdminUser $admin): void
    {
        $request->session()->put('admin_two_factor_pending_admin_id', $admin->id);
        $request->session()->put('admin_two_factor_pending_at', now()->timestamp);
        // 清除任何遗留的待启用密钥，防止影响后续 confirm/challenge 判定。
        $request->session()->forget('admin_two_factor_pending_secret');
    }

    /**
     * 作用：当用户勾选「信任此设备」时，签发可信设备令牌并下发为 HttpOnly Cookie。
     *
     * @param  Request  $request  当前请求（提供 trust_device 标志及设备指纹来源）
     * @param  AdminUser  $admin  当前管理员
     * @return void
     *
     * 「为什么」：可信设备在后续登录可跳过 2FA（见 login()）；Cookie 设为 HttpOnly、
     * 生产环境 Secure、SameSite=lax，尽量降低被窃取风险，有效期由服务常量控制。
     */
    private function maybeIssueTrustedDevice(Request $request, AdminUser $admin): void
    {
        // 用户未勾选信任设备则不签发，直接返回。
        if (! $request->boolean('trust_device')) {
            return;
        }

        // 生成与该管理员+设备绑定的可信令牌。
        $token = $this->trustedDevices->issue($admin, $request);

        // 将令牌写入 Cookie：过期分钟数=天数*24*60；生产环境启用 Secure；HttpOnly 防脚本读取。
        Cookie::queue(Cookie::make(
            AdminTrustedDeviceService::COOKIE_NAME,
            $token,
            AdminTrustedDeviceService::LIFETIME_DAYS * 24 * 60,
            '/',
            null,
            app()->environment('production'),
            true,
            false,
            'lax',
        ));
    }

    /**
     * 作用：从会话中恢复处于「待二次验证」状态的管理员（且必须仍为启用状态）。
     *
     * @param  Request  $request  当前请求
     * @return AdminUser|null 命中的启用管理员；缺少标记或账号被禁用时返回 null
     *
     * 「为什么」：confirm/challenge 两个 2FA 入口都需先确认「上一步验密的是谁」，
     * 额外加 is_active 校验，防止在两步之间账号被禁用后仍能完成登录。
     */
    private function pendingTwoFactorAdmin(Request $request): ?AdminUser
    {
        $id = $request->session()->get('admin_two_factor_pending_admin_id');

        // 会话中没有待验证管理员 ID，说明未走过 login 或已失效。
        if (! $id) {
            return null;
        }

        return AdminUser::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * 作用：完成最终登录——建立认证会话、清理 2FA 中间态、注册会话、记录登录事件与异常告警、
     * 回写「最后登录」信息，并返回用户资料。
     *
     * @param  Request  $request  当前请求
     * @param  AdminUser  $admin  即将登录的管理员
     * @param  bool  $trusted  本次是否走可信设备免验通道（默认 false），会记入登录事件
     * @return array 序列化后的用户资料（见 profile()）
     *
     * 「为什么」：这是所有登录分支（可信设备/首次绑定/常规挑战）的汇合点。
     * regenerate 会话防会话固定攻击；写入 admin_session_version 与当前用户版本对齐，
     * 供后续中间件校验会话是否因改密等失效；登录事件用于风控与异常告警。
     */
    private function completeLogin(Request $request, AdminUser $admin, bool $trusted = false): array
    {
        // 正式登入 admin 守卫。
        auth('admin')->login($admin);
        // 重新生成会话 ID，防止会话固定（session fixation）攻击。
        $request->session()->regenerate();
        // 清理所有 2FA 中间态，登录完成后这些临时数据不再需要。
        $request->session()->forget([
            'admin_two_factor_pending_admin_id',
            'admin_two_factor_pending_secret',
            'admin_two_factor_pending_at',
        ]);
        // 记录会话版本号：与用户当前 session_version 对齐，改密后版本递增将使旧会话失效。
        $request->session()->put('admin_session_version', (int) $admin->session_version);
        // 刷新会话安全时间戳（如最近活跃时间）。
        $this->sessions->touch($request);

        // 在会话注册表登记本次会话（用于多端管理）。
        $this->sessionRegistry->register($admin, $request);
        // 记录一条登录事件（含是否可信设备），返回事件对象。
        $event = $this->loginEvents->record($admin, $request, $trusted);
        // 基于该登录事件评估并（必要时）触发登录异常告警（异地/异常设备等）。
        $this->alerts->raiseLoginAnomalyAlert($admin, $event);

        // 回写最后登录时间/IP/UA 摘要，供资料页与安全展示。
        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_login_user_agent' => $this->userAgentSummary($request->userAgent()),
        ])->save();

        return $this->profile($admin->refresh());
    }

    /**
     * 作用：构造统一的「二次验证会话已失效」401 响应。
     *
     * @return JsonResponse 固定结构的 401 响应，提示重新登录
     *
     * 「为什么」：confirm/challenge 在会话缺失或超时时复用此响应，保持前端处理一致。
     */
    private function twoFactorExpiredResponse(): JsonResponse
    {
        return response()->json([
            'code' => 401,
            'message' => '二次验证会话已失效，请重新登录。',
            'data' => null,
            'timestamp' => now()->timestamp,
        ], 401);
    }

    /**
     * 作用：清洗并截断 User-Agent 字符串，作为可安全存储/展示的摘要。
     *
     * @param  string|null  $userAgent  原始 UA 字符串，可能为 null
     * @return string 过滤敏感键、去除换行制表符并限长 180 字符后的 UA 摘要
     *
     * 「为什么」：UA 会被落库与回显，需过滤其中可能夹带的 token/password/authorization/cookie
     * 键值（脱敏为 [FILTERED]）并去掉 \r\n\t，防止日志注入并避免存储敏感信息。
     */
    private function userAgentSummary(?string $userAgent): string
    {
        $summary = (string) $userAgent;
        // 将敏感键值对脱敏，避免把凭证类信息写入日志/数据库。
        $summary = preg_replace('/(token|password|authorization|cookie)=([^;\s]+)/i', '$1=[FILTERED]', $summary) ?? $summary;
        // 去除换行/制表符，防止日志注入与展示错乱。
        $summary = preg_replace('/[\r\n\t]+/', ' ', $summary) ?? $summary;

        // 限长 180 字符，超出部分丢弃（无省略号）。
        return Str::limit($summary, 180, '');
    }
}
