<template>
    <section class="alerts-page">
        <!-- 顶部静默提示条：当存在生效中的告警静默时提醒运维——命中的告警仍会入库并展示，但暂不外发到通知通道 -->
        <el-alert
            v-if="activeSilenceCount > 0"
            type="warning"
            show-icon
            :closable="false"
            class="silence-banner"
            :title="`${activeSilenceCount} 条告警静默生效中：命中的告警暂不外发到通道（仍会入库并在此列出）。`"
        />

        <!-- 概览卡片区：Open / Critical / Warning / Info 四张统计卡，数据来自 summaryCards 计算属性 -->
        <div class="summary-grid">
            <el-card v-for="item in summaryCards" :key="item.label" shadow="never" class="summary-card">
                <div class="summary-label">{{ item.label }}</div>
                <div class="summary-value" :class="item.className">{{ item.value }}</div>
                <div class="summary-desc">{{ item.description }}</div>
            </el-card>
        </div>

        <!-- 告警趋势卡：折线图展示近 N 天每日命中告警与自动恢复数量 -->
        <el-card v-loading="trendLoading" shadow="never" class="trend-card">
            <div class="notification-header">
                <div>
                    <div class="panel-title">告警趋势</div>
                    <div class="panel-subtitle">近 {{ trendDays }} 天每日命中告警与自动恢复数量</div>
                </div>

                <!-- 趋势时间窗切换（7/14/30 天），切换后重新拉取趋势数据 -->
                <el-select v-model="trendDays" size="small" class="trend-days" @change="loadTrend">
                    <el-option :value="7" label="近 7 天" />
                    <el-option :value="14" label="近 14 天" />
                    <el-option :value="30" label="近 30 天" />
                </el-select>
            </div>

            <!-- 无数据时展示空态；否则渲染 echarts 折线图挂载容器（ref=trendRef，用 v-show 保留 DOM 以便 echarts 复用实例） -->
            <el-empty v-if="!trendLoading && trendEmpty" description="暂无告警趋势数据" />
            <div v-show="!trendEmpty" ref="trendRef" class="trend-chart"></div>
        </el-card>

        <!-- 通知通道卡：展示各通道（Telegram/邮件/Webhook/钉钉/飞书）配置与连通状态，并提供通知策略表单 -->
        <el-card shadow="never" class="notification-card">
            <div class="notification-header">
                <div>
                    <div class="panel-title">通知通道</div>
                    <div class="panel-subtitle">Telegram 与邮件告警配置状态</div>
                </div>

                <!-- 通道操作：立即自检（连通性）与刷新状态 -->
                <div class="notification-actions">
                    <el-button text :loading="runningHealthCheck" @click="handleHealthCheck">
                        立即自检
                    </el-button>
                    <el-button text :loading="notificationLoading" @click="loadNotificationStatus">
                        刷新状态
                    </el-button>
                </div>
            </div>

            <!-- 通道状态网格：逐个通道展示是否可用/启用、缺失配置项、最近自检时间与连通标签 -->
            <div class="notification-grid">
                <div
                    v-for="channel in notificationChannels"
                    :key="channel.name"
                    class="notification-item"
                >
                    <!-- 通道标题行：名称 + 可用性标签（configured）+ 连通性标签（health，仅在已知时展示，异常时 tooltip 显示 last_error） -->
                    <div class="channel-title">
                        <span>{{ channel.label }}</span>
                        <el-tag :type="channel.configured ? 'success' : 'warning'" effect="plain">
                            {{ channel.configured ? '可用' : '未就绪' }}
                        </el-tag>
                        <el-tooltip
                            v-if="channel.health && channel.health !== 'unknown'"
                            :content="channel.last_error || '连通正常'"
                            :disabled="channel.health === 'healthy'"
                            placement="top"
                        >
                            <el-tag :type="channel.health === 'healthy' ? 'success' : 'danger'">
                                {{ channel.health === 'healthy' ? '连通正常' : '连通异常' }}
                            </el-tag>
                        </el-tooltip>
                    </div>
                    <div class="channel-desc">
                        {{ channel.enabled ? '已启用' : '未启用' }}
                        <template v-if="channel.missing.length">
                            · 缺少 {{ channel.missing.join(', ') }}
                        </template>
                        <template v-if="channel.last_checked_at">
                            · 最近自检 {{ channel.last_checked_at }}
                        </template>
                    </div>
                </div>
            </div>

            <!-- 通知策略面板：编辑重复通知/自动恢复/升级重推/分级通道/总开关/消息模板等设置（绑定 settingsDraft 草稿） -->
            <div class="settings-panel">
                <div class="panel-subtitle">通知策略</div>
                <el-form v-if="settingsDraft" class="settings-form" label-width="120px">
                    <el-form-item label="重复通知">
                        <el-input-number
                            v-model="settingsDraft.notification_repeat_minutes"
                            :min="0"
                            :max="1440"
                            controls-position="right"
                            :disabled="settingsSaving"
                        />
                        <span class="muted inline-help">分钟</span>
                    </el-form-item>
                    <el-form-item label="自动恢复">
                        <el-switch v-model="settingsDraft.auto_resolve_enabled" :disabled="settingsSaving" />
                        <el-input-number
                            v-model="settingsDraft.auto_resolve_grace_minutes"
                            :min="1"
                            :max="1440"
                            controls-position="right"
                            :disabled="settingsSaving"
                        />
                        <span class="muted inline-help">分钟宽限</span>
                    </el-form-item>
                    <el-form-item label="升级重推">
                        <el-switch v-model="settingsDraft.escalation_enabled" :disabled="settingsSaving" />
                        <el-input-number
                            v-model="settingsDraft.escalation_after_minutes"
                            :min="1"
                            :max="1440"
                            controls-position="right"
                            :disabled="settingsSaving"
                        />
                        <span class="muted inline-help">分钟未确认则升级重推</span>
                    </el-form-item>
                    <!-- 分级通道策略：为每个严重级（critical/warning/info）勾选允许外发的通道 -->
                    <el-form-item label="通道策略">
                        <div class="severity-grid">
                            <div v-for="level in severityLevels" :key="level" class="severity-row">
                                <span class="severity-label">{{ level }}</span>
                                <el-checkbox
                                    v-for="ch in channelKeys"
                                    :key="ch"
                                    v-model="settingsDraft.severity_channels[level][ch]"
                                    :disabled="settingsSaving"
                                >
                                    {{ channelLabel(ch) }}
                                </el-checkbox>
                            </div>
                        </div>
                    </el-form-item>
                    <!-- 通道总开关：逐通道启停（覆盖分级策略之上的全局开关，字段名为 `${ch}_enabled`） -->
                    <el-form-item label="总开关">
                        <el-checkbox
                            v-for="ch in channelKeys"
                            :key="ch"
                            v-model="settingsDraft[`${ch}_enabled`]"
                            :disabled="settingsSaving"
                        >
                            {{ channelLabel(ch) }}
                        </el-checkbox>
                    </el-form-item>
                    <!-- 全局消息模板：文本通道通知文案模板，留空回退内置多行格式；支持占位符 {title} 等 -->
                    <el-form-item label="消息模板">
                        <el-input
                            v-model="settingsDraft.message_template"
                            type="textarea"
                            :rows="4"
                            :maxlength="2000"
                            show-word-limit
                            :disabled="settingsSaving"
                            placeholder="留空=用内置多行格式。占位符：{title} {severity} {source} {status} {time} {message}"
                        />
                        <div class="muted inline-help">
                            自定义文本通道（Telegram / 邮件 / 钉钉 / 飞书）通知文案；Webhook 仍为结构化 JSON。留空恢复默认。
                        </div>
                    </el-form-item>
                    <!-- 每通道专属模板：折叠面板逐个文本通道单独定制文案（字段 `message_template_${ch}`），留空回退全局模板 -->
                    <el-form-item label="每通道模板">
                        <el-collapse class="channel-templates">
                            <el-collapse-item v-for="ch in textChannelKeys" :key="ch" :name="ch" :title="`${channelLabel(ch)} 专属模板`">
                                <el-input
                                    v-model="settingsDraft[`message_template_${ch}`]"
                                    type="textarea"
                                    :rows="3"
                                    :maxlength="2000"
                                    show-word-limit
                                    :disabled="settingsSaving"
                                    placeholder="留空=回退上面的全局模板。占位符同上。"
                                />
                            </el-collapse-item>
                        </el-collapse>
                        <div class="muted inline-help">
                            为单个通道单独定制文案；留空则回退全局模板、再回退内置。
                        </div>
                    </el-form-item>
                    <el-button type="primary" :loading="settingsSaving" @click="handleSaveSettings">
                        保存策略
                    </el-button>
                </el-form>
            </div>
        </el-card>

        <!-- 巡检状态卡：展示最近一次告警评估（巡检）的执行结果，无记录时显示空态 -->
        <el-card shadow="never" class="evaluation-card">
            <div class="notification-header">
                <div>
                    <div class="panel-title">巡检状态</div>
                    <div class="panel-subtitle">最近一次告警评估执行结果</div>
                </div>

                <el-button text :loading="evaluationLoading" @click="loadEvaluationStatus">
                    刷新状态
                </el-button>
            </div>

            <!-- 评估结果详情：状态标签、触发方式、命中数、自动恢复数、耗时、完成时间及可选说明 -->
            <el-empty v-if="!latestEvaluation && !evaluationLoading" description="暂无评估记录" />
            <div v-else-if="latestEvaluation" class="evaluation-grid">
                <el-tag :type="latestEvaluation.status === 'success' ? 'success' : 'danger'" effect="plain">
                    {{ latestEvaluation.status }}
                </el-tag>
                <span>触发：{{ latestEvaluation.trigger }}</span>
                <span>命中：{{ latestEvaluation.detected_count }}</span>
                <span>自动恢复：{{ latestEvaluation.auto_resolved_count }}</span>
                <span>耗时：{{ latestEvaluation.duration_ms }}ms</span>
                <span>完成：{{ latestEvaluation.finished_at || '-' }}</span>
                <span v-if="latestEvaluation.message" class="muted">{{ latestEvaluation.message }}</span>
            </div>
        </el-card>

        <!-- 规则配置卡：可编辑白名单告警规则的阈值与启停；头部提供变更历史/导出/导入/刷新操作 -->
        <el-card shadow="never" class="rule-card">
            <template #header>
                <div class="panel-header">
                    <div>
                        <div class="panel-title">规则配置</div>
                        <div class="panel-subtitle">系统白名单规则的阈值与启停状态</div>
                    </div>

                    <!-- 规则头部操作按钮组：查看变更历史、导出/导入规则 JSON、刷新规则列表 -->
                    <el-space>
                        <el-button text @click="openRuleChanges">变更历史</el-button>
                        <el-button text @click="handleExportRules">导出规则</el-button>
                        <el-button text :loading="importingRules" @click="handleImportRules">导入规则</el-button>
                        <el-button text :loading="rulesLoading" @click="loadAlertRules">
                            刷新规则
                        </el-button>
                    </el-space>
                </div>
            </template>

            <!-- 规则加载/为空时的提示条（如无规则、加载失败的引导文案） -->
            <el-alert
                v-if="rulesNotice"
                class="rule-notice"
                :title="rulesNotice"
                type="warning"
                show-icon
                :closable="false"
            />

            <!-- 规则表格：每行一条规则，阈值/启用列绑定到 ruleDrafts 草稿，编辑后逐行保存 -->
            <el-table :data="alertRules" border stripe v-loading="rulesLoading" empty-text="暂无告警规则">
                <el-table-column label="规则" min-width="220">
                    <template #default="{ row }">
                        <div class="alert-title">{{ row.name }}</div>
                        <div class="alert-message">{{ row.description }}</div>
                    </template>
                </el-table-column>

                <el-table-column prop="source" label="来源" width="110" />
                <el-table-column prop="metric" label="指标" width="150" />

                <!-- 预警阈值列：可编辑数字输入，精度依单位而定（thresholdPrecision） -->
                <el-table-column label="预警阈值" width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="ruleDrafts[row.key].warning_threshold"
                            :min="row.min"
                            :max="row.max"
                            :precision="thresholdPrecision(row.unit)"
                            controls-position="right"
                            :disabled="savingRuleKey === row.key"
                        />
                    </template>
                </el-table-column>

                <!-- 严重阈值列：仅当规则已设严重阈值或强制要求时可编辑，否则显示占位「-」 -->
                <el-table-column label="严重阈值" width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-if="row.critical_threshold !== null || row.requires_critical"
                            v-model="ruleDrafts[row.key].critical_threshold"
                            :min="row.min"
                            :max="row.max"
                            :precision="thresholdPrecision(row.unit)"
                            controls-position="right"
                            :disabled="savingRuleKey === row.key"
                        />
                        <span v-else class="muted">-</span>
                    </template>
                </el-table-column>

                <el-table-column label="单位" width="90">
                    <template #default="{ row }">
                        {{ row.unit || '-' }}
                    </template>
                </el-table-column>

                <!-- 启用开关列：切换即调用 handleToggleRule 启停该规则 -->
                <el-table-column label="启用" width="100">
                    <template #default="{ row }">
                        <el-switch
                            v-model="row.is_active"
                            :loading="togglingRuleKey === row.key"
                            :disabled="savingRuleKey === row.key"
                            @change="(value) => handleToggleRule(row, Boolean(value))"
                        />
                    </template>
                </el-table-column>

                <!-- 操作列：保存该行规则草稿的阈值改动 -->
                <el-table-column label="操作" width="110" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            text
                            type="primary"
                            :loading="savingRuleKey === row.key"
                            :disabled="togglingRuleKey === row.key"
                            @click="handleSaveRule(row)"
                        >
                            保存
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <!-- 告警分组卡：按来源/严重级/指派人聚合 open 告警，便于降噪与批量处理 -->
        <el-card shadow="never" class="group-card">
            <template #header>
                <div class="panel-header">
                    <div>
                        <div class="panel-title">告警分组</div>
                        <div class="panel-subtitle">按维度聚合 open 告警，降噪与关联</div>
                    </div>
                    <!-- 分组维度切换（groupBy）+ 打开统计周报弹窗 -->
                    <div class="group-actions">
                        <el-segmented v-model="groupBy" :options="groupByOptions" @change="loadGroups" />
                        <el-button text @click="openReport">统计周报</el-button>
                    </div>
                </div>
            </template>

            <!-- 分组表格：每行一个聚合分组，展示总数、各级别数、指派情况、样本与最近出现时间 -->
            <el-table :data="alertGroups" border stripe v-loading="groupsLoading" empty-text="暂无 open 告警">
                <el-table-column label="分组" min-width="160">
                    <template #default="{ row }">{{ row.group === '__unassigned__' ? '未指派' : row.group }}</template>
                </el-table-column>
                <el-table-column label="总数" width="90" prop="total" />
                <el-table-column label="严重级" width="220">
                    <template #default="{ row }">
                        <el-tag v-if="row.critical" type="danger" size="small" effect="light" class="group-tag">严重 {{ row.critical }}</el-tag>
                        <el-tag v-if="row.warning" type="warning" size="small" effect="light" class="group-tag">警告 {{ row.warning }}</el-tag>
                        <el-tag v-if="row.info" type="info" size="small" effect="light" class="group-tag">提示 {{ row.info }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="指派" width="130">
                    <template #default="{ row }">{{ row.assigned }} / 未 {{ row.unassigned }}</template>
                </el-table-column>
                <el-table-column label="样本" min-width="240">
                    <template #default="{ row }">
                        <div v-for="(s, i) in row.samples" :key="i" class="alert-message">{{ s }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="最近出现" width="180" prop="last_seen_at" />
                <!-- 批量操作列：仅有管理权限且非按指派人分组时可见——整组确认/指派/静默 -->
                <el-table-column v-if="canManage && groupBy !== 'assigned_to'" label="批量" width="220" fixed="right">
                    <template #default="{ row }">
                        <el-button text type="primary" @click="batchAck(row)">确认整组</el-button>
                        <el-button text type="info" @click="batchAssign(row)">指派</el-button>
                        <el-button text type="warning" @click="batchSilence(row)">静默</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <!-- 告警中心主卡：告警列表 + 筛选 + 分页；头部含实时连接状态标签与测试/模拟/评估操作 -->
        <el-card shadow="never" class="alert-panel">
            <template #header>
                <div class="panel-header">
                    <div>
                        <div class="panel-title">告警中心</div>
                        <div class="panel-subtitle">实时告警、确认处理与通知状态入口</div>
                    </div>

                    <!-- 头部操作：实时连接状态标签 + 测试通知 / 生成模拟数据 / 立即评估 -->
                    <el-space>
                        <el-tag :type="realtimeConnected ? 'success' : 'info'" effect="plain">
                            {{ realtimeConnected ? '实时已连接' : '实时未连接' }}
                        </el-tag>
                        <el-button :loading="testingNotification" @click="handleTestNotification">
                            测试通知
                        </el-button>
                        <el-button :icon="DataLine" :loading="demoLoading" @click="handleDemoScenarios">
                            模拟数据
                        </el-button>
                        <el-button :icon="Refresh" :loading="loading || evaluating" @click="handleEvaluate">
                            立即评估
                        </el-button>
                    </el-space>
                </div>
            </template>

            <!-- 筛选栏：状态/级别/来源/指派人/标签多维过滤，以及筛选预设的应用/保存/删除 -->
            <div class="filters">
                <el-segmented v-model="status" :options="statusOptions" @change="handleFilterChange" />

                <el-select v-model="severity" clearable placeholder="级别" @change="handleFilterChange">
                    <el-option label="Critical" value="critical" />
                    <el-option label="Warning" value="warning" />
                    <el-option label="Info" value="info" />
                </el-select>

                <el-select v-model="source" clearable placeholder="来源" @change="handleFilterChange">
                    <el-option
                        v-for="item in summary.sources"
                        :key="item.source"
                        :label="item.source"
                        :value="item.source"
                    />
                </el-select>

                <!-- 指派人筛选：未指派 / 指派给我（当前管理员）/ 其他指派人（去重排除自己） -->
                <el-select v-model="assigneeFilter" clearable placeholder="指派人" class="assignee-select" @change="handleFilterChange">
                    <el-option label="未指派" value="__unassigned__" />
                    <el-option v-if="currentAdminName" :label="`指派给我（${currentAdminName}）`" :value="currentAdminName" />
                    <el-option
                        v-for="name in assignees.filter(n => n !== currentAdminName)"
                        :key="name"
                        :label="name"
                        :value="name"
                    />
                </el-select>

                <el-input v-model="tagFilter" clearable placeholder="标签" class="tag-filter" @change="handleFilterChange" @clear="handleFilterChange" />

                <!-- 筛选预设：选择即套用已保存的过滤组合；右侧按钮保存当前筛选 / 删除选中预设 -->
                <el-select v-model="selectedPresetId" clearable placeholder="筛选预设" class="preset-select" @change="applyPreset">
                    <el-option v-for="p in presets" :key="p.id" :label="p.name" :value="p.id" />
                </el-select>
                <el-button @click="handleSavePreset">保存筛选</el-button>
                <el-button v-if="selectedPresetId" text type="danger" @click="handleDeletePreset">删除预设</el-button>
            </div>

            <!-- 告警列表表格：级别/来源/内容/状态/指派/次数/时间及行内操作 -->
            <el-table :data="alerts" border stripe v-loading="loading" empty-text="暂无告警">
                <el-table-column label="级别" width="120">
                    <template #default="{ row }">
                        <el-tag :type="severityTag(row.severity)" effect="light">
                            {{ row.severity }}
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column prop="source" label="来源" width="120" />

                <el-table-column label="告警内容" min-width="360">
                    <template #default="{ row }">
                        <div class="alert-title">{{ row.title }}</div>
                        <div class="alert-message">{{ row.message }}</div>
                        <div v-if="row.tags && row.tags.length" class="alert-tags">
                            <el-tag v-for="t in row.tags" :key="t" size="small" effect="plain" class="alert-tag">{{ t }}</el-tag>
                        </div>
                    </template>
                </el-table-column>

                <!-- 状态列：主状态标签 + 已升级/被抑制/抖动中等附加状态标签 -->
                <el-table-column label="状态" width="150">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 'open' ? 'danger' : 'info'" effect="plain">
                            {{ statusLabel(row.status) }}
                        </el-tag>
                        <el-tag v-if="row.escalated_at" type="danger" size="small" effect="dark" class="escalated-tag">
                            已升级{{ row.escalation_level ? ` L${row.escalation_level}` : '' }}
                        </el-tag>
                        <el-tag v-if="row.suppressed_at" type="info" size="small" effect="plain" class="escalated-tag">
                            被抑制
                        </el-tag>
                        <el-tag v-if="isFlapping(row)" type="warning" size="small" effect="dark" class="escalated-tag">
                            抖动中
                        </el-tag>
                    </template>
                </el-table-column>

                <el-table-column label="指派" width="130">
                    <template #default="{ row }">
                        <span v-if="row.assigned_to">{{ row.assigned_to }}</span>
                        <span v-else class="muted">—</span>
                    </template>
                </el-table-column>

                <el-table-column prop="hit_count" label="次数" width="90" />
                <el-table-column prop="last_seen_at" label="最后出现" width="180" />

                <!-- 行内操作列：详情 / 指派 / 指派给我 / 确认 / 恢复，按当前状态与权限条件展示 -->
                <el-table-column label="操作" width="180" fixed="right">
                    <template #default="{ row }">
                        <div class="action-buttons">
                            <el-button text @click="openDetail(row)">详情</el-button>
                            <el-button
                                v-if="row.status !== 'resolved'"
                                text
                                type="info"
                                :loading="assigningId === row.id"
                                @click="handleAssign(row)"
                            >
                                指派
                            </el-button>
                            <el-button
                                v-if="currentAdminName && row.status !== 'resolved'"
                                text
                                type="info"
                                :loading="assigningId === row.id"
                                @click="handleClaim(row)"
                            >
                                指派给我
                            </el-button>
                            <el-button
                                v-if="row.status === 'open'"
                                text
                                type="primary"
                                :loading="acknowledgingId === row.id"
                                @click="handleAcknowledge(row)"
                            >
                                确认
                            </el-button>
                            <el-button
                                v-if="row.status !== 'resolved'"
                                text
                                type="success"
                                :loading="resolvingId === row.id"
                                @click="handleResolve(row)"
                            >
                                恢复
                            </el-button>
                        </div>
                    </template>
                </el-table-column>
            </el-table>

            <!-- 分页栏：页码/每页条数变化后重新拉取告警列表 -->
            <div class="pagination-bar">
                <el-pagination
                    v-model:current-page="page"
                    v-model:page-size="perPage"
                    :page-sizes="[10, 20, 50, 100]"
                    :total="pagination.total"
                    background
                    layout="total, sizes, prev, pager, next, jumper"
                    @current-change="loadAlerts"
                    @size-change="handlePageSizeChange"
                />
            </div>
        </el-card>

        <!-- 告警详情抽屉：展示单条告警的基本信息、处理预案、标签、相似告警、时间线与处理备注 -->
        <el-drawer v-model="detailVisible" title="告警详情" size="520px">
            <div v-if="detailAlert" class="detail-body">
                <!-- 基础信息描述列表：标题/来源/级别/状态/说明及可选的指派人、抖动次数 -->
                <el-descriptions :column="1" border size="small">
                    <el-descriptions-item label="标题">{{ detailAlert.title }}</el-descriptions-item>
                    <el-descriptions-item label="来源">{{ detailAlert.source }}</el-descriptions-item>
                    <el-descriptions-item label="级别">{{ detailAlert.severity }}</el-descriptions-item>
                    <el-descriptions-item label="状态">{{ statusLabel(detailAlert.status) }}</el-descriptions-item>
                    <el-descriptions-item label="说明">{{ detailAlert.message }}</el-descriptions-item>
                    <el-descriptions-item v-if="detailAlert.assigned_to" label="指派">{{ detailAlert.assigned_to }}</el-descriptions-item>
                    <el-descriptions-item v-if="detailAlert.flap_count" label="抖动次数">{{ detailAlert.flap_count }}</el-descriptions-item>
                </el-descriptions>

                <!-- 处理预案（runbook）：可选的处理链接与分步骤操作指引 -->
                <template v-if="detailAlert.runbook">
                    <div class="detail-section-title">处理预案</div>
                    <a v-if="detailAlert.runbook.url" :href="detailAlert.runbook.url" target="_blank" rel="noopener" class="runbook-link">{{ detailAlert.runbook.url }}</a>
                    <ol class="runbook-steps">
                        <li v-for="(step, i) in detailAlert.runbook.steps" :key="i">{{ step }}</li>
                    </ol>
                </template>

                <!-- 标签编辑：可创建/多选标签，变更时保存（仅有管理权限可编辑） -->
                <div class="detail-section-title">标签</div>
                <el-select
                    v-model="detailTags"
                    multiple
                    filterable
                    allow-create
                    default-first-option
                    :disabled="!canManage"
                    placeholder="如 team:dba env:prod"
                    style="width: 100%"
                    @change="saveTags"
                >
                    <el-option v-for="t in detailTags" :key="t" :label="t" :value="t" />
                </el-select>

                <!-- 相似告警：同来源且已恢复的历史告警，附恢复备注与处理备注，供排查参考 -->
                <div class="detail-section-title">相似告警（同源已恢复）</div>
                <el-empty v-if="!similar.length" description="暂无相似告警" :image-size="60" />
                <div v-for="s in similar" :key="s.id" class="similar-item">
                    <div class="similar-head">
                        <span class="alert-title">{{ s.title }}</span>
                        <span class="muted">{{ s.resolved_at }}</span>
                    </div>
                    <div v-if="s.acknowledge_note" class="muted">恢复备注：{{ s.acknowledge_note }}</div>
                    <div v-for="n in s.notes" :key="n.id" class="muted">· {{ n.author }}：{{ n.body }}</div>
                </div>

                <!-- 时间线：该告警的事件流水（动作 + 操作人 + 备注） -->
                <div class="detail-section-title">时间线</div>
                <el-timeline v-if="detailAlert.timeline.length">
                    <el-timeline-item
                        v-for="ev in detailAlert.timeline"
                        :key="ev.id"
                        :timestamp="ev.created_at || ''"
                    >
                        {{ ev.action }}<span v-if="ev.actor"> · {{ ev.actor }}</span><span v-if="ev.note"> — {{ ev.note }}</span>
                    </el-timeline-item>
                </el-timeline>
                <el-empty v-else description="暂无事件" :image-size="60" />

                <!-- 处理备注：有权限时可新增备注；列表逐条展示，作者本人可删除自己的备注 -->
                <div class="detail-section-title">处理备注</div>
                <div v-if="canManage" class="note-add">
                    <el-input v-model="noteBody" type="textarea" :rows="2" :maxlength="2000" placeholder="记录排查过程 / 结论" />
                    <el-button type="primary" :loading="noteSaving" :disabled="!noteBody.trim()" @click="submitNote">添加备注</el-button>
                </div>
                <div v-for="n in alertNotes" :key="n.id" class="note-item">
                    <div class="note-meta">
                        <span class="note-author">{{ n.author }}</span>
                        <span class="muted">{{ n.created_at }}</span>
                        <el-button
                            v-if="n.admin_user_id === adminAuth.profile?.admin?.id"
                            text
                            type="danger"
                            size="small"
                            @click="removeNote(n.id)"
                        >删除</el-button>
                    </div>
                    <div class="note-body">{{ n.body }}</div>
                </div>
                <el-empty v-if="!alertNotes.length" description="暂无备注" :image-size="60" />
            </div>
        </el-drawer>

        <!-- 规则变更历史弹窗：审计规则字段的旧值→新值、操作人与时间 -->
        <el-dialog v-model="ruleChangesVisible" title="规则变更历史" width="640px">
            <el-table :data="ruleChanges" border stripe empty-text="暂无变更记录" max-height="420">
                <el-table-column label="规则" prop="rule_key" width="150" />
                <el-table-column label="字段" prop="field" width="150" />
                <el-table-column label="旧→新" min-width="140">
                    <template #default="{ row }">{{ row.old_value ?? '—' }} → {{ row.new_value ?? '—' }}</template>
                </el-table-column>
                <el-table-column label="操作人" prop="actor" width="120" />
                <el-table-column label="时间" prop="created_at" width="170" />
            </el-table>
        </el-dialog>

        <!-- 告警统计周报弹窗：窗口期内的告警总量/分级、MTTA/MTTR、SLA 达标率、积压分布、值班与 Top 来源 -->
        <el-dialog v-model="reportVisible" title="告警统计周报" width="560px">
            <div v-if="report" class="report-body">
                <p>窗口：近 {{ report.window_days }} 天（{{ report.generated_at }}）</p>
                <p>告警共 {{ report.alerts.total }} 条 —— 严重 {{ report.alerts.by_severity.critical }} / 警告 {{ report.alerts.by_severity.warning }} / 提示 {{ report.alerts.by_severity.info }}</p>
                <p>MTTA {{ Math.round(report.sla.mtta_avg_seconds / 60) }} 分 / MTTR {{ Math.round(report.sla.mttr_avg_seconds / 60) }} 分</p>
                <p>达标率：确认 {{ report.sla.ack_rate ?? '无' }}% / 恢复 {{ report.sla.resolve_rate ?? '无' }}%；当前违约 {{ report.sla.open_breaches }}</p>
                <p>积压：&lt;1h {{ report.sla.open_aging.under_1h }} / 1–24h {{ report.sla.open_aging.one_to_24h }} / &gt;24h {{ report.sla.open_aging.over_24h }}</p>
                <p>当前值班：{{ report.on_call.current || '无' }}</p>
                <p>Top 来源：<span v-for="s in report.alerts.sources" :key="s.source" class="report-src">{{ s.source }} {{ s.total }}</span></p>
            </div>
            <el-empty v-else description="暂无数据" />
        </el-dialog>
    </section>
</template>

<script setup lang="ts">
/**
 * 告警中心（Ops Center / AlertCenter）页面。
 *
 * 作用：运维告警的统一控制台，聚合以下能力于一屏：
 *  - 概览统计（Open/Critical/Warning/Info）与近 N 天告警趋势折线图（echarts）；
 *  - 通知通道状态与连通自检、通知策略（重复/自动恢复/升级/分级通道/消息模板）编辑；
 *  - 最近一次巡检（评估）结果、白名单规则阈值与启停的在线编辑、规则导入导出与变更历史；
 *  - open 告警按维度分组聚合及批量确认/指派/静默、统计周报；
 *  - 告警列表的多维筛选/预设、确认/指派/认领/恢复、详情抽屉（预案、标签、相似告警、时间线、备注）；
 *  - 通过 Laravel Echo 订阅实时告警推送并刷新徽标。
 *
 * 数据主要来自 @/api/opsStage4 与 @/api/opsAlertSilence 两个接口模块，权限由 adminAuth store 控制。
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { DataLine, Refresh } from '@element-plus/icons-vue'
import * as echarts from 'echarts'
import echo from '@/utils/echo'
import {
    acknowledgeAlert,
    assignAlert,
    createAlertDemoScenarios,
    deleteAlertPreset,
    evaluateAlerts,
    addAlertNote,
    batchAcknowledgeGroup,
    batchAssignGroup,
    batchSilenceGroup,
    deleteAlertNote,
    exportAlertRules,
    getAlertNotes,
    getSimilarAlerts,
    setAlertTags,
    getAlertAssignees,
    getAlertGroups,
    getAlertPresets,
    getAlertReport,
    getRuleChanges,
    importAlertRules,
    getAlertSettings,
    getAlertTrend,
    getLatestAlertEvaluation,
    getAlertNotificationStatus,
    getAlertRules,
    getAlerts,
    getAlertSummary,
    resolveAlert,
    runAlertHealthCheck,
    saveAlertPreset,
    testAlertNotification,
    toggleAlertRule,
    updateAlertSettings,
    updateAlertRule,
    type AlertEvaluationStatus,
    type AlertPreset,
    type AlertRule,
    type AlertRealtimePayload,
    type AlertNotificationStatus,
    type ChannelStatus,
    type AlertSettings,
    type AlertSeverity,
    type AlertSummary,
    type AlertStatus,
    type OpsAlert,
    type AlertRuleExportItem,
    type AlertGroup,
    type AlertReport,
    type AlertNoteItem,
    type SimilarAlert,
    type RuleChange,
} from '@/api/opsStage4'
import { getAlertSilences } from '@/api/opsAlertSilence'
import { useAdminAuthStore } from '@/stores/adminAuth'

const adminAuth = useAdminAuthStore() // 管理员鉴权 store，提供当前管理员信息与权限判断
const currentAdminName = computed(() => adminAuth.profile?.admin?.name ?? '') // 当前登录管理员名（用于「指派给我」与筛选）
const canManage = computed(() => adminAuth.hasPermission('ops.alerts.manage')) // 是否具备告警管理权限（控制批量/编辑类操作可见性）
const reportVisible = ref(false) // 统计周报弹窗显隐
const report = ref<AlertReport | null>(null) // 周报数据
const detailVisible = ref(false) // 告警详情抽屉显隐
const detailAlert = ref<OpsAlert | null>(null) // 当前查看的告警对象
const alertNotes = ref<AlertNoteItem[]>([]) // 当前告警的处理备注列表
const noteBody = ref('') // 备注输入框内容
const noteSaving = ref(false) // 备注提交中标志
const detailTags = ref<string[]>([]) // 当前告警的标签草稿（可编辑）
const similar = ref<SimilarAlert[]>([]) // 相似告警（同源已恢复）列表
const ruleChangesVisible = ref(false) // 规则变更历史弹窗显隐
const ruleChanges = ref<RuleChange[]>([]) // 规则变更历史记录

// 作用：打开规则变更历史弹窗并拉取记录。为什么：审计规则阈值/启停的历史修改，失败时清空列表避免残留旧数据。
const openRuleChanges = async () => {
    ruleChangesVisible.value = true
    try {
        const res = await getRuleChanges()
        ruleChanges.value = res.data.data.items
    } catch {
        ruleChanges.value = []
    }
}

// 作用：判断告警当前是否处于抖动（flapping）状态。为什么：flapping_until 是抖动抑制截止时间，未过期即视为抖动中，用于列表打标签。
const isFlapping = (row: OpsAlert) => !!row.flapping_until && new Date(row.flapping_until).getTime() > Date.now()

// 作用：打开详情抽屉并加载该告警的备注与相似告警。为什么：先重置抽屉内各状态再并发拉取，避免展示上一条告警的残留数据。
const openDetail = async (row: OpsAlert) => {
    detailAlert.value = row
    detailVisible.value = true
    noteBody.value = ''
    alertNotes.value = []
    detailTags.value = [...(row.tags || [])]
    similar.value = []
    try {
        const [notesRes, similarRes] = await Promise.all([getAlertNotes(row.id), getSimilarAlerts(row.id)])
        alertNotes.value = notesRes.data.data.items
        similar.value = similarRes.data.data.items
    } catch {
        // 保持空
    }
}

// 作用：保存详情抽屉中编辑的标签。为什么：标签变更需同步回后端与列表行，成功后刷新列表让标签筛选生效；无权限直接跳过。
const saveTags = async () => {
    if (!detailAlert.value || !canManage.value) return
    try {
        const res = await setAlertTags(detailAlert.value.id, detailTags.value)
        detailTags.value = res.data.data.tags
        detailAlert.value.tags = res.data.data.tags
        ElMessage.success('标签已更新')
        await loadAlerts()
    } catch {
        ElMessage.error('标签更新失败')
    }
}

// 作用：为当前告警提交一条处理备注。为什么：记录排查过程；提交后清空输入并重新拉取备注列表以显示最新一条。
const submitNote = async () => {
    if (!detailAlert.value || !noteBody.value.trim()) return
    noteSaving.value = true
    try {
        await addAlertNote(detailAlert.value.id, { body: noteBody.value.trim() })
        noteBody.value = ''
        const res = await getAlertNotes(detailAlert.value.id)
        alertNotes.value = res.data.data.items
        ElMessage.success('已添加备注')
    } catch {
        ElMessage.error('添加备注失败')
    } finally {
        noteSaving.value = false
    }
}

// 作用：删除指定处理备注。为什么：仅作者本人可删；成功后本地过滤移除该条，避免整表重拉。
const removeNote = async (noteId: number) => {
    if (!detailAlert.value) return
    try {
        await deleteAlertNote(detailAlert.value.id, noteId)
        alertNotes.value = alertNotes.value.filter(n => n.id !== noteId)
    } catch {
        ElMessage.error('删除备注失败')
    }
}

const loading = ref(false) // 告警列表加载中
const evaluating = ref(false) // 手动「立即评估」执行中
const testingNotification = ref(false) // 「测试通知」执行中
const trendRef = ref<HTMLDivElement>() // 趋势折线图的 DOM 容器引用（echarts 挂载点）
const trendDays = ref(14) // 趋势时间窗（天），默认近 14 天
const trendLoading = ref(false) // 趋势数据加载中
const trendEmpty = ref(false) // 趋势是否无数据（用于切换空态/图表显隐）
let trendChart: echarts.ECharts | null = null // echarts 实例句柄（惰性初始化，卸载时销毁）
const demoLoading = ref(false) // 「模拟数据」生成中
const notificationLoading = ref(false) // 通知通道状态加载中
const activeSilenceCount = ref(0) // 生效中的告警静默条数（用于顶部提示条）

// 作用：拉取当前生效的告警静默数量。为什么：顶部横幅据此提醒「静默生效中」，失败时归零不阻塞页面。
const loadSilences = async () => {
    try {
        const res = await getAlertSilences()
        activeSilenceCount.value = res.data.data.active.length
    } catch {
        activeSilenceCount.value = 0
    }
}
const runningHealthCheck = ref(false) // 通道连通自检执行中
const importingRules = ref(false) // 规则导入中
const assigneeFilter = ref('') // 指派人筛选值（'' 表示不限，'__unassigned__' 表示未指派）
const assignees = ref<string[]>([]) // 已出现过的指派人列表（筛选下拉数据源）
const tagFilter = ref('') // 标签筛选输入
const groupBy = ref<'source' | 'severity' | 'assigned_to'>('source') // 告警分组维度
const groupsLoading = ref(false) // 分组数据加载中
const alertGroups = ref<AlertGroup[]>([]) // 分组聚合结果
const groupByOptions = [ // 分组维度可选项（分段控件数据源）
    { label: '按来源', value: 'source' },
    { label: '按严重级', value: 'severity' },
    { label: '按指派人', value: 'assigned_to' },
]
const rulesLoading = ref(false) // 规则列表加载中
const rulesNotice = ref('') // 规则区提示文案（无规则/加载失败引导）
const evaluationLoading = ref(false) // 巡检状态加载中
const settingsSaving = ref(false) // 通知策略保存中
const realtimeConnected = ref(false) // 实时通道（Echo）连接状态
const acknowledgingId = ref<number | null>(null) // 正在确认的告警 id（行内 loading）
const resolvingId = ref<number | null>(null) // 正在恢复的告警 id
const assigningId = ref<number | null>(null) // 正在指派/认领的告警 id
const savingRuleKey = ref<string | null>(null) // 正在保存的规则 key
const togglingRuleKey = ref<string | null>(null) // 正在启停的规则 key
const alerts = ref<OpsAlert[]>([]) // 当前页告警列表
const alertRules = ref<AlertRule[]>([]) // 规则列表
const latestEvaluation = ref<AlertEvaluationStatus | null>(null) // 最近一次巡检评估结果
const settingsDraft = ref<AlertSettings | null>(null) // 通知策略表单草稿（编辑副本，保存后回填）
const ruleDrafts = ref<Record<string, { // 各规则阈值/启停的可编辑草稿，以 rule.key 为索引
    warning_threshold: number
    critical_threshold: number | null
    is_active: boolean
}>>({})
const status = ref<AlertStatus | 'all'>('open') // 状态筛选，默认只看 open
const severity = ref('') // 级别筛选
const source = ref('') // 来源筛选
const presets = ref<AlertPreset[]>([]) // 已保存的筛选预设列表
const selectedPresetId = ref<number | undefined>(undefined) // 当前选中的预设 id
const page = ref(1) // 当前页码
const perPage = ref(20) // 每页条数
const pagination = ref({ // 分页元信息（来自接口返回）
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
})
const summary = ref<AlertSummary>({ // 概览统计（Open/各级别计数与来源分布），驱动顶部卡片与来源下拉
    open_total: 0,
    critical: 0,
    warning: 0,
    info: 0,
    sources: [],
    checked_at: '-',
})
const notificationStatus = ref<AlertNotificationStatus>({ // 各通知通道的配置/连通状态（后端为单一来源，含 settings）
    telegram: {
        enabled: false,
        configured: false,
        missing: ['enabled'],
    },
    mail: {
        enabled: false,
        configured: false,
        missing: ['enabled'],
    },
    checked_at: '-',
})
let channel: any = null // Echo 频道句柄，避免重复订阅；离开页面时置空

const statusOptions = [ // 状态筛选分段控件选项
    { label: 'Open', value: 'open' },
    { label: '已确认', value: 'acknowledged' },
    { label: '全部', value: 'all' },
]
const severityLevels: AlertSeverity[] = ['critical', 'warning', 'info'] // 严重级枚举（分级通道策略行迭代用）

// 计算属性：将 summary 概览数据映射为顶部四张统计卡（含展示文案与样式类）。
const summaryCards = computed(() => [
    {
        label: 'Open',
        value: summary.value.open_total,
        description: '当前未处理告警',
        className: 'open',
    },
    {
        label: 'Critical',
        value: summary.value.critical,
        description: '需要立即关注',
        className: 'critical',
    },
    {
        label: 'Warning',
        value: summary.value.warning,
        description: '达到预警阈值',
        className: 'warning',
    },
    {
        label: 'Info',
        value: summary.value.info,
        description: '提示类事件',
        className: 'info',
    },
])

// 通道 key 到中文展示名的映射表
const CHANNEL_LABELS: Record<string, string> = {
    telegram: 'Telegram',
    mail: '邮件',
    webhook: 'Webhook',
    dingtalk: '钉钉',
    feishu: '飞书',
}

// 作用：取通道显示名，无映射时回退原始 key。
const channelLabel = (name: string): string => CHANNEL_LABELS[name] ?? name

// 通道列表来自后端通知状态（单一来源），前端不再写死 telegram/mail。
const channelKeys = computed<string[]>(() =>
    Object.keys(notificationStatus.value).filter(key => key !== 'checked_at' && key !== 'settings'),
)

// 支持自定义模板的文本通道（去掉 webhook——结构化载荷不套模板）。
const textChannelKeys = computed<string[]>(() => channelKeys.value.filter(name => name !== 'webhook'))

// 计算属性：把通道 key 展开为带显示名与状态字段的通道对象数组，供状态网格渲染。
const notificationChannels = computed(() =>
    channelKeys.value.map(name => ({
        name,
        label: channelLabel(name),
        ...(notificationStatus.value[name] as ChannelStatus),
    })),
)

/**
 * 加载告警规则配置。
 */
const loadAlertRules = async () => {
    rulesLoading.value = true
    rulesNotice.value = ''

    try {
        const res = await getAlertRules()
        ruleDrafts.value = Object.fromEntries(
            res.data.data.items.map(rule => [
                rule.key,
                {
                    warning_threshold: rule.warning_threshold,
                    critical_threshold: rule.critical_threshold,
                    is_active: rule.is_active,
                },
            ]),
        )
        alertRules.value = res.data.data.items

        if (alertRules.value.length === 0) {
            rulesNotice.value = '暂无告警规则；执行迁移后刷新规则或触发一次告警评估会同步默认规则。'
        }
    } catch {
        rulesNotice.value = '告警规则加载失败，请检查登录状态、权限或接口状态后重试。'
        ElMessage.error('告警规则加载失败')
    } finally {
        rulesLoading.value = false
    }
}

/**
 * 加载告警汇总。
 */
const loadSummary = async () => {
    const res = await getAlertSummary()
    summary.value = res.data.data
}

/**
 * 加载通知通道配置状态。
 */
const loadNotificationStatus = async () => {
    notificationLoading.value = true

    try {
        const res = await getAlertNotificationStatus()
        notificationStatus.value = res.data.data
        settingsDraft.value = res.data.data.settings ? structuredClone(res.data.data.settings) : settingsDraft.value
    } catch {
        ElMessage.error('通知通道状态加载失败')
    } finally {
        notificationLoading.value = false
    }
}

/**
 * 立即对已启用通道做一次连通性自检并刷新状态。
 */
const handleHealthCheck = async () => {
    runningHealthCheck.value = true

    try {
        const res = await runAlertHealthCheck()
        notificationStatus.value = res.data.data
        const summary = res.data.data.summary
        if (summary && summary.enabled === false) {
            ElMessage.info('通道健康自检未启用（OPS_ALERT_HEALTH_ENABLED）')
        } else {
            ElMessage.success(`通道自检完成：正常 ${summary?.healthy ?? 0} / 异常 ${summary?.failing ?? 0}`)
        }
    } catch {
        ElMessage.error('通道健康自检失败')
    } finally {
        runningHealthCheck.value = false
    }
}

// 作用：加载通知策略并写入草稿。为什么：用 structuredClone 深拷贝，编辑草稿不污染原始返回、保存前可随时丢弃。
const loadAlertSettings = async () => {
    const res = await getAlertSettings()
    settingsDraft.value = structuredClone(res.data.data)
}

// 作用：加载最近一次巡检（评估）状态。为什么：展示定时评估是否成功及命中/恢复量，供运维确认巡检链路正常。
const loadEvaluationStatus = async () => {
    evaluationLoading.value = true

    try {
        const res = await getLatestAlertEvaluation()
        latestEvaluation.value = res.data.data
    } catch {
        ElMessage.error('巡检状态加载失败')
    } finally {
        evaluationLoading.value = false
    }
}

/**
 * 加载告警列表。
 */
const loadAlerts = async () => {
    loading.value = true

    try {
        const res = await getAlerts({
            status: status.value === 'all' ? undefined : status.value,
            severity: severity.value || undefined,
            source: source.value || undefined,
            assigned: assigneeFilter.value === '__unassigned__' ? 'unassigned' : undefined,
            assigned_to: assigneeFilter.value && assigneeFilter.value !== '__unassigned__' ? assigneeFilter.value : undefined,
            tag: tagFilter.value.trim() || undefined,
            page: page.value,
            per_page: perPage.value,
        })

        alerts.value = res.data.data.items
        pagination.value = res.data.data.pagination
        page.value = pagination.value.current_page
        perPage.value = pagination.value.per_page
    } catch {
        ElMessage.error('告警列表加载失败')
    } finally {
        loading.value = false
    }
}

/**
 * 手动执行一次告警评估。
 */
const handleEvaluate = async () => {
    evaluating.value = true

    try {
        const res = await evaluateAlerts()
        summary.value = res.data.data.summary
        await Promise.all([loadAlerts(), loadEvaluationStatus()])
        emitAlertStateChanged()
        ElMessage.success(`评估完成，命中 ${res.data.data.detected} 条规则，自动恢复 ${res.data.data.auto_resolved ?? 0} 条`)
    } catch {
        ElMessage.error('告警评估失败')
    } finally {
        evaluating.value = false
    }
}

// 作用：保存通知策略草稿。为什么：保存后用返回值回填草稿并刷新通道状态，使分级/开关等改动即时生效。
const handleSaveSettings = async () => {
    if (!settingsDraft.value) {
        return
    }

    settingsSaving.value = true

    try {
        const res = await updateAlertSettings(settingsDraft.value)
        settingsDraft.value = structuredClone(res.data.data)
        await loadNotificationStatus()
        ElMessage.success('通知策略已保存')
    } catch {
        ElMessage.error('通知策略保存失败，请检查参数范围')
    } finally {
        settingsSaving.value = false
    }
}

/**
 * 测试 Telegram / 邮件通知通道。
 */
const handleTestNotification = async () => {
    testingNotification.value = true

    try {
        const res = await testAlertNotification({
            channels: channelKeys.value,
            message: 'Ops Center 告警中心通知通道测试。',
        })
        const result = res.data.data.result
        const sentChannels = Object.entries(result)
            .filter(([, item]) => item.sent)
            .map(([channel]) => channel)

        if (sentChannels.length > 0) {
            ElMessage.success(`通知测试成功：${sentChannels.join(', ')}`)
            return
        }

        ElMessage.warning('通知测试未发送，请检查 Telegram / 邮件配置是否启用')
    } catch {
        ElMessage.error('通知测试失败')
    } finally {
        testingNotification.value = false
    }
}

/**
 * 生成一组接近真实值班流程的演示告警。
 */
const handleDemoScenarios = async () => {
    demoLoading.value = true

    try {
        const res = await createAlertDemoScenarios()
        const result = res.data.data

        if (!result.enabled) {
            ElMessage.warning('模拟数据入口未启用，请检查 OPS_ALERT_DEMO_ENABLED')
            return
        }

        summary.value = result.summary
        status.value = 'all'
        page.value = 1
        await loadAlerts()
        emitAlertStateChanged()
        ElMessage.success(`已生成 ${result.created} 条模拟告警，覆盖触发、确认与恢复流程`)
    } catch {
        ElMessage.error('模拟告警生成失败')
    } finally {
        demoLoading.value = false
    }
}

/**
 * 保存单条规则阈值。
 */
const handleSaveRule = async (rule: AlertRule) => {
    const draft = ruleDrafts.value[rule.key]

    if (!draft) {
        ElMessage.error('规则草稿不存在，请刷新后重试')
        return
    }

    savingRuleKey.value = rule.key

    try {
        const res = await updateAlertRule(rule.key, {
            warning_threshold: draft.warning_threshold,
            critical_threshold: draft.critical_threshold,
            is_active: rule.is_active,
        })
        replaceRule(res.data.data)
        await Promise.all([loadAlertRules(), loadSummary()])
        ElMessage.success('告警规则已保存')
    } catch {
        ElMessage.error('告警规则保存失败，请检查阈值范围')
    } finally {
        savingRuleKey.value = null
    }
}

/**
 * 启用或禁用单条规则。
 */
const handleToggleRule = async (rule: AlertRule, isActive: boolean) => {
    togglingRuleKey.value = rule.key

    try {
        const res = await toggleAlertRule(rule.key, isActive)
        replaceRule(res.data.data)
        await Promise.all([loadAlertRules(), loadSummary()])
        ElMessage.success(isActive ? '告警规则已启用' : '告警规则已禁用')
    } catch {
        rule.is_active = !isActive
        ElMessage.error('告警规则状态更新失败')
    } finally {
        togglingRuleKey.value = null
    }
}

/**
 * 用接口返回值替换页面中的规则。
 */
const replaceRule = (rule: AlertRule) => {
    alertRules.value = alertRules.value.map(item => item.key === rule.key ? rule : item)
    ruleDrafts.value[rule.key] = {
        warning_threshold: rule.warning_threshold,
        critical_threshold: rule.critical_threshold,
        is_active: rule.is_active,
    }
}

/**
 * 导出全部规则的可调字段为 JSON 文件。
 */
const handleExportRules = async () => {
    try {
        const res = await exportAlertRules()
        const blob = new Blob([JSON.stringify(res.data.data, null, 2)], { type: 'application/json' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `ops-alert-rules-${Date.now()}.json`
        link.click()
        URL.revokeObjectURL(url)
        ElMessage.success('已导出规则 JSON')
    } catch {
        ElMessage.error('规则导出失败')
    }
}

/**
 * 粘贴规则 JSON 导入（仅认白名单 key 的阈值/启停，非法项跳过并报告）。
 */
const handleImportRules = async () => {
    let text = ''

    try {
        const { value } = await ElMessageBox.prompt(
            '粘贴导出的规则 JSON（含 rules 数组，或直接是规则数组）',
            '导入规则',
            {
                confirmButtonText: '导入',
                cancelButtonText: '取消',
                inputType: 'textarea',
                inputPlaceholder: '{ "rules": [ { "key": "disk_usage", "warning_threshold": 85, "critical_threshold": 95, "is_active": true } ] }',
            },
        )
        text = value
    } catch {
        return
    }

    let rules: AlertRuleExportItem[]

    try {
        const parsed = JSON.parse(text)
        rules = Array.isArray(parsed) ? parsed : parsed.rules
        if (!Array.isArray(rules)) {
            throw new Error('missing rules array')
        }
    } catch {
        ElMessage.error('JSON 解析失败，请检查格式')
        return
    }

    importingRules.value = true

    try {
        const res = await importAlertRules(rules)
        const { applied, total, skipped } = res.data.data
        await Promise.all([loadAlertRules(), loadSummary()])

        if (skipped.length > 0) {
            ElMessage.warning(`导入完成：应用 ${applied}/${total}，跳过 ${skipped.length}（未知或越界的规则）`)
        } else {
            ElMessage.success(`导入完成：应用 ${applied}/${total} 条规则`)
        }
    } catch {
        ElMessage.error('规则导入失败，请检查权限或数据结构')
    } finally {
        importingRules.value = false
    }
}

/**
 * 筛选条件变化后回到第一页。
 */
const handleFilterChange = async () => {
    page.value = 1
    await loadAlerts()
}

// 作用：加载已保存的筛选预设列表。为什么：填充「筛选预设」下拉，失败时置空不影响其他筛选。
const loadPresets = async () => {
    try {
        const res = await getAlertPresets()
        presets.value = res.data.data.items
    } catch {
        presets.value = []
    }
}

// 作用：套用选中的筛选预设。为什么：把预设里的 status/severity/source 写回筛选状态并重新查询，快速复用常用过滤组合。
const applyPreset = async (id: number | undefined) => {
    const preset = presets.value.find(p => p.id === id)
    if (!preset) return
    status.value = (preset.filters.status as AlertStatus) ?? 'all'
    severity.value = preset.filters.severity ?? ''
    source.value = preset.filters.source ?? ''
    await handleFilterChange()
}

// 作用：把当前筛选条件命名保存为预设。为什么：只收集非默认的 status/severity/source 组成预设，便于后续一键复用。
const handleSavePreset = async () => {
    try {
        const { value } = await ElMessageBox.prompt('为当前筛选取个名字', '保存筛选预设', {
            confirmButtonText: '保存',
            cancelButtonText: '取消',
            inputPattern: /\S+/,
            inputErrorMessage: '名称不能为空',
        })
        const filters: Record<string, string> = {}
        if (status.value && status.value !== 'all') filters.status = status.value
        if (severity.value) filters.severity = severity.value
        if (source.value) filters.source = source.value
        await saveAlertPreset({ name: value.trim(), filters })
        ElMessage.success('已保存预设')
        await loadPresets()
    } catch {
        // 取消或校验失败
    }
}

// 作用：删除当前选中的筛选预设。为什么：二次确认后删除并清空选中态、刷新列表；用户取消则直接返回。
const handleDeletePreset = async () => {
    if (!selectedPresetId.value) return
    try {
        await ElMessageBox.confirm('删除后不可恢复。', '删除预设', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消',
        })
    } catch {
        return
    }
    await deleteAlertPreset(selectedPresetId.value)
    selectedPresetId.value = undefined
    ElMessage.success('已删除')
    await loadPresets()
}

/**
 * 每页条数变化后回到第一页。
 */
const handlePageSizeChange = async () => {
    page.value = 1
    await loadAlerts()
}

/**
 * 确认告警。
 */
const handleAcknowledge = async (alert: OpsAlert) => {
    try {
        const { value } = await ElMessageBox.prompt('填写确认备注，可留空', '确认告警', {
            confirmButtonText: '确认',
            cancelButtonText: '取消',
            inputPlaceholder: '例如：已处理、观察中',
        })

        acknowledgingId.value = alert.id
        await acknowledgeAlert(alert.id, {
            acknowledged_by: 'ops-user',
            note: value,
        })

        ElMessage.success('告警已确认')
        emitAlertStateChanged()
        await Promise.all([loadSummary(), loadAlerts()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警确认失败')
        }
    } finally {
        acknowledgingId.value = null
    }
}

// 作用：把告警指派给手填的负责人。为什么：弹窗校验负责人字符集后写入指派，成功后刷新列表与指派人下拉数据源。
const handleAssign = async (alert: OpsAlert) => {
    try {
        const { value } = await ElMessageBox.prompt('填写负责人', '指派告警', {
            confirmButtonText: '指派',
            cancelButtonText: '取消',
            inputPlaceholder: '例如：on-call-a',
            inputPattern: /^[\p{L}\p{N}@._\-\s]+$/u,
            inputErrorMessage: '负责人只能包含文字、数字、空格、@ . _ -',
        })

        assigningId.value = alert.id
        await assignAlert(alert.id, {
            assigned_to: value,
            note: `指派给 ${value}`,
        })

        ElMessage.success('告警已指派')
        await Promise.all([loadAlerts(), loadAssignees()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警指派失败')
        }
    } finally {
        assigningId.value = null
    }
}

/**
 * 把告警认领给当前登录管理员（指派给我）。
 */
const handleClaim = async (alert: OpsAlert) => {
    if (!currentAdminName.value) {
        return
    }

    assigningId.value = alert.id

    try {
        await assignAlert(alert.id, {
            assigned_to: currentAdminName.value,
            note: `认领：${currentAdminName.value}`,
        })
        ElMessage.success('已认领该告警')
        await Promise.all([loadAlerts(), loadAssignees()])
    } catch {
        ElMessage.error('认领失败')
    } finally {
        assigningId.value = null
    }
}

/**
 * 加载已指派处理人列表（筛选下拉用）。
 */
const loadAssignees = async () => {
    try {
        const res = await getAlertAssignees()
        assignees.value = res.data.data.items
    } catch {
        assignees.value = []
    }
}

/**
 * 加载告警分组聚合。
 */
const loadGroups = async () => {
    groupsLoading.value = true
    try {
        const res = await getAlertGroups(groupBy.value)
        alertGroups.value = res.data.data.groups
    } catch {
        alertGroups.value = []
    } finally {
        groupsLoading.value = false
    }
}

// 作用：分组显示名转换。为什么：把哨兵值 __unassigned__ 显示为「未指派」，其余原样返回。
const groupLabel = (row: AlertGroup) => (row.group === '__unassigned__' ? '未指派' : row.group)

// 作用：批量确认整个分组的告警。为什么：二次确认后按分组维度批量 ack，成功后同时刷新分组/列表/概览三处数据。
const batchAck = async (row: AlertGroup) => {
    try {
        await ElMessageBox.confirm(`确认整组「${groupLabel(row)}」的 ${row.total} 条告警？`, '批量确认', { type: 'warning' })
    } catch {
        return
    }
    try {
        const res = await batchAcknowledgeGroup({ by: row.by, group: row.group })
        ElMessage.success(`已确认 ${res.data.data.affected} 条`)
        await Promise.all([loadGroups(), loadAlerts(), loadSummary()])
    } catch {
        ElMessage.error('批量确认失败')
    }
}

// 作用：把整个分组批量指派给某负责人。为什么：一次性给同类告警派单，成功后刷新分组/列表/指派人列表。
const batchAssign = async (row: AlertGroup) => {
    try {
        const { value } = await ElMessageBox.prompt(`把「${groupLabel(row)}」整组指派给谁？`, '批量指派', {
            inputPattern: /^[\p{L}\p{N}@._\-\s]+$/u,
            inputErrorMessage: '负责人只能包含文字、数字、空格、@ . _ -',
        })
        const res = await batchAssignGroup({ by: row.by, group: row.group, assigned_to: value })
        ElMessage.success(`已指派 ${res.data.data.affected} 条给 ${value}`)
        await Promise.all([loadGroups(), loadAlerts(), loadAssignees()])
    } catch (e) {
        if (e !== 'cancel') ElMessage.error('批量指派失败')
    }
}

// 作用：对整个分组批量静默指定分钟数。为什么：临时抑制同类告警外发降噪，成功后刷新顶部静默计数。
const batchSilence = async (row: AlertGroup) => {
    try {
        const { value } = await ElMessageBox.prompt(`静默「${groupLabel(row)}」多少分钟？`, '批量静默', {
            inputValue: '60',
            inputPattern: /^\d+$/,
            inputErrorMessage: '请输入分钟数',
        })
        await batchSilenceGroup({ by: row.by, group: row.group, minutes: Number(value) })
        ElMessage.success(`已静默 ${groupLabel(row)} ${value} 分钟`)
        await loadSilences()
    } catch (e) {
        if (e !== 'cancel') ElMessage.error('批量静默失败')
    }
}

// 作用：打开统计周报弹窗并拉取近 7 天报告。为什么：汇总 SLA/MTTR 等指标供复盘，失败时置空显示空态。
const openReport = async () => {
    reportVisible.value = true
    try {
        const res = await getAlertReport(7)
        report.value = res.data.data
    } catch {
        report.value = null
    }
}

/**
 * 标记告警已恢复。
 */
const handleResolve = async (alert: OpsAlert) => {
    try {
        await ElMessageBox.confirm('确认该告警已恢复？', '恢复告警', {
            confirmButtonText: '确认恢复',
            cancelButtonText: '取消',
            type: 'success',
        })

        resolvingId.value = alert.id
        await resolveAlert(alert.id, {
            acknowledged_by: 'ops-user',
            note: '已恢复',
        })

        ElMessage.success('告警已恢复')
        emitAlertStateChanged()
        await Promise.all([loadSummary(), loadAlerts()])
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('告警恢复失败')
        }
    } finally {
        resolvingId.value = null
    }
}

/**
 * 通知布局层刷新告警徽标。
 */
const emitAlertStateChanged = () => {
    window.dispatchEvent(new CustomEvent('ops:alerts-updated'))
}

/**
 * 建立告警 WebSocket 监听。
 */
const startRealtime = () => {
    if (channel) {
        return
    }

    channel = echo.channel('ops.alerts')
        .listen('.alert.triggered', async (payload: AlertRealtimePayload) => {
            realtimeConnected.value = true
            ElMessage.warning(`${payload.source}: ${payload.title}`)
            await Promise.all([loadSummary(), loadAlerts()])
        })
        .error(() => {
            realtimeConnected.value = false
        })

    realtimeConnected.value = true
}

/**
 * 离开页面时释放频道。
 */
const stopRealtime = () => {
    if (channel) {
        echo.leaveChannel('ops.alerts')
        channel = null
    }

    realtimeConnected.value = false
}

// 作用：把严重级映射为 el-tag 的类型色。为什么：critical→danger、warning→warning、其余→info，统一列表标签配色。
const severityTag = (value: string) => {
    if (value === 'critical') {
        return 'danger'
    }

    if (value === 'warning') {
        return 'warning'
    }

    return 'info'
}

// 作用：把告警状态英文枚举转中文文案。为什么：open→未处理、acknowledged→已确认、其余→已恢复，用于列表与详情展示。
const statusLabel = (value: string) => {
    if (value === 'open') {
        return '未处理'
    }

    if (value === 'acknowledged') {
        return '已确认'
    }

    return '已恢复'
}

// 作用：按单位决定阈值输入框的小数精度。为什么：MB/s 类速率保留 2 位小数，其余（如百分比/计数）取整。
const thresholdPrecision = (unit: string | null) => unit === 'MB/s' ? 2 : 0

// 作用：加载告警趋势并渲染 echarts 折线图。为什么：惰性初始化图表实例、装配 option 并 resize，保证首绘与容器尺寸正确。
const loadTrend = async () => {
    trendLoading.value = true

    try {
        const res = await getAlertTrend(trendDays.value)
        const buckets = res.data.data.buckets
        // 若所有分桶评估次数均为 0，则视为无数据（切换空态、隐藏图表）
        trendEmpty.value = buckets.every(bucket => bucket.evaluations === 0)

        // 惰性初始化：仅当实例未创建且容器已挂载时 init（v-show 保证容器始终存在）
        if (!trendChart && trendRef.value) {
            trendChart = echarts.init(trendRef.value)
        }

        // 装配 echarts option：双折线（命中告警红 / 自动恢复绿）+ 轴/图例/tooltip 配置
        trendChart?.setOption({
            tooltip: { trigger: 'axis' },
            legend: { top: 8, left: 'center' },
            grid: { top: 44, left: 8, right: 16, bottom: 8, containLabel: true },
            xAxis: { type: 'category', boundaryGap: false, data: buckets.map(bucket => bucket.date) },
            yAxis: { type: 'value', minInterval: 1 },
            series: [
                { name: '命中告警', type: 'line', smooth: true, itemStyle: { color: '#dc2626' }, data: buckets.map(bucket => bucket.detected) },
                { name: '自动恢复', type: 'line', smooth: true, itemStyle: { color: '#16a34a' }, data: buckets.map(bucket => bucket.auto_resolved) },
            ],
        })

        // 重新计算图表尺寸以适配当前容器宽度（数据窗切换/首绘后）
        trendChart?.resize()
    } finally {
        trendLoading.value = false
    }
}

// 作用：窗口 resize 时同步重绘趋势图。为什么：echarts 不会自动感知容器变化，需手动 resize 保持自适应。
const handleTrendResize = () => trendChart?.resize()

// 生命周期：挂载时并发加载全部初始数据，随后建立实时订阅并注册窗口 resize 监听。
onMounted(async () => {
    await Promise.all([loadSummary(), loadAlerts(), loadNotificationStatus(), loadAlertRules(), loadAlertSettings(), loadEvaluationStatus(), loadTrend(), loadSilences(), loadPresets(), loadAssignees(), loadGroups()])
    startRealtime()
    window.addEventListener('resize', handleTrendResize)
})

// 生命周期：卸载前解绑 resize 监听、断开实时频道并销毁 echarts 实例，防止内存泄漏与重复订阅。
onBeforeUnmount(() => {
    window.removeEventListener('resize', handleTrendResize)
    stopRealtime()
    trendChart?.dispose()
    trendChart = null
})
</script>

<style scoped>
.alerts-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.trend-days {
    width: 130px;
}

.trend-chart {
    height: 280px;
    min-height: 240px;
}

.summary-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.notification-card,
.evaluation-card,
.rule-card {
    border-radius: 8px;
}

.notification-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.notification-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 14px;
}

.settings-panel {
    border-top: 1px solid #e5e7eb;
    margin-top: 14px;
    padding-top: 14px;
}

.settings-form {
    margin-top: 10px;
}

.severity-grid,
.evaluation-grid {
    display: grid;
    gap: 8px;
}

.severity-row,
.evaluation-grid {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
}

.severity-row {
    gap: 10px;
}

.severity-label {
    color: #111827;
    font-weight: 700;
    min-width: 70px;
}

.evaluation-grid {
    color: #475569;
    gap: 12px;
    margin-top: 14px;
}

.inline-help {
    margin-left: 8px;
}

.escalated-tag {
    margin-left: 6px;
}

.notification-item {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px;
}

.channel-title {
    align-items: center;
    color: #111827;
    display: flex;
    font-weight: 700;
    justify-content: space-between;
}

.channel-desc {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 8px;
}

.rule-notice {
    margin-bottom: 12px;
}

.summary-card {
    border-radius: 8px;
}

.summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.summary-value {
    color: #111827;
    font-size: 30px;
    font-weight: 800;
    margin-top: 8px;
}

.summary-value.critical {
    color: #dc2626;
}

.summary-value.warning {
    color: #d97706;
}

.summary-value.info {
    color: #2563eb;
}

.summary-value.open {
    color: #111827;
}

.summary-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 4px;
}

.panel-header,
.filters,
.pagination-bar {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.panel-title {
    color: #111827;
    font-size: 18px;
    font-weight: 700;
}

.panel-subtitle {
    color: #64748b;
    font-size: 13px;
    margin-top: 4px;
}

.filters {
    gap: 12px;
    justify-content: flex-start;
    margin-bottom: 14px;
}

.filters :deep(.el-select) {
    width: 160px;
}

.alert-title {
    color: #111827;
    font-weight: 700;
}

.alert-message {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 4px;
}

.muted {
    color: #94a3b8;
    font-size: 12px;
}

.action-buttons {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 8px;
}

.action-buttons :deep(.el-button + .el-button) {
    margin-left: 0;
}

.pagination-bar {
    justify-content: flex-end;
    padding-top: 14px;
}

@media (max-width: 1100px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .notification-grid {
        grid-template-columns: 1fr;
    }

    .panel-header,
    .filters {
        align-items: stretch;
        flex-direction: column;
    }

    .filters :deep(.el-select) {
        width: 100%;
    }
}
</style>
