# Clean Contents 模块

基于 Drush 的内容清理和数据清洗工具，专为 Drupal 开发和测试环境设计。

## ⚠️ 重要警告

**这个模块包含破坏性操作！**

- 运行任何命令前务必备份数据库
- 先用 `--dry-run` 参数测试命令
- 千万不要在生产环境运行
- 命令执行后无法撤销

## 功能说明

这个模块提供了以下 Drush 命令：
1. 删除所有未发布的内容（节点、段落、媒体、自定义实体）
2. 删除孤立的内容（没有被任何父级引用的内容）
3. 删除旧版本历史，只保留最新版本
4. 删除失效的 URL 别名和重定向
5. 将用户批量重命名为测试账号（test1, test2, test3...）

## 安装方法

1. 将模块放在 `web/modules/custom/clean_contents/`
2. 启用模块：
   ```bash
   ddev drush en clean_contents
   ```

## 命令详解

### 1. 删除未发布内容

```bash
drush clean-contents:delete-unpublished [--dry-run] [--entity-type=TYPE]
drush cc:du  # 简短别名
```

**做什么用：**
- 删除未发布的节点、段落、媒体、区块
- 删除未发布的自定义实体（marketo_form、crc_asset、ul_alert、ul_legal_hold）
- 级联删除：删除节点时会自动删除它引用的段落

**参数说明：**
- `--dry-run` - 只预览会删除什么，不实际删除
- `--entity-type=TYPE` - 只删除指定类型的内容（node、paragraph、media 等）

**使用示例：**
```bash
# 预览会删除哪些未发布内容
ddev drush cc:du --dry-run

# 删除所有未发布内容
ddev drush cc:du

# 只删除未发布的节点
ddev drush cc:du --entity-type=node
```

### 2. 删除孤立内容

```bash
drush clean-contents:delete-orphans [--dry-run] [--entity-type=TYPE] [--include-terms]
drush cc:do  # 简短别名
```

**做什么用：**
- 检测孤立的段落（没有被任何节点或段落引用）
- 检测孤立的媒体（没有被节点、段落或区块引用）
- 检测孤立的文件（没有被媒体或文件字段引用）
- 检测孤立的自定义实体（marketo_form、crc_asset 等）

**参数说明：**
- `--dry-run` - 只预览会删除什么，不实际删除
- `--entity-type=TYPE` - 只删除指定类型的孤立内容
- `--include-terms` - 同时清理孤立的分类术语（需要手动开启）

**使用示例：**
```bash
# 预览会删除哪些孤立内容
ddev drush cc:do --dry-run

# 删除所有孤立内容
ddev drush cc:do

# 只删除孤立的段落
ddev drush cc:do --entity-type=paragraph

# 同时清理分类术语
ddev drush cc:do --include-terms
```

### 3. 删除旧版本历史

```bash
drush clean-contents:delete-old-revisions [--dry-run] [--entity-type=TYPE]
drush cc:dr  # 简短别名
```

**做什么用：**
- 删除所有内容的历史版本
- 每个内容只保留最新的当前版本
- 目标：节点、段落、媒体（如果启用了版本）和自定义实体（marketo_form、ul_alert、ul_legal_hold）
- 显著减小数据库大小
- 提高与版本相关的查询性能

**重要提示：**
- **所有版本历史将永久丢失**
- 这是不可逆的破坏性操作
- 只会保留当前发布/草稿版本
- 适合不需要版本历史的开发/测试环境

**参数说明：**
- `--dry-run` - 预览会删除多少个旧版本
- `--entity-type=TYPE` - 只删除指定类型的旧版本（node、paragraph、media 等）

**使用示例：**
```bash
# 预览会删除哪些旧版本
ddev drush cc:dr --dry-run

# 删除所有实体类型的旧版本
ddev drush cc:dr

# 只删除节点的旧版本
ddev drush cc:dr --entity-type=node

# 清理前先查看节点有多少版本
ddev drush sqlq "SELECT entity_id, COUNT(*) as rev_count FROM node_revision GROUP BY entity_id HAVING rev_count > 1"
```

### 4. 删除失效的 URL 别名和重定向

```bash
drush clean-contents:delete-broken-paths [--dry-run]
drush cc:dbp  # 简短别名
```

**做什么用：**
- 删除指向已删除内容的 URL 别名
- 删除指向不存在路径的重定向（如果启用了 redirect 模块）
- 删除无用的数据库记录，提升网站性能
- 消除失效别名导致的 404 错误

**重要提示：**
- 只删除指向已删除内容的别名/重定向
- 不影响有效的别名/重定向
- 适合批量删除内容后使用
- redirect 模块是可选的，没有也能运行

**参数说明：**
- `--dry-run` - 预览会删除多少个失效路径

**使用示例：**
```bash
# 预览失效的别名和重定向
ddev drush cc:dbp --dry-run

# 删除所有失效的 URL 别名和重定向
ddev drush cc:dbp

# 通过 SQL 查看失效别名
ddev drush sqlq "SELECT path, alias FROM path_alias WHERE path LIKE '/node/%'"

# 统计别名总数
ddev drush sqlq "SELECT COUNT(*) FROM path_alias"

# 检查 redirect 模块是否启用
ddev drush pm:list --filter=redirect
```

### 5. 重命名用户

```bash
drush clean-contents:rename-users [--dry-run] [--password=PASS]
drush cc:ru  # 简短别名
```

**做什么用：**
- 将用户批量重命名为 test1, test2, test3...
- 将邮箱改为 test1@example.com, test2@example.com 等
- 重置密码为可配置的值（默认：Test1234!）
- **保留以下用户不变：**
  - UID 0（匿名用户）
  - UID 1（管理员）
  - 邮箱包含 "gung" 的用户（不区分大小写）

**参数说明：**
- `--dry-run` - 预览会重命名哪些用户
- `--password=PASS` - 为重命名的用户设置自定义密码（默认：Test1234!）

**使用示例：**
```bash
# 预览用户重命名
ddev drush cc:ru --dry-run

# 使用默认密码重命名用户
ddev drush cc:ru

# 使用自定义密码重命名用户
ddev drush cc:ru --password="MyTestPass123"
```

### 6. 一键清理全部

```bash
drush clean-contents:cleanup-all [--dry-run] [--skip-unpublished] [--skip-orphans] [--skip-revisions] [--skip-broken-paths] [--skip-users]
drush cc:all  # 简短别名
```

**做什么用：**
- 按顺序运行所有清理操作
- 完整的环境数据清洗

**参数说明：**
- `--dry-run` - 预览所有操作，不实际执行
- `--skip-unpublished` - 跳过删除未发布内容
- `--skip-orphans` - 跳过删除孤立内容
- `--skip-revisions` - 跳过删除旧版本
- `--skip-broken-paths` - 跳过删除失效路径
- `--skip-users` - 跳过用户重命名

**使用示例：**
```bash
# 预览完整清理流程
ddev drush cc:all --dry-run

# 执行完整清理
ddev drush cc:all

# 清理但不重命名用户
ddev drush cc:all --skip-users

# 只删除未发布内容和旧版本
ddev drush cc:all --skip-orphans --skip-broken-paths --skip-users

# 跳过失效路径清理
ddev drush cc:all --skip-broken-paths
```

## 孤立内容检测原理

### 段落（Paragraphs）
1. 从数据库查询所有段落 ID
2. 找到所有引用段落的 entity_reference_revisions 字段
3. 查询这些字段获取所有被引用的段落 ID
4. 识别孤立项：不在被引用列表中的段落

### 媒体（Media）
1. 查询所有媒体 ID
2. 找到所有引用媒体的 entity_reference 字段
3. 查询这些字段获取所有被引用的媒体 ID
4. 识别孤立项：不在被引用列表中的媒体

### 文件（Files）
1. 查询 file_usage 表获取文件使用情况
2. 识别使用次数为零的文件
3. 这些文件就是待删除的候选

### 自定义实体
- 采用与媒体相同的模式：找到所有引用字段，与现有实体对比

## 支持的实体类型

**核心实体类型：**
- `node` - 所有内容类型
- `paragraph` - 所有段落类型
- `media` - 所有媒体类型
- `block_content` - 自定义区块
- `taxonomy_term` - 所有分类词汇表（需用 --include-terms 开启）
- `file` - 受管理的文件

**自定义内容实体类型：**
- `marketo_form` - Marketo 表单实体（ul_marketo 模块）
- `crc_asset` - CRC 资产实体（ul_crc_asset 模块）
- `ul_alert` - 提醒实体（ul_alerts 模块）
- `ul_legal_hold` - 法律保留实体（ul_legal_hold 模块）

## 安全特性

1. **预览模式（Dry-run）** - 所有命令都支持 --dry-run 预览操作
2. **确认提示** - 破坏性操作前会要求交互式确认
3. **批量处理** - 每批处理 50 个实体，防止内存耗尽
4. **错误处理** - 单个实体删除失败不会影响整体流程
5. **日志记录** - 所有操作记录到 watchdog（clean_contents 频道）
6. **精确删除** - 可以限定只操作特定实体类型

## 性能表现

- 批量处理：每批 50 个实体（可在 service 中配置）
- 优化的数据库查询，用于孤立内容检测
- 可以处理 10,000+ 个实体而不超时
- 长时间操作会显示进度提示

## 常见问题

**"Entity type does not exist"（实体类型不存在）错误**
- 某些自定义实体类型可能没有启用
- 模块会自动跳过不存在的实体类型

**内存错误**
- 可以在 ContentCleanupService::$batchSize 中调整批量大小
- 尝试用 --entity-type 参数限定操作范围

**权限错误**
- 以有足够权限的用户运行命令
- 命令使用 accessCheck(FALSE) 绕过访问控制

**性能慢**
- 大数据集需要较长处理时间
- 孤立内容检测涉及多个数据库查询
- 考虑用 --entity-type 一次只处理一种类型

## 最佳实践

1. **务必先备份** - 使用 `ddev snapshot` 或导出数据库
2. **先预览再执行** - 实际删除前务必先用 --dry-run 看一下
3. **确认结果** - 查看预览输出中的数量和实体类型
4. **按顺序执行** - 如果要运行所有操作，使用 cleanup-all 命令
5. **监控日志** - 操作后检查 watchdog 日志
6. **分步测试** - 先从一种实体类型开始，确认无误再处理全部

## 验证结果

运行清理操作后验证：

```bash
# 统计未发布的节点
ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE status = 0"

# 统计孤立的段落
ddev drush cc:do --dry-run --entity-type=paragraph

# 列出用户
ddev drush sqlq "SELECT uid, name, mail FROM users_field_data WHERE uid > 1"

# 查看 watchdog 日志
ddev drush wd-show --type=clean_contents
```

## 开发说明

**测试：**
- 规格说明中记录了手动测试步骤
- 创建测试内容并验证清理效果
- 验证已发布内容和保留用户不受影响

**代码规范：**
```bash
ddev composer code-sniff
```

## 技术支持

遇到问题或有疑问：
- 查看 watchdog 日志：`drush wd-show --type=clean_contents`
- 查看本 README 和命令帮助：`drush cc:du --help`
- 参考 ai/SPEC-clean-contents-module.md 规格说明文档

## 授权协议

GPL-2.0-or-later
