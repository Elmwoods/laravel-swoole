// @ts-ignore
import request from '@/utils/request'

export function getOctaneStatus() {
    return request({
        url: '/api/ops/octane/status',
        method: 'get',
    })
}
