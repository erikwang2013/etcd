#!/usr/bin/env python3
"""Build the architecture / features / lifecycle SVGs, one set per language.

Source of truth for every string is the T dict below (Chinese). A language is
built by overlaying scripts/i18n/catalog/<lang>.json on top of it, so a catalog
only needs to carry the keys it translates.

    python3 scripts/i18n/build_diagrams.py zh en de        # build listed langs
    python3 scripts/i18n/build_diagrams.py --all           # every catalog in place
    python3 scripts/i18n/build_diagrams.py --dump zh       # print the full zh catalog
    python3 scripts/i18n/build_diagrams.py --check de      # validate only

Every string is measured before it is drawn. A translation that would need more
lines than its box can hold is reported and makes the run exit non-zero, so a
bad translation fails the build instead of quietly spilling out of its box.
"""
from __future__ import annotations

import html
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CATALOG = os.path.join(ROOT, "scripts", "i18n", "catalog")
OUTDIR = os.path.join(ROOT, "docs", "i18n", "diagrams")

RTL = {"ar"}

BG = "#0b1220"
PANEL = "#111c2e"
PANEL2 = "#16253c"
BORDER = "#20406b"
TEXT = "#e6edf3"
BODY = "#c3d0e0"
MUTED = "#8aa0bd"
DIM = "#5b7699"
BLUE = "#4fb3ff"
TEAL = "#2dd4bf"
AMBER = "#fbbf24"
RED = "#f87171"
PURPLE = "#a78bfa"
SKY = "#7dd3fc"
ORANGE = "#fb923c"

FONT = ("Helvetica,Arial,'Noto Sans','Noto Sans CJK SC','Noto Sans Devanagari','Noto Sans Bengali',"
        "'Noto Sans Arabic','Lohit Devanagari','PingFang SC','Microsoft YaHei',sans-serif")
MONO = "'DejaVu Sans Mono',Menlo,Consolas,'Noto Sans Mono CJK SC',monospace"
MF = 0.602     # monospace advance, exact for DejaVu Sans Mono / Menlo
AF = None      # None = measure sans text with the per-character table below

# Helvetica/Arial advance widths (em/1000). A flat "average character" factor
# over-measured real prose by 25%+, which wrapped text early and truncated
# single-line labels that would have fitted.
_ADV: dict[str, float] = {}
for _chars, _w in [
    (" ", .278), ("!", .278), ('"', .355), ("#", .556), ("$", .556), ("%", .889), ("&", .667), ("'", .191),
    ("(", .333), (")", .333), ("*", .389), ("+", .584), (",", .278), ("-", .333), (".", .278), ("/", .278),
    ("0123456789", .556), (":", .278), (";", .278), ("<", .584), ("=", .584), (">", .584), ("?", .556), ("@", 1.015),
    ("ABC", .667), ("D", .722), ("E", .667), ("F", .611), ("G", .778), ("H", .722), ("I", .278), ("J", .500),
    ("K", .667), ("L", .556), ("M", .833), ("N", .722), ("O", .778), ("P", .667), ("Q", .778), ("R", .722),
    ("S", .667), ("T", .611), ("U", .722), ("V", .667), ("W", .944), ("X", .667), ("Y", .667), ("Z", .611),
    ("[", .278), ("\\", .278), ("]", .278), ("^", .469), ("_", .556), ("`", .333),
    ("a", .556), ("b", .556), ("c", .500), ("d", .556), ("e", .556), ("f", .278), ("g", .556), ("h", .556),
    ("i", .222), ("j", .222), ("k", .500), ("l", .222), ("m", .833), ("n", .556), ("o", .556), ("p", .556),
    ("q", .556), ("r", .333), ("s", .500), ("t", .278), ("u", .556), ("v", .500), ("w", .722), ("x", .500),
    ("y", .500), ("z", .500), ("{", .334), ("|", .260), ("}", .334), ("~", .584),
]:
    for _c in _chars:
        _ADV[_c] = _w
DEFAULT_ADV = 0.56   # Cyrillic / Arabic / Devanagari / Bengali: proportional, unmeasured


# ------------------------------------------------------------------ catalog
T = {
    # ---- shared
    "common.arch_title": "erikwang2013/etcd · 架构设计",
    "common.arch_sub": "PHP etcd v3 客户端 —— 分层架构、依赖方向与错误语义",
    "common.arch_desc": "五层架构：框架适配层、门面层、子系统层、传输层与 etcd 服务端，附横切关注点、消息层与异常体系。",
    "common.arch_alt": "erikwang2013/etcd 架构设计图",
    "common.feat_title": "erikwang2013/etcd · 功能设计",
    "common.feat_sub": "六大 API 领域 —— 方法速览与行为约定",
    "common.feat_desc": "KV、Watch、Lease、Auth、Cluster、Maintenance 六大功能域及其方法与行为约定。",
    "common.feat_alt": "erikwang2013/etcd 功能设计图",
    "common.life_title": "erikwang2013/etcd · 生命周期",
    "common.life_sub": "一次请求、一条监听、一个租约 —— 三条主线",
    "common.life_desc": "请求生命周期、Watch 生命周期与 Lease 生命周期的三条主线。",
    "common.life_alt": "erikwang2013/etcd 生命周期图",

    # ---- architecture
    "arch.b1": "框架适配层",
    "arch.b1.note": "自动发现 / 手动注册",
    "arch.app.laravel.sub": "ServiceProvider + Facade",
    "arch.app.hyperf.sub": "ConfigProvider（自动发现）",
    "arch.app.thinkphp.sub": "Service + Facade",
    "arch.app.webman.sub": "Plugin::install()",
    "arch.b2": "门面层",
    "arch.facade": "EtcdClient —— 统一入口，惰性构建六大子系统",
    "arch.b2.note": "领域方法调用",
    "arch.b3": "子系统层 —— 六大 API 领域",
    "arch.kv.sub": "put / get / getByPrefix / txn / compact",
    "arch.watch.sub": "watch / watchPrefix · 断线续订",
    "arch.lease.sub": "grant / keepAlive / revoke / TTL",
    "arch.auth.sub": "RBAC：用户 / 角色 / 权限",
    "arch.cluster.sub": "成员增删 / Learner 提升",
    "arch.maint.sub": "status / alarm / defrag / snapshot",
    "arch.b3.note": "send() / sendRaw() / watch()",
    "arch.b4": "传输层",
    "arch.sel.body": "transport = grpc → GrpcTransport ｜ http → HttpTransport ｜ auto → 检测 ext-grpc 与 Grpc\\BaseStub，二者齐备才走 gRPC，否则回退 HTTP",
    "arch.http.sub1": "etcd 内置 gRPC-gateway，JSON over HTTP",
    "arch.http.sub2": "PSR-18 Client + PSR-17 Factory",
    "arch.http.badge": "可用",
    "arch.grpc.sub1": "Grpc\\Channel 复用，原生流式",
    "arch.grpc.sub2": "ext-grpc + grpc/grpc + google/protobuf",
    "arch.grpc.badge": "骨架",
    "arch.b4.note": "TCP :2379",
    "arch.b5": "etcd 服务端集群 (v3.x)",
    "arch.srv1.t": "gRPC-gateway  /v3/*",
    "arch.srv2.t": "原生 gRPC  :2379",
    "arch.srv3.t": "Raft + MVCC",
    "arch.srv1.sub": "HTTP JSON 入口",
    "arch.srv2.sub": "高性能 / 双向流",
    "arch.srv3.sub": "共识 · 多版本 · Lease 定时器",
    "arch.aside.title": "横切关注点",
    "arch.aside.1.t": "配置与环境变量",
    "arch.aside.1.b": "endpoints / transport / scheme / timeout / retry / auth，未传配置时读取 ETCD_* 环境变量。",
    "arch.aside.2.t": "单例生命周期",
    "arch.aside.2.b": "EtcdClient::instance() 供 Webman 等无 DI 场景；resetInstance() 供测试重置。",
    "arch.aside.3.t": "重试与退避",
    "arch.aside.3.b": "只有连接层失败才重试，最多 retry 次，间隔 100ms×n 线性退避并换端点。",
    "arch.aside.4.t": "base64 编解码",
    "arch.aside.4.b": "gRPC-gateway 要求 key / value 为 base64 字符串，收发两侧自动转换。",
    "arch.aside.5.t": "前缀 → range_end",
    "arch.aside.5.b": "prefixToRangeEnd() 按字节递增求区间上界，全 0xFF 时回退 \\x00。",
    "arch.msg.title": "消息层 · Protobuf 消息桩",
    "arch.msg.body": "纯 PHP 数据类，不继承 Google\\Protobuf\\Internal\\Message；由传输层编解码使用，不是网络跳点。",
    "arch.msg.i1": "Mvccpb —— KeyValue / Event",
    "arch.msg.i2": "Etcdserverpb —— 60+ 请求 / 响应",
    "arch.msg.i3": "Authpb — User / Role / Permission",
    "arch.dep.title": "运行依赖",
    "arch.dep.1": "psr/http-client ^1.0  必需",
    "arch.dep.2": "psr/http-factory ^1.0  必需",
    "arch.dep.3": "grpc/grpc  建议（gRPC 传输）",
    "arch.dep.4": "google/protobuf  建议",
    "arch.dep.5": "php >= 8.0",
    "arch.exc.title": "异常体系 —— 全部继承自 RuntimeException，按 retryable 语义分流",
    "arch.exc.root": "EtcdException",
    "arch.exc.root.sub": "retryable 标记",
    "arch.exc.1.sub": "连接超时 / DNS 失败 / 连接拒绝",
    "arch.exc.1.tag": "触发重试",
    "arch.exc.2.sub": "HTTP 401 认证失败",
    "arch.exc.2.tag": "不重试",
    "arch.exc.3.sub": "getOrFail() 未命中",
    "arch.exc.3.tag": "业务自行处理",

    # ---- features
    "feat.footer": "所有方法失败时抛出 Erikwang2013\\Etcd\\Exception 下的类型化异常；HTTP 与 gRPC 两种传输共用同一套子系统 API。",
    "feat.kv.t": "KV 键值存储",
    "feat.kv.s": "etcd 的核心读写，含前缀扫描与原子事务",
    "feat.kv.m1": "put(k, v[, lease, prevKv])",
    "feat.kv.m2": "get(k[, rangeEnd, limit, revision])",
    "feat.kv.m3": "getByPrefix(p) / getOrFail(k)",
    "feat.kv.m4": "delete(k) / deleteByPrefix(p)",
    "feat.kv.m5": "txn(compare, success, failure)",
    "feat.kv.m6": "compact(revision) 压缩历史版本",
    "feat.kv.n": "输出为关联数组；getOrFail() 未命中抛 KeyNotFoundException；txn 为 compare → success / failure 三段式。",
    "feat.watch.t": "Watch 变更监听",
    "feat.watch.s": "基于 HTTP chunked 的长连接事件流",
    "feat.watch.m1": "watch(key, cb[, opts])",
    "feat.watch.m2": "watchPrefix(prefix, cb)",
    "feat.watch.m3": "startRevision / prevKv / progressNotify",
    "feat.watch.m4": "流式分块读取，非阻塞 I/O",
    "feat.watch.m5": "断线自动重连，以 lastRevision 续订",
    "feat.watch.n": "EOF 后按 100ms×n 退避并随机换端点重连，故障转移不丢事件。",
    "feat.lease.t": "Lease 租约",
    "feat.lease.s": "带 TTL 的 key，到期由 etcd 自动清理",
    "feat.lease.m1": "grant(ttl[, id]) → {ID, TTL}",
    "feat.lease.m2": "keepAlive(id) 单次续约",
    "feat.lease.m3": "timeToLive(id[, keys]) 查状态",
    "feat.lease.m4": "list() 列出活跃租约",
    "feat.lease.m5": "revoke(id) 撤销并删除绑定 key",
    "feat.lease.n": "服务注册的标准姿势：grant + put(lease) + 定时 keepAlive 心跳；进程崩溃也无需手工清理。",
    "feat.auth.t": "Auth 认证授权",
    "feat.auth.s": "RBAC：用户 → 角色 → 权限",
    "feat.auth.m1": "enable() / disable() / status()",
    "feat.auth.m2": "user().add / get / list / delete",
    "feat.auth.m3": "user().grantRole / revokeRole",
    "feat.auth.m4": "user().changePassword(name, pass)",
    "feat.auth.m5": "role().add / get / list / delete",
    "feat.auth.m6": "role().grantPermission / revokePermission",
    "feat.auth.n": "权限类型 0=READ 1=WRITE 2=READWRITE；开启认证后客户端必须配置 auth.user / auth.password。",
    "feat.cluster.t": "Cluster 集群管理",
    "feat.cluster.s": "成员增删与 Learner 生命周期",
    "feat.cluster.m1": "memberList() 成员列表",
    "feat.cluster.m2": "memberAdd(peers[, isLearner])",
    "feat.cluster.m3": "memberUpdate(id, peers)",
    "feat.cluster.m4": "memberPromote(id) Learner → Voter",
    "feat.cluster.m5": "memberRemove(id)",
    "feat.cluster.n": "Learner 先以非投票身份加入，再 promote 提升为 Voter，扩容不影响 quorum。",
    "feat.maint.t": "Maintenance 运维",
    "feat.maint.s": "集群状态、告警与备份",
    "feat.maint.m1": "status() → version / dbSize / leader",
    "feat.maint.m2": "alarm([action, alarm, memberID])",
    "feat.maint.m3": "defragment() 碎片整理",
    "feat.maint.m4": "hash([revision]) KV 哈希校验",
    "feat.maint.m5": "snapshot() 返回原始二进制",
    "feat.maint.n": "snapshot() 经 sendRaw() 返回原始二进制，可直接落盘作为备份文件。",

    # ---- lifecycle
    "life.r1.t": "请求生命周期",
    "life.r1.s": "同步调用：从构造到异常语义",
    "life.r1.a.t": "① 构造与配置",
    "life.r1.a.1": "new EtcdClient($config)",
    "life.r1.a.2": "合并默认值，读取 ETCD_* 环境变量",
    "life.r1.a.3": "TransportSelector 按 auto / http / grpc 选定实现",
    "life.r1.b.t": "② 编码请求",
    "life.r1.b.1": "KvClient / WatchClient 组装请求体",
    "life.r1.b.2": "key 与 value 做 base64_encode",
    "life.r1.b.3": "前缀经 prefixToRangeEnd() 转 range_end",
    "life.r1.c.t": "③ 传输与重试",
    "life.r1.c.1": "随机挑端点，PSR-18 客户端发出 /v3/* 请求",
    "life.r1.c.2": "连接层失败：最多重试 retry 次",
    "life.r1.c.3": "100ms×n 线性退避后换端点重试",
    "life.r1.d.t": "④ 响应与异常",
    "life.r1.d.1": "响应 base64 解码为关联数组",
    "life.r1.d.2": "401 → AuthException（不重试）",
    "life.r1.d.3": "4xx / 5xx → EtcdException",
    "life.r1.note": "失败分支：只有 ConnectionException 触发重试；认证与业务错误立即抛出，交由调用方处理。",
    "life.r2.t": "Watch 生命周期",
    "life.r2.s": "长连接：事件流与故障转移",
    "life.r2.a.t": "① 注册监听",
    "life.r2.a.1": "watch(key, cb) / watchPrefix()",
    "life.r2.a.2": "计算 range_end 界定监听区间",
    "life.r2.a.3": "可指定 startRevision / prevKv / progressNotify",
    "life.r2.b.t": "② 建立分块流",
    "life.r2.b.1": "fopen + stream_context 直连 /v3/watch",
    "life.r2.b.2": "stream_set_blocking(false) 非阻塞读",
    "life.r2.b.3": "按行切分 JSON 帧并累积缓冲区",
    "life.r2.c.t": "③ 事件分发",
    "life.r2.c.1": "解析 PUT / DELETE 事件与 header.revision",
    "life.r2.c.2": "记录 lastRevision 作为续订锚点",
    "life.r2.c.3": "回调交给业务处理",
    "life.r2.d.t": "④ 断线续订",
    "life.r2.d.1": "EOF 或连接中断 → 退避后换端点",
    "life.r2.d.2": "start_revision = lastRevision 续订",
    "life.r2.d.3": "故障转移不丢事件",
    "life.r2.note": "回环：④ 失败后回到 ② 重建连接，lastRevision 保证断点续传，换端点实现天然故障转移。",
    "life.r2.loop": "断线重连",
    "life.r3.t": "Lease 生命周期",
    "life.r3.s": "服务注册：TTL 心跳与自动清理",
    "life.r3.a.t": "① 创建租约",
    "life.r3.a.1": "lease()->grant(ttl)",
    "life.r3.a.2": "返回 {ID, TTL}，可指定 ID 复用",
    "life.r3.a.3": "TTL 由 etcd 服务端计时",
    "life.r3.b.t": "② 绑定 key",
    "life.r3.b.1": "put(key, value, ['lease' => $ID])",
    "life.r3.b.2": "同一租约可绑定多个 key",
    "life.r3.b.3": "key 与租约同生共死",
    "life.r3.c.t": "③ 心跳续约",
    "life.r3.c.1": "定时 keepAlive($ID) 刷新 TTL",
    "life.r3.c.2": "timeToLive($ID, true) 可查绑定的 key",
    "life.r3.c.3": "续约成功即服务存活",
    "life.r3.d.t": "④ 到期清理",
    "life.r3.d.1": "TTL 归零或被 revoke()",
    "life.r3.d.2": "etcd 自动删除全部绑定 key",
    "life.r3.d.3": "进程崩溃无需手工清理",
    "life.r3.note": "回环：③ 按 TTL/3 周期重复直至服务下线，等价于分布式健康检查。",
    "life.r3.loop": "周期续约",
    "life.legend.1": "同步请求",
    "life.legend.2": "长连接流",
    "life.legend.3": "租约 / TTL",
}

LANG = "zh"
PROBLEMS: list[str] = []
TIGHT: list[str] = []
TIGHT_RATIO = 0.94


def load_catalog(lang: str) -> dict[str, str]:
    """zh defaults overlaid with catalog/<lang>.json (zh itself needs no file)."""
    data = dict(T)
    path = os.path.join(CATALOG, f"{lang}.json")
    if os.path.exists(path):
        with open(path, encoding="utf-8") as fh:
            over = json.load(fh)
        unknown = set(over) - set(T)
        if unknown:
            PROBLEMS.append(f"[{lang}] catalog has unknown keys: {sorted(unknown)[:6]}")
        data.update({k: v for k, v in over.items() if k in T})
    elif lang != "zh":
        PROBLEMS.append(f"[{lang}] no catalog file at {path}")
    return data


def s(key: str) -> str:
    return LANG_T.get(key, T.get(key, key))


# ------------------------------------------------------------- text metrics
def sw(text: str, size: float, factor: float | None = AF) -> float:
    """Advance width in user units. CJK/kana/hangul are exactly 1em; Latin uses
    the Helvetica table; a float ``factor`` switches to a flat per-character
    model (monospace, where every glyph advances by the same amount)."""
    if factor is not None:
        return size * factor * len(text)
    return size * sum(1.0 if ord(c) > 0x2E80 else _ADV.get(c, DEFAULT_ADV) for c in text)


def esc(text: str) -> str:
    return html.escape(text, quote=True)


def _tokens(text: str):
    toks, cur = [], ""
    for ch in text:
        if ord(ch) > 0x2E80 or ch == " ":
            if cur:
                toks.append(cur)
                cur = ""
            toks.append(ch)
        else:
            cur += ch
    if cur:
        toks.append(cur)
    return toks


def wrap(text: str, max_w: float, size: float, factor: float | None = AF) -> list[str]:
    lines, cur = [], ""
    for tk in _tokens(text):
        if sw(cur + tk, size, factor) <= max_w or not cur.strip():
            if sw(cur + tk, size, factor) > max_w and not cur.strip():
                for ch in tk:                      # single token wider than the box
                    if sw(cur + ch, size, factor) > max_w and cur:
                        lines.append(cur)
                        cur = ""
                    cur += ch
            else:
                cur += tk
        else:
            lines.append(cur.rstrip())
            cur = "" if tk == " " else tk
    if cur.strip():
        lines.append(cur.rstrip())
    return lines or [""]


def fit_text(text: str, max_w: float, size: float, max_lines: int = 1,
             factor: float | None = AF, key: str = "") -> list[str]:
    lines = wrap(text, max_w, size, factor)
    if lines and max_w:
        # The width metric is an estimate; a browser may lay the same string out
        # a few percent wider. Flag anything with less than ~6% headroom so it
        # can be reworded while there is still slack.
        # Only single-line text is at risk: wrapped text that fills a line is
        # just a normal wrap point, whereas a capped one-liner has nowhere to go.
        fill = max(sw(ln, size, factor) for ln in lines) / max_w
        if max_lines == 1 and fill > TIGHT_RATIO:
            TIGHT.append(f"[{LANG}] {key or text[:24]}: {fill:.0%} of its box -> {text!r}")
    if len(lines) > max_lines:
        PROBLEMS.append(
            f"[{LANG}] {key or text[:24]}: needs {len(lines)} lines, box fits {max_lines} "
            f"({size}px, {max_w:.0f}u) -> {text!r}"
        )
    return lines[:max_lines]


def fit(key: str, max_w: float, size: float, max_lines: int = 99, factor: float | None = AF) -> list[str]:
    """Wrap a catalog string and complain if it needs more room than the box has."""
    return fit_text(s(key), max_w, size, max_lines, factor, key)


def fits(key: str, max_w: float, max_lines: int = 1, size: float = 11, factor: float | None = AF) -> str:
    """One-shot guard for text that is drawn on a single line with a known width."""
    return fit(key, max_w, size, max_lines, factor)[0]


# ------------------------------------------------------------- svg primitives
def txt(x, y, text, size=13, fill=MUTED, anchor="start", weight="400", mono=False, opacity=None):
    a = f' font-family="{MONO}"' if mono else ""
    o = f' opacity="{opacity}"' if opacity is not None else ""
    rtl = ""
    if LANG in RTL and anchor == "start":
        # RTL "start" is the right edge: park it at the far end so the run still
        # reads from the box's left padding outwards.
        x = x + sw(text, size, MF if mono else AF)
        rtl = ' direction="rtl"'
    return (f'<text x="{x:.1f}" y="{y}" font-size="{size}" fill="{fill}" text-anchor="{anchor}"'
            f' font-weight="{weight}"{a}{rtl}{o}>{esc(text)}</text>')


def rect(x, y, w, h, fill=PANEL, stroke=BORDER, rx=14, sw_=1.5, opacity=None):
    o = f' opacity="{opacity}"' if opacity is not None else ""
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}"'
            f' stroke="{stroke}" stroke-width="{sw_}"{o}/>')


def circle(cx, cy, r, fill, stroke=None, sw_=2, opacity=None):
    st = f' stroke="{stroke}" stroke-width="{sw_}"' if stroke else ""
    o = f' opacity="{opacity}"' if opacity is not None else ""
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{fill}"{st}{o}/>'


def line(x1, y1, x2, y2, stroke=BORDER, sw_=2, dash=None, marker=None, opacity=None):
    d = f' stroke-dasharray="{dash}"' if dash else ""
    m = f' marker-end="url(#{marker})"' if marker else ""
    o = f' opacity="{opacity}"' if opacity is not None else ""
    return (f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{stroke}"'
            f' stroke-width="{sw_}"{d}{m}{o}/>')


def path(d, fill="none", stroke=BORDER, sw_=2, dash=None, marker=None, cap="round"):
    ds = f' stroke-dasharray="{dash}"' if dash else ""
    m = f' marker-end="url(#{marker})"' if marker else ""
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" stroke-width="{sw_}"'
            f' stroke-linecap="{cap}" stroke-linejoin="round"{ds}{m}/>')


def marker(mid, color):
    return (f'<marker id="{mid}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6.5"'
            f' markerHeight="6.5" orient="auto"><path d="M0,0.6 L10,5 L0,9.4 z" fill="{color}"/></marker>')


def defs(*ms):
    return "<defs>" + "".join(ms) + "</defs>"


def bullets(x, y, keys, max_w, max_lines=3, size=11.5, lh=16, fill=MUTED, dot=None):
    out, cy = [], y
    for k in keys:
        for i, ln in enumerate(fit(k, max_w - (14 if dot else 0), size, max_lines)):
            if dot and i == 0:
                out.append(circle(x + 3, cy - 4, 2.5, dot))
            out.append(txt(x + (14 if dot else 0), cy, ln, size=size, fill=fill))
            cy += lh
    return "".join(out)


def wrap_svg(w, h, body, title, desc):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}"'
            f' font-family="{FONT}" role="img" aria-labelledby="ttl dsc">\n'
            f'  <title id="ttl">{esc(title)}</title>\n  <desc id="dsc">{esc(desc)}</desc>\n'
            f'  {body}\n</svg>\n')


def header(w, title_key, sub_key):
    return (rect(0, 0, w, 10**4, fill=BG, stroke="none", rx=0) +
            txt(40, 56, fits(title_key, w - 80, size=27), size=27, fill=TEXT, weight="700") +
            txt(40, 86, fits(sub_key, w - 80, size=14), size=14, fill=MUTED))


# ================================================================ architecture
def architecture():
    W = 1200
    MX, MW = 40, 820
    SX, SW = 890, 270
    CX = MX + MW // 2
    GAP = 34
    p, y = [], 130

    def band(yy, num, key, color):
        label = f"{num} {s(key)}"
        p.append(txt(MX, yy, fit_text(label, MW, 15, 1, AF, key)[0], size=15, fill=color, weight="700"))

    def arrow(yy, note_key=None, color=BLUE, x=CX):
        p.append(line(x, yy, x, yy + GAP - 10, stroke=color, sw_=2, marker="ah", opacity="0.75"))
        if note_key:
            p.append(txt(x + 12, yy + GAP // 2 + 4, s(note_key), size=11.5, fill=DIM))

    band(y, "①", "arch.b1", SKY)
    y += 14
    for i, (t, sk) in enumerate([("Laravel", "arch.app.laravel.sub"), ("Hyperf", "arch.app.hyperf.sub"),
                                 ("ThinkPHP", "arch.app.thinkphp.sub"), ("Webman", "arch.app.webman.sub")]):
        x = MX + i * 210
        p.append(rect(x, y, 190, 80, rx=12))
        p.append(txt(x + 95, y + 34, t, size=16, fill=TEXT, weight="700", anchor="middle"))
        p.append("".join(txt(x + 95, y + 58 + j * 14, ln, size=11, fill=MUTED, anchor="middle")
                         for j, ln in enumerate(fit(sk, 166, 11, 2))))
    y += 80
    arrow(y, "arch.b1.note")
    y += GAP

    band(y, "②", "arch.b2", BLUE)
    y += 14
    p.append(rect(MX, y, MW, 120, fill=PANEL2, stroke=BLUE, rx=16))
    p.append(txt(CX, y + 32, fits("arch.facade", MW - 60, 1, 15), size=15, fill=TEXT, weight="700", anchor="middle"))
    cw = (MW - 40 - 5 * 12) // 6
    for i, sub in enumerate(["kv", "watch", "lease", "auth", "cluster", "maintenance"]):
        x = MX + 20 + i * (cw + 12)
        p.append(rect(x, y + 50, cw, 44, fill=BG, rx=10, sw_=1))
        p.append(txt(x + cw / 2, y + 78, sub, size=13, fill=BLUE, anchor="middle", mono=True))
    y += 120
    arrow(y, "arch.b2.note")
    y += GAP

    band(y, "③", "arch.b3", TEAL)
    y += 14
    subsys = [("KvClient", "arch.kv.sub"), ("WatchClient", "arch.watch.sub"), ("LeaseClient", "arch.lease.sub"),
              ("AuthClient · UserClient · RoleClient", "arch.auth.sub"), ("ClusterClient", "arch.cluster.sub"),
              ("MaintenanceClient", "arch.maint.sub")]
    cw3 = (MW - 40) // 3
    for i, (t, sk) in enumerate(subsys):
        x = MX + (i % 3) * (cw3 + 20)
        yy = y + (i // 3) * 98
        p.append(rect(x, yy, cw3, 82, rx=12))
        p.append("".join(txt(x + 18, yy + 26 + j * 15, ln, size=12.5, fill=TEXT, weight="700")
                         for j, ln in enumerate(fit(t, cw3 - 36, 12.5, 2))))
        p.append("".join(txt(x + 18, yy + 58 + j * 15, ln, size=11, fill=MUTED)
                         for j, ln in enumerate(fit(sk, cw3 - 36, 11, 2))))
    y += 82 + 98
    arrow(y, "arch.b3.note")
    y += GAP

    band(y, "④", "arch.b4", AMBER)
    y += 14
    sel_lines = fit("arch.sel.body", MW - 40, 11, 2)
    sel_h = 26 + 21 * len(sel_lines) + 10
    p.append(rect(MX, y, MW, sel_h, rx=12))
    p.append(txt(MX + 20, y + 26, "TransportSelector", size=14, fill=TEXT, weight="700"))
    p.append("".join(txt(MX + 20, y + 46 + i * 17, ln, size=11, fill=MUTED) for i, ln in enumerate(sel_lines)))
    ty = y + sel_h + 26
    for i, (t, k1, k2, kb, col, badge_k) in enumerate([
        ("HttpTransport", "arch.http.sub1", "arch.http.sub2", "arch.http.badge", TEAL, "arch.http.badge"),
        ("GrpcTransport", "arch.grpc.sub1", "arch.grpc.sub2", "arch.grpc.badge", AMBER, "arch.grpc.badge"),
    ]):
        x = MX + i * 420
        p.append(rect(x, ty, 400, 96, stroke=col, rx=12))
        p.append(txt(x + 18, ty + 30, t, size=14, fill=TEXT, weight="700", mono=True))
        p.append("".join(txt(x + 18, ty + 54 + j * 15, ln, size=11, fill=MUTED)
                         for j, ln in enumerate(fit(k1, 366, 11, 2))))
        p.append(txt(x + 18, ty + 74, fits(k2, 366, 1, 10.5, MF), size=10.5, fill=DIM, mono=True))
        badge = s(badge_k)
        bw = 10 + sw(badge, 11)
        p.append(rect(x + 400 - bw - 16, ty + 14, bw, 22, fill=BG, stroke=col, rx=11, sw_=1))
        p.append(txt(x + 400 - bw / 2 - 16, ty + 29, badge, size=11, fill=col, anchor="middle"))
    for i in range(2):
        cx0 = MX + i * 420 + 200
        p.append(path(f"M {CX} {y + sel_h} V {ty - 14} H {cx0} V {ty - 2}", stroke=BORDER, sw_=1.5, marker="ah"))
    y = ty + 96
    arrow(y, "arch.b4.note")
    y += GAP

    band(y, "⑤", "arch.b5", ORANGE)
    y += 14
    p.append(rect(MX, y, MW, 104, fill=PANEL2, stroke=ORANGE, rx=16))
    for i, (tk, sk) in enumerate([("arch.srv1.t", "arch.srv1.sub"), ("arch.srv2.t", "arch.srv2.sub"),
                                  ("arch.srv3.t", "arch.srv3.sub")]):
        x = MX + 20 + i * 262
        p.append(rect(x, y + 18, 242, 68, fill=BG, rx=10, sw_=1))
        p.append(txt(x + 121, y + 44, fits(tk, 226, 1, 12, MF), size=12, fill=TEXT, weight="700", anchor="middle", mono=True))
        p.append(txt(x + 121, y + 66, fits(sk, 222, 1, 11), size=11, fill=MUTED, anchor="middle"))
    y += 104

    # right column: cross-cutting concerns
    sb1, cy = [], 130 + 72
    for tk, bk in [("arch.aside.1.t", "arch.aside.1.b"), ("arch.aside.2.t", "arch.aside.2.b"),
                   ("arch.aside.3.t", "arch.aside.3.b"), ("arch.aside.4.t", "arch.aside.4.b"),
                   ("arch.aside.5.t", "arch.aside.5.b")]:
        tlines = fit(tk, SW - 54, 12.5, 2)
        sb1.append(circle(SX + 22, cy - 4, 3.5, TEAL))
        sb1.append("".join(txt(SX + 34, cy + j * 15, ln, size=12.5, fill=TEXT, weight="700")
                           for j, ln in enumerate(tlines)))
        cy += 19 + 15 * (len(tlines) - 1)
        for ln in fit(bk, SW - 54, 11, 4):
            sb1.append(txt(SX + 34, cy, ln, size=11, fill=MUTED))
            cy += 15.5
        cy += 11
    h1 = cy - 130 + 6

    # footer: exception tree
    fy = y + GAP + 8
    fh = 246
    pb = [rect(MX, fy, W - 2 * MX, fh, fill=PANEL2, rx=16)]
    pb.append(txt(MX + 20, fy + 32, s("arch.exc.title"), size=15, fill=TEXT, weight="700"))
    rc = fy + 146
    pb.append(rect(MX + 20, rc - 32, 250, 64, fill=BG, stroke=RED, rx=12))
    pb.append(txt(MX + 145, rc - 4, s("arch.exc.root"), size=13, fill=TEXT, weight="700", anchor="middle", mono=True))
    pb.append(txt(MX + 145, rc + 18, s("arch.exc.root.sub"), size=10.5, fill=MUTED, anchor="middle"))
    trunk = MX + 296
    rows = rc - 58, rc, rc + 58
    kids = [("ConnectionException", "arch.exc.1.sub", "arch.exc.1.tag", TEAL),
            ("AuthException", "arch.exc.2.sub", "arch.exc.2.tag", AMBER),
            ("KeyNotFoundException", "arch.exc.3.sub", "arch.exc.3.tag", DIM)]
    for i, (t, sk, tagk, col) in enumerate(kids):
        x, rcy = 360, rows[i]
        pb.append(rect(x, rcy - 22, W - 40 - x, 44, fill=BG, stroke=col, rx=12))
        pb.append(txt(x + 18, rcy + 5, t, size=12, fill=TEXT, weight="700", mono=True))
        tail = f"—— {s(sk)} → {s(tagk)}"
        avail = (W - 40 - x) - 48 - sw(t, 12, MF)
        pb.append(txt(x + 18 + sw(t, 12, MF) + 18, rcy + 5, fit_text(tail, avail, 11)[0], size=11, fill=MUTED))
    pb.append(line(MX + 270, rc, trunk, rc, stroke=BORDER, sw_=1.5))
    pb.append(line(trunk, rows[0], trunk, rows[2], stroke=BORDER, sw_=1.5))
    for rcy in rows:
        pb.append(line(trunk, rcy, 358, rcy, stroke=BORDER, sw_=1.5, marker="ah"))

    H = fy + fh + 44

    # right column: message layer + dependencies
    p2y = 130 + h1 + 20
    p2h = y - p2y
    pb.append(rect(SX, p2y, SW, p2h, fill=PANEL2, rx=16))
    pb.append(txt(SX + 20, p2y + 32, s("arch.msg.title"), size=13, fill=TEXT, weight="700"))
    pb.append(line(SX + 20, p2y + 46, SX + SW - 20, p2y + 46, stroke=BORDER, sw_=1))
    cy = p2y + 70
    for ln in fit("arch.msg.body", SW - 40, 11, 6):
        pb.append(txt(SX + 20, cy, ln, size=11, fill=MUTED))
        cy += 15.5
    cy += 10
    for k in ("arch.msg.i1", "arch.msg.i2", "arch.msg.i3"):
        pb.append(circle(SX + 24, cy - 4, 2.5, PURPLE))
        pb.append(txt(SX + 36, cy, fits(k, SW - 56, 1, 10, MF), size=10, fill=BODY, mono=True))
        cy += 19
    cy += 14
    pb.append(line(SX + 20, cy - 12, SX + SW - 20, cy - 12, stroke=BORDER, sw_=1))
    pb.append(txt(SX + 20, cy + 10, s("arch.dep.title"), size=13, fill=TEXT, weight="700"))
    cy += 34
    for k in ("arch.dep.1", "arch.dep.2", "arch.dep.3", "arch.dep.4", "arch.dep.5"):
        pb.append(txt(SX + 20, cy, fits(k, SW - 40, 1, 11, MF), size=11, fill=MUTED, mono=True))
        cy += 18
    if cy - 6 > p2y + p2h:
        PROBLEMS.append(
            f"[{LANG}] right column: message/dependency panel needs {cy - 6 - p2y:.0f}u, "
            f"only {p2h:.0f}u left under the cross-cutting panel"
        )

    body = (header(W, "common.arch_title", "common.arch_sub") + defs(marker("ah", BLUE))
            + rect(SX, 130, SW, h1, fill=PANEL2, rx=16) + "".join(sb1) + "".join(pb) + "".join(p))
    return wrap_svg(W, H, body, s("common.arch_alt"), s("common.arch_desc"))


# ================================================================ features
def features():
    W, H = 1200, 880
    cw, ch, gx, gy = 357, 330, 24, 24
    p = [header(W, "common.feat_title", "common.feat_sub")]
    cards = [
        (BLUE, "K", "feat.kv", 6), (TEAL, "W", "feat.watch", 5), (AMBER, "L", "feat.lease", 5),
        (PURPLE, "A", "feat.auth", 6), (SKY, "C", "feat.cluster", 5), (ORANGE, "M", "feat.maint", 5),
    ]
    for i, (col, glyph, base, nm) in enumerate(cards):
        x = 40 + (i % 3) * (cw + gx)
        y = 130 + (i // 3) * (ch + gy)
        p.append(rect(x, y, cw, ch, rx=16))
        p.append(rect(x + 26, y + 26, 44, 44, fill=BG, stroke=col, rx=13))
        p.append(txt(x + 48, y + 55, glyph, size=20, fill=col, anchor="middle", weight="700", mono=True))
        for j, ln in enumerate(fit(f"{base}.t", cw - 112, 17, 1)):
            p.append(txt(x + 86, y + 46 + j * 18, ln, size=17, fill=TEXT, weight="700"))
        for j, ln in enumerate(fit(f"{base}.s", cw - 112, 11.5, 2)):
            p.append(txt(x + 86, y + 68 + j * 15, ln, size=11.5, fill=MUTED))
        p.append(line(x + 26, y + 92, x + cw - 26, y + 92, stroke=BORDER, sw_=1))
        for j in range(nm):
            key = f"{base}.m{j + 1}"
            for k, ln in enumerate(fit(key, cw - 66, 10.5, 1, MF)):
                cy0 = y + 118 + (j + k) * 25
                p.append(circle(x + 29, cy0 - 4, 2.5, col))
                p.append(txt(x + 40, cy0, ln, size=10.5, fill=BODY, mono=True))
        p.append(line(x + 26, y + 258, x + cw - 26, y + 258, stroke=BORDER, sw_=1))
        for k, ln in enumerate(fit(f"{base}.n", cw - 56, 11, 3)):
            p.append(txt(x + 26, y + 282 + k * 15.5, ln, size=11, fill=MUTED))

    p.append(txt(40, H - 34, fits("feat.footer", W - 80, 1, 12), size=12, fill=DIM))
    return wrap_svg(W, H, "".join(p), s("common.feat_alt"), s("common.feat_desc"))


# ================================================================ lifecycle
def lifecycle():
    W = 1200
    GAP = 18
    SW_ = (W - 80 - 3 * GAP) // 4
    BW = 152
    p = [header(W, "common.life_title", "common.life_sub")]
    mk = [marker("ah", BLUE), marker("ah2", TEAL), marker("ah3", AMBER)]
    lanes = [
        (BLUE, "life.r1", ["a", "b", "c", "d"], "life.r1.note", None),
        (TEAL, "life.r2", ["a", "b", "c", "d"], "life.r2.note", (3, 1, "life.r2.loop", "ah2")),
        (AMBER, "life.r3", ["a", "b", "c", "d"], "life.r3.note", (2, 2, "life.r3.loop", "ah3")),
    ]
    y = 118
    for col, base, stages, note_key, loop in lanes:
        p.append(rect(40, y, W - 80, 36, fill=PANEL2, rx=10, sw_=1))
        p.append(circle(64, y + 18, 6, col))
        p.append(txt(80, y + 23, s(f"{base}.t"), size=15, fill=TEXT, weight="700"))
        p.append(txt(80 + sw(s(f"{base}.t"), 15) + 18, y + 23,
                     fits(f"{base}.s", W - 140 - sw(s(f"{base}.t"), 15), 1, 11.5), size=11.5, fill=MUTED))
        sy = y + 50
        for i, st in enumerate(stages):
            x = 40 + i * (SW_ + GAP)
            p.append(rect(x, sy, SW_, BW, rx=14))
            p.append(rect(x, sy, SW_, 4, fill=col, rx=2, stroke="none"))
            p.append(txt(x + 18, sy + 30, s(f"{base}.{st}.t"), size=14, fill=TEXT, weight="700"))
            p.append(bullets(x + 18, sy + 56, [f"{base}.{st}.{n}" for n in (1, 2, 3)], SW_ - 36, 3, dot=col))
            if i < len(stages) - 1:
                p.append(line(x + SW_ + 2, sy + BW / 2, x + SW_ + GAP - 6, sy + BW / 2,
                              stroke=col, sw_=2, marker="ah", opacity="0.7"))
        if loop:
            a, b, label_key, mid = loop
            xa = 40 + a * (SW_ + GAP) + SW_ / 2
            xb = 40 + b * (SW_ + GAP) + SW_ / 2
            ly = sy + BW + 30
            if a == b:
                p.append(path(f"M {xb+40} {sy+BW} V {ly} H {xb-40} V {sy+BW+4}",
                              stroke=col, sw_=1.5, dash="6 5", marker=mid, cap="butt"))
            else:
                p.append(path(f"M {xa} {sy+BW} V {ly} H {xb} V {sy+BW+4}",
                              stroke=col, sw_=1.5, dash="6 5", marker=mid, cap="butt"))
            p.append(txt((xa + xb) / 2, ly + 16, fits(label_key, 220, 1, 11), size=11, fill=col, anchor="middle"))
        p.append(txt(40, sy + BW + 62, fits(note_key, W - 80, 1, 11.5), size=11.5, fill=DIM))
        y = sy + BW + 92

    p.append(line(40, y - 26, W - 40, y - 26, stroke=BORDER, sw_=1))
    lx = 40
    for k, c in [("life.legend.1", BLUE), ("life.legend.2", TEAL), ("life.legend.3", AMBER)]:
        p.append(circle(lx + 5, y - 4, 5, c))
        p.append(txt(lx + 18, y, s(k), size=12, fill=MUTED))
        lx += 30 + sw(s(k), 12) + 26
    H = y + 36
    return wrap_svg(W, H, defs(*mk) + "".join(p), s("common.life_alt"), s("common.life_desc"))


def build(lang: str) -> dict[str, str]:
    """Render every diagram for one language. Writing is the caller's job, so a
    language with problems never lands a truncated SVG on disk."""
    global LANG, LANG_T
    LANG = lang
    LANG_T = load_catalog(lang)
    return {name: fn() for name, fn in
            (("architecture", architecture), ("features", features), ("lifecycle", lifecycle))}


def emit(lang: str, svgs: dict[str, str]) -> None:
    out = os.path.join(OUTDIR, lang)
    os.makedirs(out, exist_ok=True)
    for name, svg in svgs.items():
        with open(os.path.join(out, f"{name}.svg"), "w", encoding="utf-8") as fh:
            fh.write(svg)
        if lang == "zh":
            # zh is also the set the root README embeds, straight from docs/
            with open(os.path.join(ROOT, "docs", f"{name}.svg"), "w", encoding="utf-8") as fh:
                fh.write(svg)


def main(argv: list[str]) -> int:
    argv = argv[1:]
    if not argv:
        print(__doc__)
        return 2
    check = "--check" in argv
    argv = [a for a in argv if a != "--check"]
    if argv and argv[0] == "--dump":
        print(json.dumps(T, ensure_ascii=False, indent=2, sort_keys=True))
        return 0
    if argv and argv[0] == "--all":
        langs = sorted(f[:-5] for f in os.listdir(CATALOG) if f.endswith(".json"))
    else:
        langs = argv
    for lang in langs:
        before = len(PROBLEMS)
        svgs = build(lang)
        if len(PROBLEMS) > before:
            print(f"skipped {lang} — not writing truncated output", file=sys.stderr)
            continue
        if not check:
            emit(lang, svgs)
            print(f"built {lang}")
        else:
            print(f"checked {lang}")
    if PROBLEMS:
        print(f"\n{len(PROBLEMS)} problem(s):", file=sys.stderr)
        for prob in PROBLEMS:
            print("  " + prob, file=sys.stderr)
        return 1
    if TIGHT:
        print(f"\n{len(TIGHT)} string(s) within {1 - TIGHT_RATIO:.0%} of their box (no failure):")
        for t in TIGHT:
            print("  ! " + t)
    print(f"\nOK — {len(langs)} language(s), all strings fit their boxes.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
