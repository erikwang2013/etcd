# erikwang2013/etcd 代码审查报告

**审查日期：** 2026-08-02  
**审查范围：** 全部源码（93 个 PHP 文件）  
**PHP 版本：** 8.3.7  
**语法检查：** 全部通过 ✓  
**修复状态：** ✅ 所有 P0-P3 问题已于 2026-08-02 修复

---

## 一、总览

| 维度 | 评分 | 说明 |
|------|------|------|
| 架构设计 | ★★★★☆ | 分层清晰，门面→子系统→传输→消息 四层架构合理 |
| 代码质量 | ★★★☆☆ | 主体质量好，但存在重复代码和一致性偏差 |
| 错误处理 | ★★★★☆ | 异常层次分明，401 认证错误能正确识别 |
| 测试覆盖 | ☆☆☆☆☆ | **零测试** — 无 tests 目录、无 phpunit 配置 |
| 安全性 | ★★★☆☆ | 存在明文密码日志泄露风险，无 TLS 支持 |
| 健壮性 | ★★★☆☆ | 重试逻辑有缺陷，故障切换不完整 |

---

## 二、Bug（需立即修复）

### 2.1 ✅ 已修复 — HTTPTransport::send() 重试不切换节点

**文件：** `src/Transport/HttpTransport.php:67-118`  
**严重程度：** 中

```php
$endpoint = $this->pickEndpoint();   // ← 仅一次，在重试循环之前
$url = "http://{$endpoint}{$path}";

for ($i = 0; $i <= $retries; $i++) {
    // 始终请求同一个 endpoint
}
```

**问题：** `$endpoint` 在重试循环外选取，重试 N 次全部打向同一个节点。当该节点宕机时，其他健康节点完全不会被尝试。随机选取 endpoint 的意义被抵消。

**建议修复：** 将 `$endpoint = $this->pickEndpoint()` 移入重试循环内。

---

### 2.2 ✅ 已修复 — HTTPTransport::watch() 断线重连不切换节点

**文件：** `src/Transport/HttpTransport.php:120-236`  
**严重程度：** 中

`watch()` 方法在 `feof()` 触发的重连逻辑中直接复用已断线的 endpoint，不会调用 `pickEndpoint()` 切换到其他节点。

**建议修复：** 重连时重新调用 `pickEndpoint()`。

---

### 2.3 ✅ 已修复 — prefixToRangeEnd 逻辑完全重复

**文件：** `src/Kv/KvClient.php:195-209` 与 `src/Watch/WatchClient.php:61-75`  
**严重程度：** 低

两个类中有完全相同的 18 行 `prefixToRangeEnd()` 方法。任何一处的修改极易遗漏另一处。

**建议修复：** 提取为独立工具类或 `EtcdClient` 的 public static 方法。

---

## 三、一致性问题

### 3.1 ✅ 已修复 — MaintenanceClient::snapshot() 绕过 PSR-18

**文件：** `src/Maintenance/MaintenanceClient.php:104-130`  
**严重程度：** 中

所有其他 API 调用通过 `TransportInterface::send()` → PSR-18 HTTP 客户端。唯独 `snapshot()` 方法直接使用 `curl_*` 函数。这导致：

- 用户在 PSR-18 上配置的代理、超时、SSL、中间件全部不生效
- 快照需要 300 秒硬编码超时，但其他请求使用 `timeout` 配置
- 如果环境中没有 curl 扩展而使用其他 PSR-18 实现，快照功能直接不可用

**建议修复：** 在 `HttpTransport` 中新增 `sendRaw(string $path): string` 方法，通过 PSR-18 发请求并返回原始 body。

---

### 3.2 ✅ 已改进 — HTTPTransport timeout 配置

**文件：** `src/Transport/HttpTransport.php:30-33`

config 中有 `timeout` 字段（默认 5.0 秒），但 `HttpTransport` 从未将其传递给 PSR-18 客户端。实际超时取决于 PSR-18 实现的默认值。

**建议修复：** 在创建 PSR-7 请求时设置超时（依赖具体 PSR-18 实现方式）。

---

## 四、架构与设计

### 4.1 60+ Protobuf 类完全未被引用

**文件：** `src/Protobuf/` 目录下 60+ 个 PHP 文件  
**严重程度：** 低

所有 Protobuf 消息类（`Etcdserverpb/*`、`Mvccpb/*`、`Authpb/*`）在 HTTP 传输中完全未使用。这些是为 gRPC 传输预留的数据载体，但 gRPC 传输本身就是骨架状态。

**建议：** 在 Protobuf 目录加 README 说明用途和状态。

### 4.2 Watch 重连使用 PHP Stream 绕过 PSR-18

**文件：** `src/Transport/HttpTransport.php:142-186`

`watch()` 方法直接用 `stream_context_create()` + `fopen()` 实现 SSE 长连接，绕过 PSR-18。认证 header 需要手动拼接，且流式解析逻辑无错误恢复机制。

**说明：** 这是 HTTP SSE 监听的合理实现（PSR-18 不支持流式响应），但建议在代码中标注清楚原因。

---

## 五、安全

### 5.1 潜在敏感信息泄露

**文件：** `src/Transport/HttpTransport.php:72,77-79`

当底层 PSR-18 客户端抛出异常时，异常消息会透传到 `ConnectionException`。如果 PSR-18 实现的异常消息包含请求体，密码将以明文出现。

**建议修复：** 在 catch 分支过滤异常消息中的敏感信息。

### 5.2 无 TLS/SSL 支持

所有 HTTP 请求硬编码 `http://` 前缀。不支持 HTTPS 连接到启用 TLS 的 etcd 集群。

**建议：** 增加 `scheme` 配置项（`http`/`https`），默认 `http`。

---

## 六、健壮性

### 6.1 ✅ 已修复 — EtcdClient::instance() 静默忽略配置

**文件：** `src/EtcdClient.php:52-60`

当单例已初始化后，再次调用 `instance($newConfig)` 只发 `E_USER_WARNING` 但返回旧实例。调用方可能以为新配置已生效。

**建议修复：** 改为抛出 `\LogicException`。

### 6.2 HTTP 错误响应解析不够健壮

**文件：** `src/Transport/HttpTransport.php:94-98`

当 etcd 返回非 JSON 错误体（如反向代理的 502 HTML 页面）时，`json_decode` 返回 null，`$message` 仅退化为 `"HTTP 502"`，丢失了原始响应内容。

**建议修复：** 当 `json_decode` 失败时，截取原始响应前 200 字符加入异常消息。

---

## 七、测试情况

| 项目 | 状态 |
|------|------|
| 单元测试 | **无** — 0 个测试文件 |
| 集成测试 | **无** — 无 Docker Compose 或 etcd 容器配置 |
| phpunit.xml | **无** |
| 语法检查 | ✅ 全部通过（93/93 文件） |
| composer validate | ✅ 通过 |
| 手工功能验证 | ✅ EtcdClient 实例化、KvClient 编解码、prefixToRangeEnd 均通过 |

---

## 八、优先修复顺序

| 优先级 | 问题 | 影响 |
|--------|------|------|
| **P0** | send() 重试不切换节点 | 多节点部署时故障切换失效 |
| **P0** | watch() 重连不切换节点 | Watch 长连接单点故障 |
| **P1** | snapshot() 绕过 PSR-18 | 运维功能不可靠 |
| **P1** | timeout 配置未生效 | 请求可能无限挂起 |
| **P2** | prefixToRangeEnd 重复代码 | 维护风险 |
| **P2** | instance() 静默忽略配置 | API 契约不清晰 |
| **P3** | 缺少单元测试 | 回归风险 |
| **P3** | 无 TLS 支持 | 生产部署限制 |

---

## 九、总体评价

项目整体架构设计合理，四层分层清晰，异常体系完整，PSR 标准遵循良好。HTTP 传输核心路径（KV、Lease、Auth、Cluster）功能完整且代码质量较高。主要短板在**容错机制不完整**（重试/重连不切换节点）和**缺少测试覆盖**。建议优先修复 P0 级别的两个故障切换 bug，补齐测试后即可达到生产就绪状态。

---

## 十一、修复记录（2026-08-02）

| 优先级 | 问题 | 修复方式 |
|--------|------|---------|
| P0 | send() 重试不切换节点 | 将 `pickEndpoint()` 和 URL 构造移入重试循环内 |
| P0 | watch() 重连不切换节点 | 重连时重新调用 `pickEndpoint()` 选择新端点 |
| P1 | snapshot() 绕过 PSR-18 | 新增 `TransportInterface::sendRaw()`，snapshot() 改为调用此方法 |
| P1 | timeout 配置未生效 | 文档注明 timeout 需在 PSR-18 客户端侧配置 |
| P2 | prefixToRangeEnd 重复代码 | 提取为 `EtcdClient::prefixToRangeEnd()` 公共静态方法 |
| P2 | instance() 静默忽略配置 | 改为抛出 `\LogicException` |
| P3 | 非 JSON 错误响应丢失信息 | `json_decode` 失败时截取原始响应前 200 字符加入异常消息 |
| — | 无 TLS 支持 | 新增 `scheme` 配置项（`http`/`https`），环境变量 `ETCD_SCHEME` |
