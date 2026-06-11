// @ts-ignore
import request from '@/utils/request'

export function getContainers() {
    return request.get('/api/ops/docker/containers')
}
