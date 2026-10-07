## Decision

复用现有 gzip on / gzip_vary on / gzip_min_length 1024 配置，仅在 gzip_types 增加 application/json，保留默认压缩级别。使用标准 HTTP 编码协商，无应用缓存或数据延迟。

字段/能力映射：所有 JSON 字段和值仍位于解压后相同路径；所有 HTTP 端点、状态和权限检查不变；原有 CSS/JS 压缩和 build/assets 缓存不变；不支持 gzip 的调用方继续收到明文 JSON。

## Alternatives

Redis 仅能减少被缓存部分的服务端工作，不能降低现有大响应的下载量；当前 SQL 总量仅数十 ms，缓存权限/业务数据还需失效设计，故不引入。删除字段或重构客户端不属于此次配置修正。

## Rollout and rollback

先保存实际线上 snippet 基线及哈希；候选必须仅有目标 MIME 变化。使用生产 Nginx 二进制在临时监听端口测试实际候选配置、JSON gzip/identity/q=0、小 JSON、CSS/JS 与静态资源错误状态。上线前严格 OpenSpec 校验与质量门禁；写入前比较线上基线，原子替换，nginx -t 成功再 reload。若语法、协商、登录或 JSON 语义检查失败，则恢复基线并重新验证/reload。禁止全量 deploy.sh 同步脏代码。

## Evidence boundaries

服务器进程内控制器测量不含完整 FPM/HTTP middleware 和网络。网络对照用同一会话交错 identity/gzip，保留样本数量与波动，不保证每次小于某个毫秒数。模拟器录屏是页面内容出现的近似时间，不是精密触控延迟。生产测试只读取业务数据；不触发 AI 生成、招采研究或写入回归。

Reference: https://nginx.org/en/docs/http/ngx_http_gzip_module.html
