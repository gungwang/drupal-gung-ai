# 规范说明：clean_contents 自定义 Drupal 模块（简体中文）

## 概述

**模块名称：** `clean_contents`
**用途：** 这是一个专门为开发/测试环境准备的内容清理工具，可以通过 Drush 命令一键清理内容。
**位置：** `web/modules/custom/clean_contents/`

主要功能：
1. 删除所有未发布的内容（文章、段落、媒体、定制内容等）
2. 删除没有被任何内容引用的“孤儿”内容
3. 删除内容的历史版本，只保留最新的
4. 删除指向不存在内容的别名和重定向
5. 把用户重命名为 test1、test2、test3...（邮箱带“gung”的除外）

## 详细说明

### 1. 删除未发布内容
- 支持所有内容类型（文章、活动、新闻、知识库、资源、产品、工具、培训、页面、落地页、主页、中心页、地点、人物等）
- 支持所有段落类型、媒体类型、自定义内容类型和自定义区块
- 删除时会自动级联，比如删除文章会顺带删除它引用的段落
- 删除前会显示数量统计
- 支持“演练模式”（dry-run），只看不删
- 支持大批量数据分批处理

**命令示例：**
```
drush clean-contents:delete-unpublished [--dry-run] [--entity-type=类型]
别名：cc:du
```

### 2. 删除“孤儿”内容
- 找出没有被任何内容引用的段落、媒体、文件、自定义内容、分类、区块等
- 分类默认不删（有些分类本来就独立存在）
- 支持“演练模式”

**命令示例：**
```
drush clean-contents:delete-orphans [--dry-run] [--entity-type=类型] [--include-terms]
别名：cc:do
```

### 3. 用户重命名
- 把所有用户批量改成 test1、test2、test3...（邮箱带“gung”的不动）
- 用户名、邮箱都会改，密码可选重置
- 不会动匿名用户（UID 0）和管理员（UID 1）
- 支持“演练模式”

**命令示例：**
```
drush clean-contents:rename-users [--dry-run] [--password=密码]
别名：cc:ru
```

### 4. 删除历史版本
- 删除所有内容的历史版本，只保留最新的
- 支持文章、段落、媒体、自定义内容等
- 支持“演练模式”
- 批量处理，防止内存溢出

**命令示例：**
```
drush clean-contents:delete-old-revisions [--dry-run] [--entity-type=类型]
别名：cc:dr
```

### 5. 删除无效别名和重定向
- 删除指向已删除内容的别名和重定向
- 支持“演练模式”

**命令示例：**
```
drush clean-contents:delete-broken-paths [--dry-run]
别名：cc:dbp
```

## 一键清理命令

可以一条命令全部清理：
```
drush clean-contents:cleanup-all [--dry-run] [--skip-unpublished] [--skip-orphans] [--skip-revisions] [--skip-users]
别名：cc:all
```

顺序：
1. 删除未发布内容
2. 删除孤儿内容
3. 删除历史版本
4. 删除无效别名和重定向
5. 用户重命名

## 目录结构

```
web/modules/custom/clean_contents/
├── clean_contents.info.yml
├── clean_contents.services.yml
├── composer.json（可选）
├── src/
│   ├── Commands/
│   │   └── CleanContentsCommands.php
│   └── Service/
│       ├── ContentCleanupService.php
│       └── OrphanDetectionService.php
└── README.md
```

## 文件说明

- `clean_contents.info.yml`：模块信息和依赖
- `clean_contents.services.yml`：服务定义
- `src/Commands/CleanContentsCommands.php`：Drush 命令实现
- `src/Service/ContentCleanupService.php`：内容清理逻辑
- `src/Service/OrphanDetectionService.php`：孤儿内容检测逻辑
- `README.md`：模块说明

---

**友情提示：**
- 这些命令会永久删除内容，建议只在开发/测试环境使用，操作前请务必备份数据库！
- 支持 dry-run 预览，不会真的删除内容。
- 批量处理，适合大数据量。
- 删除历史版本和孤儿内容可以大幅减少数据库体积，提高性能。
- 用户重命名适合演示/测试环境，防止泄露真实用户信息。
