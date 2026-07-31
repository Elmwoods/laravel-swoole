<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * 告警通知服务（外发环节 notify）。
 *
 * 在 Ops Center 告警管线中的位置：AlertCenterService 完成 raise（入库/去重/聚合）后，
 * 由本类负责把已成型的 OpsAlert「向外投递」到各 IM/邮件/Webhook 通道，
 * 广播（broadcast，前端实时推送）由调用方另行处理，本类只管外部通道。
 *
 * 关键约束：
 * - 通道单一来源于 config('ops.alerts.channels')，各处遍历该列表，避免多处硬编码通道清单；
 * - 未配置凭据时只记录/跳过，不抛异常、不阻塞采集主流程（发送失败绝不能拖垮 raise）；
 * - send() 是唯一对外投递入口（choke point），三道抑制门（静默/关联抑制/抖动）都收敛在此。
 */
class AlertNotificationService
{
    /**
     * 作用：注入外发前需要咨询的两个抑制判定服务。
     *
     * 「为什么」：静默与关联抑制的判定逻辑各自独立成服务，这里以只读依赖注入，
     * 使 send() 能在真正外发前先问一遍「这条该不该发」。
     *
     * @param  AlertSilenceService  $silences  值班静默窗口判定
     * @param  AlertCorrelationService  $correlation  父子来源关联抑制判定
     */
    public function __construct(
        private readonly AlertSilenceService $silences,
        private readonly AlertCorrelationService $correlation,
    ) {}

    /**
     * 每个通道判定 configured/missing 所需的凭据字段（config 键，相对 ops.alerts.<channel>）。
     */
    private const CHANNEL_CREDENTIALS = [
        'telegram' => ['bot_token', 'chat_id'],
        'mail' => ['to'],
        'webhook' => ['url'],
        'dingtalk' => ['webhook'],
        'feishu' => ['webhook'],
    ];

    /**
     * 作用：返回系统支持/启用的通知通道清单（全局单一来源）。
     *
     * 「为什么」：所有遍历通道的地方（status/send/probe）都调这里，
     * 保证「有哪些通道」只在一处定义，改配置即可全局生效。
     *
     * @return array<int, string> 通道名数组，缺省回退到 telegram + mail
     */
    public function channels(): array
    {
        // array_values 抹掉配置里可能的字符串键，统一成有序列表
        return array_values((array) config('ops.alerts.channels', ['telegram', 'mail']));
    }

    /**
     * 作用：汇总各通道的「是否启用 / 是否配置完整 / 缺哪些配置项」，供运维前端展示。
     *
     * 「为什么」：安全考虑——只返回布尔状态与缺失项名称，绝不回传
     * token、chat_id、邮箱、webhook、secret 等敏感明文值。
     *
     * @return array<string, mixed> 每通道 {enabled, configured, missing[]}，外加 checked_at 时间戳
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->channels() as $channel) {
            // config 层开关：环境/配置文件里是否启用该通道
            $configEnabled = (bool) config("ops.alerts.{$channel}.enabled", false);
            // 运行时开关：DB 设置表里的人工总闸（可临时关某通道而不改配置）
            $toggle = (bool) OpsAlertSetting::value("{$channel}_enabled");
            // config 未启用时，'enabled' 本身即算一项缺失
            $missing = $configEnabled ? [] : ['enabled'];
            $allPresent = true;

            // 逐个检查该通道声明的必需凭据字段是否齐全
            foreach (self::CHANNEL_CREDENTIALS[$channel] ?? [] as $credential) {
                if ($this->credentialMissing($channel, $credential)) {
                    $missing[] = $credential;
                    $allPresent = false;
                }
            }

            $status[$channel] = [
                // enabled = config 开 且 运行时闸也开
                'enabled' => $configEnabled && $toggle,
                // configured = config 开 且 凭据齐全（与运行时闸无关）
                'configured' => $configEnabled && $allPresent,
                'missing' => array_values($missing),
            ];
        }

        $status['checked_at'] = now()->toDateTimeString();

        return $status;
    }

    /**
     * 作用：告警外发的唯一入口（choke point），依次过三道抑制门后逐通道投递。
     *
     * 三道门的顺序即优先级：静默 > 关联抑制 > 抖动；任一命中都短路返回，
     * 结果里带 reason 说明为何未发（silenced/suppressed/flapping）。
     *
     * 「为什么」把三道门都收在这里：保证「入库/广播照常、只是不外发」这一语义
     * 在所有调用路径上一致，调用方无需各自判断。
     *
     * @param  OpsAlert  $alert  已成型（已 raise 入库）的告警
     * @param  array<int, string>|null  $channels  收窄到指定通道集；null=全部启用通道
     * @return array<string, array> 每通道的投递结果 {enabled, sent, reason?/status?}
     */
    public function send(OpsAlert $alert, ?array $channels = null): array
    {
        // 可选 $channels：收窄到指定通道集（如升级级别专属通道）；null=全部启用通道。
        $targets = $channels === null
            ? $this->channels()
            : array_values(array_intersect($this->channels(), $channels));

        // 值班静默窗口：命中则只入库/广播（由调用方完成），不外发到任何通道。
        if ($this->silences->isSilenced($alert)) {
            return collect($targets)
                ->mapWithKeys(fn (string $channel): array => [$channel => ['enabled' => true, 'sent' => false, 'reason' => 'silenced']])
                ->all();
        }

        // 关联抑制：父来源正在 firing 时，抑制子来源告警外发（仍入库/广播）。
        if ($this->correlation->isSuppressed($alert)) {
            return collect($targets)
                ->mapWithKeys(fn (string $channel): array => [$channel => ['enabled' => true, 'sent' => false, 'reason' => 'suppressed']])
                ->all();
        }

        // 抖动抑制：flapping 冷却期内只入库/广播，不外发（行内读取 flapping_until，无需查库）。
        if ((bool) config('ops.alerts.flapping.enabled', false)
            && $alert->flapping_until !== null
            && $alert->flapping_until->isFuture()) {
            return collect($targets)
                ->mapWithKeys(fn (string $channel): array => [$channel => ['enabled' => true, 'sent' => false, 'reason' => 'flapping']])
                ->all();
        }

        $result = [];

        foreach ($targets as $channel) {
            $result[$channel] = $this->dispatch($channel, $alert);
        }

        return $result;
    }

    /**
     * 作用：发送一条测试通知，用于人工验证通道凭据是否可用。
     *
     * 「为什么」构造内存态 OpsAlert 而不落库：测试不应污染真实告警表，
     * 只借用 dispatch 的通道投递逻辑走一遍真实链路。
     *
     * @param  array<int, string>  $channels  目标通道（空=全部启用通道），会与支持列表取交集
     * @param  string|null  $message  自定义正文，null 用默认测试语
     * @return array<string, array> 每通道投递结果
     */
    public function sendTest(array $channels = [], ?string $message = null): array
    {
        $supported = $this->channels();
        $channels = $channels ?: $supported;
        // 与支持列表取交集，剔除调用方传入的未知/未启用通道
        $channels = array_values(array_intersect($channels, $supported));

        // 内存态告警：不入库，仅承载文本供 dispatch 投递
        $alert = new OpsAlert([
            'source' => 'notification-test',
            'severity' => 'info',
            'title' => 'Ops Center 通知测试',
            'message' => $message ?: '这是一条 Ops Center 告警通知测试消息。',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $result = [];

        foreach ($channels as $channel) {
            $result[$channel] = $this->dispatch($channel, $alert);
        }

        return $result;
    }

    /**
     * 作用：告警被指派/认领时，向被指派人推送一条 info 级通知。
     *
     * 复用内存态 OpsAlert + dispatch；severity=info 会走严重级矩阵的 info 行。
     * config 未开时 no-op，且始终不落库。
     *
     * 「为什么」不走 send()：这是一条派生通知而非告警本身，
     * 不需要过静默/抑制门，只需按通道矩阵直接投递。
     *
     * @param  OpsAlert  $alert  被指派的原告警（仅取标题/来源做文案）
     * @param  string  $assignee  被指派人标识
     * @return array<string, array> 每通道投递结果；未开启时为空数组
     */
    public function sendAssignment(OpsAlert $alert, string $assignee): array
    {
        // config 开关未开：整个功能 no-op，直接返回空
        if (! (bool) config('ops.alerts.assignment_notify.enabled', false)) {
            return [];
        }

        $notice = new OpsAlert([
            'source' => 'alert-assignment',
            'severity' => 'info',
            'title' => '告警已指派',
            'message' => "「{$alert->title}」（来源 {$alert->source}）已指派给 {$assignee}",
            'status' => (string) $alert->status,
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $result = [];

        foreach ($this->channels() as $channel) {
            $result[$channel] = $this->dispatch($channel, $notice);
        }

        return $result;
    }

    /**
     * 作用：值班上岗提醒推送，提醒下一班值班人做好交接准备。
     *
     * config 未开时 no-op，不落库；同样复用 dispatch 逐通道投递。
     *
     * @param  string  $assignee  即将上岗的值班人
     * @param  string  $startsAt  上岗时间（已格式化字符串）
     * @return array<string, array> 每通道投递结果；未开启时为空数组
     */
    public function sendOnCallReminder(string $assignee, string $startsAt): array
    {
        // config 开关未开：no-op
        if (! (bool) config('ops.alerts.on_call_reminder.enabled', false)) {
            return [];
        }

        $notice = new OpsAlert([
            'source' => 'on-call-reminder',
            'severity' => 'info',
            'title' => '值班上岗提醒',
            'message' => "{$assignee}，你将于 {$startsAt} 上岗值班，请做好交接准备。",
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $result = [];

        foreach ($this->channels() as $channel) {
            $result[$channel] = $this->dispatch($channel, $notice);
        }

        return $result;
    }

    /**
     * 作用：按通道名把告警路由到对应的具体发送方法。
     *
     * 「为什么」用 match + 单一入口：所有「发一条告警」的路径都经此收敛，
     * 未知通道统一回落到 unknown_channel，而不是各调用点各自判断。
     *
     * @param  string  $channel  通道名
     * @param  OpsAlert  $alert  待发送告警
     * @return array<string, mixed> 该通道发送结果
     */
    private function dispatch(string $channel, OpsAlert $alert): array
    {
        return match ($channel) {
            'telegram' => $this->sendTelegram($alert),
            'mail' => $this->sendMail($alert),
            'webhook' => $this->sendWebhook($alert),
            'dingtalk' => $this->sendDingtalk($alert),
            'feishu' => $this->sendFeishu($alert),
            default => ['enabled' => false, 'sent' => false, 'reason' => 'unknown_channel'],
        };
    }

    /**
     * 作用：批量对多个通道做连通性探测（健康自检）。
     *
     * 「为什么」静默探测：尽量不投递用户可见告警，避免自检刷屏；
     * 钉钉/飞书没有静默 ping API，只能发一条明确标注的轻量心跳。
     *
     * @param  array<int, string>  $channels  目标通道（空=全部启用通道）
     * @return array<string, array{healthy: bool, reason?: string, checked_via: string}> 每通道健康结果
     */
    public function probeChannels(array $channels = []): array
    {
        $supported = $this->channels();
        $channels = $channels ?: $supported;
        $channels = array_values(array_intersect($channels, $supported));

        $result = [];

        foreach ($channels as $channel) {
            $result[$channel] = $this->probeChannel($channel);
        }

        return $result;
    }

    /**
     * 作用：探测单个通道连通性，先过一串「跳过」前置检查，再做真实探测。
     *
     * 「为什么」checked_via='skipped'：未启用/未配置的通道不该算「不健康」，
     * 用 skipped 标记让上层健康统计把它们剔除，而不是记为故障。
     *
     * @param  string  $channel  通道名
     * @return array{healthy: bool, reason?: string, checked_via: string} 健康结果
     */
    public function probeChannel(string $channel): array
    {
        // config 未启用：跳过（不计入健康统计）
        if (! (bool) config("ops.alerts.{$channel}.enabled", false)) {
            return ['healthy' => false, 'reason' => 'not_enabled', 'checked_via' => 'skipped'];
        }

        // 运行时总闸关闭：跳过
        if (! (bool) OpsAlertSetting::value("{$channel}_enabled")) {
            return ['healthy' => false, 'reason' => 'disabled_by_toggle', 'checked_via' => 'skipped'];
        }

        // 凭据不全：无从探测，跳过并指明缺哪一项
        foreach (self::CHANNEL_CREDENTIALS[$channel] ?? [] as $credential) {
            if ($this->credentialMissing($channel, $credential)) {
                return ['healthy' => false, 'reason' => "missing_{$credential}", 'checked_via' => 'skipped'];
            }
        }

        return match ($channel) {
            'telegram' => $this->probeTelegram(),
            'mail' => $this->probeMail(),
            'webhook' => $this->probeWebhook(),
            'dingtalk' => $this->probeDingtalk(),
            'feishu' => $this->probeFeishu(),
            default => ['healthy' => false, 'reason' => 'unknown_channel', 'checked_via' => 'skipped'],
        };
    }

    /**
     * 作用：每个具体通道发送方法开头的统一守卫，按序检查三项前置条件。
     *
     * 顺序：设置表可用 → 严重级策略允许该通道 → config 已启用。
     *
     * 「为什么」返回值语义：null 表示三关全过、可继续发送；
     * 非 null 则是「应原样回传给调用方」的短路结果，避免每个 sendXxx 重复写这三段。
     * settingsAvailable 放第一位是 boot-safe 考虑——迁移未跑时设置表不存在，不能直接查。
     *
     * @param  OpsAlert  $alert  待发送告警（用于查严重级矩阵）
     * @param  string  $channel  通道名
     * @return array<string, mixed>|null null=放行；否则为短路结果
     */
    private function guard(OpsAlert $alert, string $channel): ?array
    {
        // boot-safe：设置表不存在（迁移未跑）时不外发，避免查表报错
        if (! $this->settingsAvailable()) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'settings_unavailable'];
        }

        // 严重级 x 通道矩阵：该级别下此通道被人工关掉则不发
        if (! $this->channelAllowed($alert, $channel)) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'channel_disabled_by_policy'];
        }

        // config 层未启用：enabled=false，明确区别于「启用了但没发成」
        if (! (bool) config("ops.alerts.{$channel}.enabled", false)) {
            return ['enabled' => false, 'sent' => false];
        }

        return null;
    }

    /**
     * 作用：向 Telegram Bot 发送一条告警消息。
     *
     * @param  OpsAlert  $alert  待发送告警
     * @return array<string, mixed> 发送结果 {enabled, sent, status?/reason?}
     */
    private function sendTelegram(OpsAlert $alert): array
    {
        // 统一守卫：任一前置不满足则原样短路
        if ($guard = $this->guard($alert, 'telegram')) {
            return $guard;
        }

        $token = (string) config('ops.alerts.telegram.bot_token', '');
        $chatId = (string) config('ops.alerts.telegram.chat_id', '');

        // 凭据缺失兜底（guard 之外的二次防御）
        if ($token === '' || $chatId === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'telegram_not_configured'];
        }

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->formatMessage($alert, 'telegram'),
                'disable_web_page_preview' => true,
            ]);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('telegram', $alert, $e);
        }
    }

    /**
     * 作用：以纯文本邮件发送告警，主题带级别与标题。
     *
     * @param  OpsAlert  $alert  待发送告警
     * @return array<string, mixed> 发送结果
     */
    private function sendMail(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'mail')) {
            return $guard;
        }

        // 收件人列表过滤空值后取值；全空视为未配置
        $to = array_values(array_filter((array) config('ops.alerts.mail.to', [])));

        if ($to === []) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'mail_recipient_empty'];
        }

        try {
            Mail::raw($this->formatMessage($alert, 'mail'), function ($message) use ($alert, $to): void {
                $message->to($to)->subject("[Ops Center][{$alert->severity}] {$alert->title}");
            });

            return ['enabled' => true, 'sent' => true];
        } catch (Throwable $e) {
            return $this->failure('mail', $alert, $e);
        }
    }

    /**
     * 作用：向通用 Webhook 端点 POST 结构化 JSON 告警，可选带 HMAC-SHA256 签名头。
     *
     * 「为什么」签名：接收端可用共享 secret 校验 X-Ops-Signature，防止伪造投递。
     *
     * @param  OpsAlert  $alert  待发送告警
     * @return array<string, mixed> 发送结果
     */
    private function sendWebhook(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'webhook')) {
            return $guard;
        }

        $url = (string) config('ops.alerts.webhook.url', '');

        if ($url === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'webhook_not_configured'];
        }

        $secret = (string) config('ops.alerts.webhook.secret', '');
        // 对原始 body 字节做签名，故先固定序列化再据此计算 HMAC
        $body = json_encode($this->webhookPayload($alert), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $request = Http::timeout(5)->withBody($body, 'application/json');

            // 配了 secret 才加签名头，对同一段 body 计算 HMAC
            if ($secret !== '') {
                $request = $request->withHeaders(['X-Ops-Signature' => hash_hmac('sha256', $body, $secret)]);
            }

            $response = $request->post($url);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('webhook', $alert, $e);
        }
    }

    /**
     * 作用：向钉钉群机器人发送 text 消息告警，可选加签。
     *
     * 「为什么」加签方式：钉钉要求 sign = base64(HMAC-SHA256(timestamp\nsecret))，
     * timestamp 用毫秒；timestamp 与 sign 拼到 webhook URL 的查询串上。
     *
     * @param  OpsAlert  $alert  待发送告警
     * @return array<string, mixed> 发送结果
     */
    private function sendDingtalk(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'dingtalk')) {
            return $guard;
        }

        $webhook = (string) config('ops.alerts.dingtalk.webhook', '');

        if ($webhook === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'dingtalk_not_configured'];
        }

        $secret = (string) config('ops.alerts.dingtalk.secret', '');
        $url = $webhook;

        // 配了 secret 才加签：毫秒时间戳 + HMAC 签名拼到 URL
        if ($secret !== '') {
            $timestamp = (string) (int) round(microtime(true) * 1000);
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            // 已有查询串用 & 续接，否则用 ? 起始；sign 需 URL 编码
            $url .= (str_contains($url, '?') ? '&' : '?').'timestamp='.$timestamp.'&sign='.rawurlencode($sign);
        }

        try {
            $response = Http::timeout(5)->post($url, [
                'msgtype' => 'text',
                'text' => ['content' => $this->formatMessage($alert, 'dingtalk')],
            ]);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('dingtalk', $alert, $e);
        }
    }

    /**
     * 作用：向飞书群机器人发送 text 消息告警，可选加签。
     *
     * 「为什么」与钉钉不同：飞书用秒级 timestamp，且 sign 是以
     * "timestamp\nsecret" 为 HMAC 密钥、对空串签名；timestamp 与 sign 放进请求体而非 URL。
     *
     * @param  OpsAlert  $alert  待发送告警
     * @return array<string, mixed> 发送结果
     */
    private function sendFeishu(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'feishu')) {
            return $guard;
        }

        $webhook = (string) config('ops.alerts.feishu.webhook', '');

        if ($webhook === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'feishu_not_configured'];
        }

        $secret = (string) config('ops.alerts.feishu.secret', '');
        $payload = [
            'msg_type' => 'text',
            'content' => ['text' => $this->formatMessage($alert, 'feishu')],
        ];

        // 配了 secret 才加签：秒级时间戳，签名与 timestamp 一并放进请求体
        if ($secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            // 注意：飞书是对空串签名，密钥为 timestamp\nsecret
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp."\n".$secret, true));
        }

        try {
            $response = Http::timeout(5)->post($webhook, $payload);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('feishu', $alert, $e);
        }
    }

    /**
     * 作用：Telegram 静默探测，调用 getMe 验证 token 与网络连通。
     *
     * 「为什么」用 getMe：它只读机器人自身信息、不向任何 chat 投递消息，
     * 适合做健康自检而不打扰用户。
     *
     * @return array{healthy: bool, reason?: string, checked_via: string}
     */
    private function probeTelegram(): array
    {
        $token = (string) config('ops.alerts.telegram.bot_token', '');

        try {
            $response = Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getMe");
            $ok = $response->successful() && (bool) data_get($response->json(), 'ok', false);

            return $ok
                ? ['healthy' => true, 'checked_via' => 'getMe']
                : ['healthy' => false, 'reason' => 'telegram_getme_status_'.$response->status(), 'checked_via' => 'getMe'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'getMe'];
        }
    }

    /**
     * 作用：邮件静默探测，仅 start/stop SMTP 传输以验证连接可建立，不投递邮件。
     *
     * 「为什么」非 SMTP 驱动跳过：log/array 等驱动没有可探测的远端连接，
     * 探测无意义，标 skipped 不计入健康统计。
     *
     * @return array{healthy: bool, reason?: string, checked_via: string}
     */
    private function probeMail(): array
    {
        try {
            $transport = Mail::mailer()->getSymfonyTransport();

            if (! $transport instanceof SmtpTransport) {
                return ['healthy' => false, 'reason' => 'non_smtp_driver', 'checked_via' => 'skipped'];
            }

            $transport->start();
            $transport->stop();

            return ['healthy' => true, 'checked_via' => 'smtp'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'smtp'];
        }
    }

    /**
     * 作用：Webhook 探测，POST 一个最小健康载荷（event=ops_health_check）到端点。
     *
     * 「为什么」用专门的 health_check 事件：接收端可据此识别并忽略，
     * 不会与真实告警混淆；同样按配置附带签名头。
     *
     * @return array{healthy: bool, reason?: string, checked_via: string}
     */
    private function probeWebhook(): array
    {
        $url = (string) config('ops.alerts.webhook.url', '');
        $secret = (string) config('ops.alerts.webhook.secret', '');
        $body = json_encode(['event' => 'ops_health_check', 'time' => now()->toDateTimeString()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $request = Http::timeout(5)->withBody($body, 'application/json');

            if ($secret !== '') {
                $request = $request->withHeaders(['X-Ops-Signature' => hash_hmac('sha256', $body, $secret)]);
            }

            $response = $request->post($url);

            return $response->successful()
                ? ['healthy' => true, 'checked_via' => 'http_post']
                : ['healthy' => false, 'reason' => 'webhook_status_'.$response->status(), 'checked_via' => 'http_post'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'http_post'];
        }
    }

    /**
     * 作用：钉钉探测，发一条明确标注的轻量心跳消息，据 errcode 判连通。
     *
     * 「为什么」发心跳而非静默 ping：钉钉群机器人无只读探测接口，
     * 只能真发一条（文案已注明「请忽略」）；errcode==0 才算连通。
     *
     * @return array{healthy: bool, reason?: string, checked_via: string}
     */
    private function probeDingtalk(): array
    {
        $webhook = (string) config('ops.alerts.dingtalk.webhook', '');
        $secret = (string) config('ops.alerts.dingtalk.secret', '');
        $url = $webhook;

        if ($secret !== '') {
            $timestamp = (string) (int) round(microtime(true) * 1000);
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            $url .= (str_contains($url, '?') ? '&' : '?').'timestamp='.$timestamp.'&sign='.rawurlencode($sign);
        }

        try {
            $response = Http::timeout(5)->post($url, [
                'msgtype' => 'text',
                'text' => ['content' => $this->heartbeatText()],
            ]);

            // 优先取响应体 errcode；缺字段时用 HTTP 成功与否兜底为 0/-1
            $errcode = (int) data_get($response->json(), 'errcode', $response->successful() ? 0 : -1);

            return ($response->successful() && $errcode === 0)
                ? ['healthy' => true, 'checked_via' => 'heartbeat']
                : ['healthy' => false, 'reason' => 'dingtalk_errcode_'.$errcode, 'checked_via' => 'heartbeat'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'heartbeat'];
        }
    }

    /**
     * 作用：飞书探测，发一条明确标注的轻量心跳消息，据 code 判连通。
     *
     * 「为什么」同钉钉：飞书群机器人也无只读探测接口；飞书成功码字段是 code==0。
     *
     * @return array{healthy: bool, reason?: string, checked_via: string}
     */
    private function probeFeishu(): array
    {
        $webhook = (string) config('ops.alerts.feishu.webhook', '');
        $secret = (string) config('ops.alerts.feishu.secret', '');
        $payload = [
            'msg_type' => 'text',
            'content' => ['text' => $this->heartbeatText()],
        ];

        if ($secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp."\n".$secret, true));
        }

        try {
            $response = Http::timeout(5)->post($webhook, $payload);
            // 优先取响应体 code；缺字段时用 HTTP 成功与否兜底
            $code = (int) data_get($response->json(), 'code', $response->successful() ? 0 : -1);

            return ($response->successful() && $code === 0)
                ? ['healthy' => true, 'checked_via' => 'heartbeat']
                : ['healthy' => false, 'reason' => 'feishu_code_'.$code, 'checked_via' => 'heartbeat'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'heartbeat'];
        }
    }

    /**
     * 作用：心跳探测消息文案（明确标注请忽略，避免误当真实告警）。
     *
     * @return string 心跳文案
     */
    private function heartbeatText(): string
    {
        return 'Ops Center 告警通道健康自检：连通正常，请忽略。';
    }

    /**
     * 作用：通道发送抛异常时的统一收尾——记 warning 日志并返回失败结果。
     *
     * 「为什么」不外抛异常：单通道失败不应中断其它通道，也不能拖垮采集主流程，
     * 故吞掉异常、脱敏后落日志，向调用方返回 sent=false。
     *
     * @param  string  $channel  出错的通道名
     * @param  OpsAlert  $alert  相关告警（取 id 记日志）
     * @param  Throwable  $e  捕获到的异常
     * @return array<string, mixed> 失败结果 {enabled:true, sent:false, reason}
     */
    private function failure(string $channel, OpsAlert $alert, Throwable $e): array
    {
        Log::warning("Ops alert {$channel} notification failed", [
            'alert_id' => $alert->id,
            // 日志同样走脱敏，避免把 token 等写进日志
            'message' => $this->safeExceptionMessage($e),
        ]);

        return ['enabled' => true, 'sent' => false, 'reason' => "{$channel}_exception"];
    }

    /**
     * 作用：构造 Webhook 的结构化 JSON 载荷（含 fingerprint，便于接收端去重）。
     *
     * 「为什么」不含凭据：载荷只带告警本身字段，任何 token/secret 都不进 body。
     *
     * @param  OpsAlert  $alert  告警
     * @return array<string, mixed> 载荷数组
     */
    private function webhookPayload(OpsAlert $alert): array
    {
        return [
            'title' => $alert->title,
            'severity' => $alert->severity,
            'source' => $alert->source,
            'status' => $alert->status,
            'message' => $alert->message,
            'time' => optional($alert->last_seen_at)->toDateTimeString(),
            'fingerprint' => $alert->fingerprint,
        ];
    }

    /**
     * 作用：把告警渲染成通知文本，支持自定义模板与占位符替换。
     *
     * 「为什么」try/catch 包住模板读取：设置表不可用（boot 阶段）时不能因此发不出消息，
     * 读模板失败就回落到内置格式。
     *
     * @param  OpsAlert  $alert  告警
     * @param  string  $channel  通道名（用于挑选通道专属模板，空串则只用全局模板）
     * @return string 渲染后的通知正文
     */
    private function formatMessage(OpsAlert $alert, string $channel = ''): string
    {
        $template = '';

        try {
            // 回退链：该通道专属模板 → 全局模板 → 内置。
            if ($channel !== '') {
                $template = trim((string) OpsAlertSetting::value("message_template_{$channel}"));
            }

            if ($template === '') {
                $template = trim((string) OpsAlertSetting::value('message_template'));
            }
        } catch (Throwable) {
            $template = '';
        }

        if ($template !== '') {
            return strtr($template, [
                '{title}' => (string) $alert->title,
                '{severity}' => (string) $alert->severity,
                '{source}' => (string) $alert->source,
                '{status}' => (string) $alert->status,
                '{time}' => optional($alert->last_seen_at)->toDateTimeString() ?? '',
                '{message}' => (string) $alert->message,
            ]);
        }

        return implode(PHP_EOL, [
            "Ops Center 告警：{$alert->title}",
            "级别：{$alert->severity}",
            "来源：{$alert->source}",
            "状态：{$alert->status}",
            '时间：'.optional($alert->last_seen_at)->toDateTimeString(),
            "说明：{$alert->message}",
        ]);
    }

    /**
     * 作用：判断某告警在「严重级 x 通道」策略矩阵下是否允许走该通道。
     *
     * 「为什么」两层判断：先看运行时总闸，再查矩阵单元格；
     * 矩阵缺该单元格时默认 true（放行），即未显式关闭即视为允许。
     *
     * @param  OpsAlert  $alert  告警（取 severity，空则按 warning）
     * @param  string  $channel  通道名
     * @return bool 是否允许该通道外发
     */
    private function channelAllowed(OpsAlert $alert, string $channel): bool
    {
        // 运行时总闸关闭：直接不允许
        if (! (bool) OpsAlertSetting::value("{$channel}_enabled")) {
            return false;
        }

        $matrix = (array) OpsAlertSetting::value('severity_channels');
        $severity = $alert->severity ?: 'warning';

        // 矩阵缺省 true：只有被显式配为 false 才拦截
        return (bool) data_get($matrix, "{$severity}.{$channel}", true);
    }

    /**
     * 作用：判断某通道的某个凭据 config 是否缺失。
     *
     * 「为什么」区分数组：像 mail.to 这类是数组，全空才算缺失；
     * 标量则按空串判断。
     *
     * @param  string  $channel  通道名
     * @param  string  $key  凭据字段名（相对 ops.alerts.<channel>）
     * @return bool true=缺失
     */
    private function credentialMissing(string $channel, string $key): bool
    {
        $value = config("ops.alerts.{$channel}.{$key}");

        // 数组型凭据（如收件人列表）：过滤空值后为空才算缺失
        if (is_array($value)) {
            return array_values(array_filter($value)) === [];
        }

        return (string) $value === '';
    }

    /**
     * 作用：boot-safe 检查设置表是否存在（迁移是否已跑）。
     *
     * 「为什么」try/catch：无 DB 连接或表不存在时 hasTable 可能抛异常，
     * 一律视为不可用，让 guard 走 settings_unavailable 分支而非崩溃。
     *
     * @return bool 设置表是否可用
     */
    private function settingsAvailable(): bool
    {
        try {
            return Schema::hasTable('ops_alert_settings');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 作用：对异常消息做脱敏并截断，避免把敏感凭据写进结果/日志。
     *
     * 「为什么」逐条正则：Telegram bot URL、token/secret/chat_id 等查询参数、
     * Bearer 头都可能出现在异常文本里，需逐一替换为 [FILTERED]，最后限长 500 字。
     *
     * @param  Throwable  $e  异常
     * @return string 脱敏并截断后的消息
     */
    private function safeExceptionMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/https:\/\/api\.telegram\.org\/bot[^\/\s]+/i', 'https://api.telegram.org/bot[FILTERED]', $message) ?? $message;
        $message = preg_replace('/(token|password|secret|api[_-]?key|auth_signature|chat_id|access_token|sign)=([^&\s"]+)/i', '$1=[FILTERED]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [FILTERED]', $message) ?? $message;

        return mb_strimwidth($message, 0, 500, '...');
    }
}
