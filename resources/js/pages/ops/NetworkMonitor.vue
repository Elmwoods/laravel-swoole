<template>
    <div class="network-monitor">

        <el-card>

            <!-- 卡片标题 -->
            <template #header>

                <span>Network 流量监控</span>

                <el-button
                    style="float:right"
                    type="primary"
                    size="small"
                    @click="fetchData"
                >
                    刷新
                </el-button>

            </template>

            <!-- 网络流量表格 -->
            <el-table
                :data="networks"
                style="width:100%"
            >

                <!-- 网卡名称 -->
                <el-table-column
                    prop="iface"
                    label="网卡"
                    width="150"
                />

                <!-- 下载 KB/s -->
                <el-table-column
                    prop="rx_kb_s"
                    label="下载(KB/s)"
                />

                <!-- 上传 KB/s -->
                <el-table-column
                    prop="tx_kb_s"
                    label="上传(KB/s)"
                />

                <!-- 下载 MB/s -->
                <el-table-column
                    prop="rx_mb_s"
                    label="下载(MB/s)"
                />

                <!-- 上传 MB/s -->
                <el-table-column
                    prop="tx_mb_s"
                    label="上传(MB/s)"
                />

                <el-table-column label="状态">
                    <template #default="{ row }">
                        <el-tag
                            :type="
                row.rx_kb_s > 0 ||
                row.tx_kb_s > 0
                    ? 'success'
                    : 'info'
            "
                        >
                            {{
                                row.rx_kb_s > 0 ||
                                row.tx_kb_s > 0
                                    ? 'Active'
                                    : 'Idle'
                            }}
                        </el-tag>
                    </template>
                </el-table-column>
            </el-table>

            <!-- 更新时间 -->
            <div class="time">
                更新时间：{{ timestamp }}
            </div>

        </el-card>

    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import echo from '@/utils/echo'

/**
 * 单个网卡数据结构
 */
interface NetworkItem {

    /**
     * 网卡名称
     * eth0
     * ens33
     * docker0
     */
    iface: string

    /**
     * 下载速度
     */
    rx_kb_s: number

    /**
     * 上传速度
     */
    tx_kb_s: number

    /**
     * 下载速度
     */
    rx_mb_s: number

    /**
     * 上传速度
     */
    tx_mb_s: number
}

/**
 * 网络数据
 */
const networks = ref<NetworkItem[]>([])

/**
 * 更新时间
 */
const timestamp = ref('')

/**
 * 获取初始数据
 *
 * 页面首次进入时调用
 */
const fetchData = async () => {

    try {

        const res = await axios.get(
            '/api/ops/network'
        )

        const interfaces =
            res.data?.data?.interfaces ?? {}

        /**
         * 转换对象 -> 数组
         *
         * 后端:
         * {
         *   eth0:{},
         *   lo:{}
         * }
         *
         * 前端:
         * [
         *   {iface:'eth0',...},
         *   {iface:'lo',...}
         * ]
         */
        networks.value = Object
            .entries(interfaces)
            .map(([iface, item]: any) => ({

                iface,

                rx_kb_s: item.rx_kb_s ?? 0,
                tx_kb_s: item.tx_kb_s ?? 0,

                rx_mb_s: item.rx_mb_s ?? 0,
                tx_mb_s: item.tx_mb_s ?? 0,
            }))

        timestamp.value =
            new Date().toLocaleString()

    } catch (error) {

        console.error(
            'network fetch failed',
            error
        )
    }
}

/**
 * websocket channel
 */
let channel: any = null

/**
 * 页面挂载
 */
onMounted(() => {

    /**
     * 首次加载
     */
    fetchData()

    /**
     * WebSocket 实时监听
     *
     * Channel:
     * ops.system.metrics
     *
     * Event:
     * network.updated
     */
    channel = echo
        .channel('ops.system.metrics')
        .listen(
            '.network.updated',
            (event: any) => {

                try {

                    /**
                     * 当前收到的数据格式：
                     *
                     * {
                     *   status: "ok",
                     *   timestamp: 1781013710,
                     *   interfaces: {
                     *      eth0: {...},
                     *      lo: {...}
                     *   }
                     * }
                     */
                    // console.logs('[network.updated]', event)

                    /**
                     * 状态检查
                     */
                    if (event?.status !== 'ok') {
                        return
                    }

                    /**
                     * 网卡数据
                     */
                    const interfaces =
                        event?.interfaces ?? {}

                    // console.logs(
                    //     '[interfaces]',
                    //     interfaces
                    // )

                    /**
                     * 转换为表格数据
                     *
                     * {
                     *   eth0: {...},
                     *   lo: {...}
                     * }
                     *
                     * =>
                     *
                     * [
                     *   {
                     *      iface:'eth0',
                     *      ...
                     *   },
                     *   {
                     *      iface:'lo',
                     *      ...
                     *   }
                     * ]
                     */
                    networks.value = Object
                        .entries(interfaces)
                        .map(([iface, item]: any) => ({

                            /**
                             * 网卡名称
                             */
                            iface,

                            /**
                             * 下载速度 KB/s
                             */
                            rx_kb_s: Number(
                                item?.rx_kb_s ?? 0
                            ),

                            /**
                             * 上传速度 KB/s
                             */
                            tx_kb_s: Number(
                                item?.tx_kb_s ?? 0
                            ),

                            /**
                             * 下载速度 MB/s
                             */
                            rx_mb_s: Number(
                                item?.rx_mb_s ?? 0
                            ),

                            /**
                             * 上传速度 MB/s
                             */
                            tx_mb_s: Number(
                                item?.tx_mb_s ?? 0
                            ),
                        }))

                    /**
                     * 按下载速度排序
                     *
                     * 流量最大的网卡排前面
                     */
                    networks.value.sort(
                        (a, b) =>
                            b.rx_kb_s - a.rx_kb_s
                    )

                    /**
                     * 更新时间
                     */
                    timestamp.value = event.timestamp
                        ? new Date(
                            event.timestamp * 1000
                        ).toLocaleString()
                        : new Date().toLocaleString()

                } catch (error) {

                    console.error(
                        '[NetworkMonitor] WebSocket 数据解析失败',
                        error
                    )
                }
            }
        )
})

/**
 * 页面销毁
 */
onBeforeUnmount(() => {

    /**
     * 离开频道
     */
    if (channel) {

        echo.leaveChannel(
            'ops.system.metrics'
        )

        channel = null
    }
})
</script>

<style scoped>
.network-monitor {
    padding: 20px;
}

.time {
    margin-top: 15px;
    color: #909399;
    font-size: 13px;
}
</style>
