# 客户一次性配置

将整个 procurement-publish 文件夹放入客户的 `~/.codex/skills/`。
平台管理员在“招采信息 → 采集端管理”创建采集端，绑定有招采权限的人员并设置有效期。令牌只显示一次，丢失后撤销重建。

由客户将令牌安全注入启动 Codex 进程的环境变量 `PROCUREMENT_MCP_TOKEN`。不要在聊天、命令参数、源码或 Skill 中填写明文令牌。桌面 Codex 必须实际继承该环境变量；仅在另一个终端设置变量不代表桌面进程已继承。

用客户的实际 HTTPS 平台地址替换示例地址：

```sh
codex mcp add procurement --url https://YOUR_PLATFORM_HOST/mcp/procurement --bearer-token-env-var PROCUREMENT_MCP_TOKEN
```

`YOUR_PLATFORM_HOST` 是连接模板，必须由管理员提供，不能猜测地址。重新打开 Codex 后确认三个工具可用。可先要求“校验这个招采 JSON，不发布”，验证连接与权限，再执行真实发布。

令牌仅有提交和读取本采集端批次权限。后台撤销即时生效。该 Skill 不包含服务端；必须先部署对应后端。
