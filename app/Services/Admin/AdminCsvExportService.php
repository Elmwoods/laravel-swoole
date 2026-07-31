<?php

namespace App\Services\Admin;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台管理 CSV 导出服务。
 *
 * 归属于 admin 安全子系统的「数据导出」环节：负责把审计日志、登录事件、
 * 告警记录等后台数据以流式（StreamedResponse）方式导出为 CSV 文件。
 *
 * 安全关注点有两处：
 *  1. 单元格内容防「CSV 注入 / 公式注入」——即 Excel/WPS 打开时以 =、+、-、@
 *     开头的单元格会被当作公式执行，可能导致命令执行或数据外泄，故统一转义。
 *  2. 下载文件名清洗——防止路径穿越、响应头注入等文件名相关攻击。
 */
class AdminCsvExportService
{
    /**
     * 作用：以流式下载方式输出一份 CSV 文件，边生成边下发，避免大数据集一次性占满内存。
     *
     * @param  string  $filename  期望的下载文件名（会经 safeFilename 清洗后使用）
     * @param  array  $headers  CSV 表头列数组
     * @param  iterable  $rows  数据行的可迭代集合，每个元素为一行的单元格数组
     * @return StreamedResponse 可直接返回给控制器的流式下载响应
     *
     * 为什么用 streamDownload / iterable：导出量可能很大，逐行写入 php://output
     * 可让 PHP 边写边刷给客户端，内存占用恒定；iterable 允许上游用生成器惰性取数。
     */
    public function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            // 直接写入 PHP 输出流，实现真正的边生成边下发（不缓存整份文件）
            $handle = fopen('php://output', 'w');

            // 表头由调用方控制、可信，无需做注入转义，直接写出
            fputcsv($handle, $headers, ',', '"', '\\');

            foreach ($rows as $row) {
                // 数据行来自用户可控数据，逐格经 escapeCell 处理以防 CSV 公式注入
                fputcsv($handle, array_map(fn ($value): string => $this->escapeCell($value), $row), ',', '"', '\\');
            }

            fclose($handle);
        }, $this->safeFilename($filename), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 作用：把任意类型的单元格值规范化为字符串，并对可能触发电子表格公式的内容做转义。
     *
     * @param  mixed  $value  单元格原始值（null / 布尔 / 数组 / 标量皆可）
     * @return string 可安全写入 CSV 的字符串
     *
     * 为什么要转义首字符：Excel、WPS、LibreOffice 等会把以 = + - @ 开头的单元格当作
     * 公式求值（CSV / Formula Injection），攻击者可借此执行 DDE 命令或外泄数据；
     * 在前面补一个单引号可强制这些客户端按纯文本解析，从而中和该风险。
     */
    public function escapeCell(mixed $value): string
    {
        // null 统一输出为空串，避免出现字面量 "null"
        if ($value === null) {
            return '';
        }

        // 布尔值转为可读的 true/false 文本，而非 PHP 强转出的 "1"/""
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // 数组/对象序列化为 JSON；保留中文与斜杠原样，便于阅读
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $cell = (string) $value;

        // 公式注入防护：首字符为危险符号时，前置单引号使其被当作纯文本
        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
            return "'{$cell}";
        }

        return $cell;
    }

    /**
     * 作用：清洗用户传入的文件名，得到一个安全的下载文件名。
     *
     * @param  string  $filename  原始文件名（可能含路径、特殊字符）
     * @return string 仅含字母数字与 _ . - 的安全文件名，兜底为 export.csv
     *
     * 为什么要清洗：文件名会进入 Content-Disposition 响应头，未过滤的斜杠、CRLF
     * 或特殊字符可能造成路径穿越或响应头注入，故仅保留白名单字符并做兜底。
     */
    public function safeFilename(string $filename): string
    {
        // 非白名单字符（含 / \ 空格、控制字符等）统一折叠为连字符；全被替换时兜底为 export.csv
        $clean = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $filename) ?: 'export.csv';
        // 去掉首尾的点和连字符，避免出现隐藏文件名（.foo）或纯连字符
        $clean = trim($clean, '.-');

        return $clean !== '' ? $clean : 'export.csv';
    }
}
