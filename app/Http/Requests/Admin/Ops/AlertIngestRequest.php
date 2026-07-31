<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 入站 Webhook 告警校验（token 守卫在控制器，authorize 放行）。
 *
 * 对应外部系统通过 Webhook 主动上报告警的入站接口：由第三方 POST 一条告警的
 * 来源 / 级别 / 标题 / 正文 等结构化字段进来。
 * 注意鉴权分工——此接口的身份校验依赖控制器里的 token 守卫（校验 Webhook 密钥），
 * 因此本 Request 的 authorize 直接放行，只负责字段格式校验。
 */
class AlertIngestRequest extends FormRequest
{
    // authorize 返回 true：入站鉴权由控制器的 token 守卫负责（校验 Webhook 密钥），此处不再重复鉴权。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明：
     * - source：告警来源标识，required 必填；正则 /^[A-Za-z0-9_-]+$/ 仅允许字母数字下划线连字符
     *   （来源为机器/服务标识）；max:50 限长。
     * - severity：严重级别，required；Rule::in 白名单只允许 critical/warning/info，
     *   防止外部系统传入自定义级别扰乱后端分级逻辑。
     * - title：告警标题，required；max:200 限长。
     * - message：告警正文，required；max:2000 限制正文体积，避免超大 payload。
     * - context：附加上下文，nullable + array，可空的结构化数据（如指标快照、链接等）。
     * - tags：标签数组，nullable + array，max:20 限制标签数量上限，防止一次上报塞入过多标签。
     * - tags.*：数组内每个标签元素，须为 string 且 max:60；正则 /^[A-Za-z0-9_:.\-]+$/
     *   允许 key:value 形式（含冒号点连字符），逐项校验以拦截非法标签。
     * - dedup_key：去重键，nullable；max:120。外部可传该键让后端对重复上报做去重/合并，避免同一问题反复生成告警。
     */
    public function rules(): array
    {
        return [
            // 来源：必填，技术标识白名单正则，最长 50
            'source' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            // 级别：必填，白名单仅 critical/warning/info
            'severity' => ['required', Rule::in(['critical', 'warning', 'info'])],
            // 标题：必填，最长 200
            'title' => ['required', 'string', 'max:200'],
            // 正文：必填，最长 2000，限制单条 payload 体积
            'message' => ['required', 'string', 'max:2000'],
            // 上下文：可空的结构化数组（指标/链接等附加信息）
            'context' => ['nullable', 'array'],
            // 标签集合：可空数组，最多 20 个，防止标签过多
            'tags' => ['nullable', 'array', 'max:20'],
            // 单个标签：字符串，最长 60，允许 key:value 形式（含冒号点连字符），逐项校验
            'tags.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9_:.\\-]+$/'],
            // 去重键：可空，最长 120，用于后端对重复上报去重/合并
            'dedup_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
