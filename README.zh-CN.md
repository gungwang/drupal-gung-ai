# Drupal Gung AI

AI 驱动的 Drupal 开发和自动化工具套件

## 简介

这个模块套件提供了智能化的自动化工具，帮助你更高效地进行 Drupal 开发、内容管理和网站维护。所有工具都是在 AI 辅助下开发的，能够安全、高效地处理复杂的 Drupal 操作。

## 子模块

### Clean Contents (`clean_contents/`)

基于 Drush 的内容清理和数据清洗工具，专为开发和测试环境设计。

**主要功能：**
- 删除未发布的内容
- 清理孤立的内容（段落、媒体、文件等）
- 清理旧版本历史记录
- 修复失效的 URL 别名和重定向
- 将用户批量重命名为测试账号

**详细文档：** 查看 `clean_contents/README.zh-CN.md`

## 安装方法

启用父模块和你需要的子模块：

```bash
ddev drush en drupal-gung-ai
ddev drush en clean_contents
```

或者直接启用子模块（它们可以独立工作）：

```bash
ddev drush en clean_contents
```

## 开发说明

这个模块套件是在 AI 助手（Claude/GitHub Copilot）的帮助下开发的，旨在加速 Drupal 开发并提供经过实战检验的自动化工具。

### 添加新的子模块

1. 在 `drupal-gung-ai/` 下创建新目录
2. 遵循 Drupal 模块命名规范
3. 添加详细的文档
4. 为破坏性操作提供预览模式（dry-run）
5. 实现完善的错误处理和日志记录

## 最佳实践

- 运行破坏性操作前务必备份数据库
- 先用 `--dry-run` 参数预览要执行的操作
- 先在开发环境测试，确认无误后再用于正式环境
- 操作完成后检查 watchdog 日志
- 遵循 Drupal 代码规范

## 技术支持

- 查看各个子模块的 README 文件了解详细文档
- 查看 watchdog 日志：`drush wd-show`
- 参考 `ai/` 目录中的规格说明文档
- 查看详细规范：`SPEC-clean-contents-module.zh-CN.md`
- 查看实现记录：`SESSION-clean-contents-implementation.zh-CN.md`

## 授权协议

GPL-2.0-or-later
