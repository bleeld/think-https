# bleeld/think-https

基于 PHP cURL 的 HTTP 客户端库，仿照 Guzzle 风格设计，深度集成 ThinkPHP 8。

## 目录

- [环境要求](#环境要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [Client 客户端](#client-客户端)
- [请求选项](#请求选项)
- [Response 响应](#response-响应)
- [异常处理](#异常处理)
- [中间件系统](#中间件系统)
- [Cookie 管理](#cookie-管理)
- [代理池管理](#代理池管理)
- [爬虫功能](#爬虫功能)
- [助手函数](#助手函数)
- [安全特性](#安全特性)
- [完整应用示例](#完整应用示例)

---

## 环境要求

- PHP >= 8.0
- ext-curl
- ext-json

---

## 安装

已在项目 `composer.json` 中配置 path repository，直接加载即可：

```json
{
    "repositories": [
        { "type": "path", "url": "vendor/bleeld/think-https" }
    ],
    "require": {
        "bleeld/think-https": "*@dev"
    }
}
```

---

## 快速开始

### 最简单的请求

```php
use think\http\Client;

$client = new Client();
$response = $client->get('https://httpbin.org/get');

echo $response->getStatusCode();  // 200
echo $response->getBody();        // JSON 字符串
```

### 使用助手函数

```php
// 一行搞定
$response = http_get('https://httpbin.org/get');
$data = http_json('https://api.example.com/users');
$ok = http_check('https://example.com');  // true/false
```

---

## Client 客户端

### 配置项一览

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `base_uri` | string | `''` | 基础 URI，请求时自动拼接 |
| `timeout` | float | `30.0` | 请求超时（秒） |
| `connect_timeout` | float | `10.0` | 连接超时（秒） |
| `verify` | bool | `true` | SSL 证书验证 |
| `allow_redirects` | bool | `true` | 是否跟随重定向 |
| `max_redirects` | int | `10` | 最大重定向次数 |
| `http_errors` | bool | `true` | 4xx/5xx 是否抛异常 |
| `headers` | array | `[]` | 默认请求头 |
| `user_agent` | string | `'ThinkHTTP/1.0'` | User-Agent |
| `proxy` | string | `''` | 代理地址 |
| `debug` | bool | `false` | 调试模式 |
| `max_body_size` | int | `0` | 响应体大小限制（0=不限） |
| `allowed_schemes` | array | `['http','https']` | 允许的 URL 协议 |
| `block_private_ips` | bool | `true` | 阻止内网 IP（防 SSRF） |
| `cookie_jar` | CookieJar\|null | `null` | Cookie 管理器 |
| `proxy_manager` | ProxyManager\|null | `null` | 代理池管理器 |

### 创建客户端

```php
$client = new Client([
    'base_uri' => 'https://api.github.com',
    'timeout'  => 5.0,
    'headers'  => [
        'Accept' => 'application/vnd.github.v3+json',
    ],
]);
```

### HTTP 方法

```php
$client->get('/users');
$client->post('/users', ['json' => ['name' => 'John']]);
$client->put('/users/1', ['json' => ['name' => 'Jane']]);
$client->delete('/users/1');
$client->patch('/users/1', ['json' => ['name' => 'Bob']]);
$client->head('/users');
$client->options('/users');

// 通用方法
$client->request('GET', '/users');
```

### 并发请求（pool）

```php
$results = $client->pool([
    ['method' => 'GET', 'uri' => 'https://httpbin.org/get'],
    ['method' => 'GET', 'uri' => 'https://httpbin.org/headers'],
    ['method' => 'GET', 'uri' => 'https://httpbin.org/ip'],
]);

foreach ($results as $response) {
    if ($response instanceof \think\http\Response) {
        echo $response->getStatusCode();  // 200
    } else {
        // ConnectException
        echo $response->getMessage();
    }
}
```

### 事件钩子

```php
$client->onBeforeRequest(function ($method, $url, $options) {
    echo "即将请求: {$method} {$url}\n";
});

$client->onAfterRequest(function ($method, $url, $response) {
    echo "请求完成: {$response->getStatusCode()} ({$response->getTransferTime()}s)\n";
});
```

**预期输出：**
```
即将请求: GET https://httpbin.org/get
请求完成: 200 (0.523s)
```

---

## 请求选项

请求选项可在每次请求时传入，会覆盖客户端默认配置。

### json — 发送 JSON 数据

```php
$response = $client->post('/api/users', [
    'json' => [
        'name'  => '张三',
        'email' => 'zhangsan@example.com',
    ],
]);
```

**实际发送的请求体：**
```json
{"name":"张三","email":"zhangsan@example.com"}
```
**自动设置 Header：** `Content-Type: application/json`

### form_params — 发送表单数据

```php
$response = $client->post('/login', [
    'form_params' => [
        'username' => 'admin',
        'password' => '123456',
    ],
]);
```

**实际发送的请求体：**
```
username=admin&password=123456
```
**自动设置 Header：** `Content-Type: application/x-www-form-urlencoded`

### query — URL 查询参数

```php
$response = $client->get('/api/search', [
    'query' => [
        'keyword' => 'ThinkPHP',
        'page'    => 1,
        'limit'   => 20,
    ],
]);
// 实际请求: /api/search?keyword=ThinkPHP&page=1&limit=20
```

### auth — 认证

```php
// Basic Auth
$response = $client->get('/api/data', [
    'auth' => ['username', 'password'],
]);

// Bearer Token
$response = $client->get('/api/data', [
    'auth' => 'your-api-token-here',
]);
// 自动设置 Header: Authorization: Bearer your-api-token-here
```

### multipart — 文件上传

```php
$response = $client->post('/upload', [
    'multipart' => [
        ['name' => 'file', 'contents' => fopen('/path/to/file.jpg', 'r'), 'filename' => 'photo.jpg'],
        ['name' => 'description', 'contents' => 'A photo'],
    ],
]);
```

### body — 原始请求体

```php
$response = $client->post('/api/webhook', [
    'body' => '<xml><data>test</data></xml>',
    'headers' => ['Content-Type' => 'application/xml'],
]);
```

### 其他选项

```php
$response = $client->get('/api/data', [
    'timeout'         => 5,           // 超时
    'verify'          => false,       // 跳过 SSL 验证
    'proxy'           => 'http://proxy:8080',  // 代理
    'http_errors'     => false,       // 不抛异常
    'allow_redirects' => false,       // 不跟随重定向
    'debug'           => true,        // 调试输出
]);
```

---

## Response 响应

### 方法一览

| 方法 | 返回类型 | 说明 |
|------|----------|------|
| `getStatusCode()` | int | HTTP 状态码 |
| `getReasonPhrase()` | string | 状态短语（如 OK, Not Found） |
| `getBody()` | string | 响应体原始字符串 |
| `getHeaders()` | array | 所有响应头 |
| `hasHeader($name)` | bool | 是否有指定头 |
| `getHeader($name)` | array | 获取头（数组） |
| `getHeaderLine($name)` | string | 获取头的第一个值 |
| `getHeaderLines($name)` | string | 获取所有同名头拼接 |
| `getContentType()` | string | Content-Type |
| `getContentLength()` | int | Content-Length |
| `getTransferTime()` | float | 请求耗时（秒） |
| `getRequestInfo()` | array | cURL 完整信息 |
| `json($assoc)` | mixed | 解析 JSON 响应体 |
| `isSuccessful()` | bool | 2xx |
| `isRedirect()` | bool | 3xx |
| `isClientError()` | bool | 4xx |
| `isServerError()` | bool | 5xx |
| `ok()` | bool | 状态码 === 200 |
| `__toString()` | string | 响应体字符串 |

### 使用示例

```php
$response = $client->get('https://httpbin.org/get');

// 状态
echo $response->getStatusCode();       // 200
echo $response->getReasonPhrase();     // OK
echo $response->isSuccessful();        // true
echo $response->ok();                  // true

// 响应头
echo $response->getContentType();      // application/json
echo $response->getHeaderLine('server'); // nginx

// 响应体
echo $response->getBody();             // 原始 JSON 字符串
$data = $response->json();             // 解析为数组
echo $data['headers']['Host'];         // httpbin.org

// 性能
echo $response->getTransferTime();     // 0.352 (秒)
```

**`json()` 解析 httpbin.org/get 的预期返回：**
```php
[
    'args'    => [],
    'headers' => [
        'Accept'     => '*/*',
        'Host'       => 'httpbin.org',
        'User-Agent' => 'ThinkHTTP/1.0',
    ],
    'origin' => '203.0.113.1',
    'url'    => 'https://httpbin.org/get',
]
```

---

## 异常处理

### 异常类层次

```
\RuntimeException
└── RequestException (HTTP 错误)
    ├── ConnectException (连接失败)
    └── TooManyRedirectsException (重定向过多)
```

### 捕获异常

```php
use think\http\Exception\RequestException;
use think\http\Exception\ConnectException;

try {
    $response = $client->get('https://httpbin.org/status/404');
} catch (RequestException $e) {
    echo $e->getMessage();          // Client error: 404 Not Found
    echo $e->getRequestUrl();       // https://httpbin.org/status/404
    echo $e->getRequestMethod();    // GET
    
    if ($e->hasResponse()) {
        $resp = $e->getResponse();
        echo $resp->getStatusCode(); // 404
        echo $resp->getBody();       // 错误页面内容
    }
}

try {
    $client->get('http://unreachable-host-xyz.invalid');
} catch (ConnectException $e) {
    echo $e->getMessage();  // cURL error 6: Could not resolve host...
}
```

### 禁止异常

```php
// 设置 http_errors => false，4xx/5xx 不抛异常
$response = $client->get('https://httpbin.org/status/500', [
    'http_errors' => false,
]);
echo $response->getStatusCode();  // 500
echo $response->isServerError();  // true
```

---

## 中间件系统

中间件按 LIFO（后进先出）顺序执行，类似洋葱模型。

### 内置中间件

```php
use think\http\Middleware\Middleware;

// 重试（3次，指数退避，500/502/503/504）
$client->pushMiddleware(Middleware::retry(3, 1000));

// 日志
$client->pushMiddleware(Middleware::log(function ($msg) {
    echo $msg . "\n";
}));
// 输出:
// [HTTP] --> GET https://api.example.com/users
// [HTTP] <-- 200 https://api.example.com/users (152.3ms)

// Basic Auth
$client->pushMiddleware(Middleware::basicAuth('user', 'pass'));

// Bearer Token
$client->pushMiddleware(Middleware::bearerToken('your-token'));

// 默认请求头
$client->pushMiddleware(Middleware::defaultHeaders([
    'X-Api-Key'    => 'abc123',
    'X-Client-Ver' => '2.0',
]));

// 超时覆盖
$client->pushMiddleware(Middleware::timeout(10.0));

// User-Agent 覆盖
$client->pushMiddleware(Middleware::userAgent('MyApp/2.0'));

// 缓存
$cache = [];
$client->pushMiddleware(Middleware::cache(
    fn($key) => $cache[$key] ?? null,
    fn($key, $resp) => $cache[$key] = $resp
));
```

### 自定义中间件

```php
$client->pushMiddleware(function (callable $handler) {
    return function (string $method, string $url, array $options) use ($handler) {
        // 请求前处理
        $options['headers']['X-Custom'] = 'value';
        
        // 调用下一层
        $response = $handler($method, $url, $options);
        
        // 响应后处理
        $response->getHeaders()['x-custom'] = 'added';
        
        return $response;
    };
});
```

---

## Cookie 管理

### 基本使用

```php
use think\http\Cookie\CookieJar;

$jar = new CookieJar('example.com');

// 设置 Cookie
$jar->set('session_id', 'abc123');
$jar->set('user_token', 'xyz', 'example.com', '/', time() + 3600);

// 获取 Cookie
echo $jar->get('session_id', 'example.com');  // abc123

// 生成请求头
echo $jar->getHeader('example.com');
// session_id=abc123; user_token=xyz
```

### 自动管理（配合 Client）

```php
$jar = http_cookie_jar('example.com');
$client = new Client([
    'cookie_jar' => $jar,
    'block_private_ips' => false,
]);

// 登录 — 服务器返回 Set-Cookie
$client->post('/login', [
    'form_params' => ['user' => 'admin', 'pass' => '123'],
]);

// 后续请求自动携带 Cookie
$response = $client->get('/dashboard');
// 请求头自动包含: Cookie: session_id=xxx; token=yyy

// 持久化到文件
$jar->save('/path/to/cookies.json');

// 从文件恢复
$jar->load('/path/to/cookies.json');
```

### CookieJar 方法一览

| 方法 | 说明 |
|------|------|
| `set($name, $value, $domain, $path, $expires)` | 设置 Cookie |
| `get($name, $domain, $path)` | 获取值 |
| `remove($name, $domain)` | 删除 |
| `clear()` | 清空 |
| `purge()` | 清除过期 |
| `getHeader($domain, $path, $isSecure)` | 生成请求头字符串 |
| `fromResponse($response, $url)` | 从响应解析 Set-Cookie |
| `save($filePath)` / `load($filePath)` | 持久化 |
| `count()` | Cookie 数量 |
| `all()` | 获取所有数据 |

---

## 代理池管理

### 基本使用

```php
use think\http\Proxy\ProxyManager;

$pool = new ProxyManager('round_robin');  // 轮换策略

// 添加代理
$pool->add('http://proxy1:8080');
$pool->add('http://proxy2:8080', ['weight' => 3]);  // 权重更高
$pool->add('socks5://proxy3:1080', ['auth' => ['user', 'pass']]);

// 批量添加
$pool->addMany([
    'http://p1:8080',
    'http://p2:8080',
    ['proxy' => 'http://p3:8080', 'weight' => 5],
]);

// 从文件加载（每行一个代理）
$pool->loadFromFile('/path/to/proxies.txt');

// 选择一个代理
$proxy = $pool->select();  // http://proxy1:8080

// 标记成功/失败
$pool->markSuccess('http://proxy1:8080');
$pool->markFailed('http://proxy2:8080');

// 查看状态
echo $pool->aliveCount();    // 存活数
echo $pool->totalCount();    // 总数
print_r($pool->status());    // 详细状态
```

### 轮换策略

| 策略 | 说明 |
|------|------|
| `round_robin` | 轮询，依次使用每个代理 |
| `random` | 随机选择 |
| `weighted_random` | 加权随机，权重高的被选中概率大 |
| `priority` | 优先级，始终选权重最高的 |

### 配合 Client 使用

```php
$pool = http_proxy_manager('round_robin');
$pool->addMany([
    'http://proxy1:8080',
    'http://proxy2:8080',
    'http://proxy3:8080',
]);

$client = new Client([
    'proxy_manager' => $pool,
    'block_private_ips' => false,
]);

// 每次请求自动使用代理池中的一个
// 请求成功自动 markSuccess，连接失败自动 markFailed
$response = $client->get('https://httpbin.org/ip');
```

### 健康检查

```php
$results = $pool->healthCheck('http://httpbin.org/ip', 5.0);
// 返回:
// [
//     'http://proxy1:8080' => ['alive' => true, 'httpCode' => 200, 'error' => ''],
//     'http://proxy2:8080' => ['alive' => false, 'httpCode' => 0, 'error' => '...'],
// ]
```

---

## 爬虫功能

### 快速爬取

```php
use think\http\Crawler\Crawler;

$crawler = new Crawler([
    'base_uri'  => 'https://example.com',
    'max_depth' => 2,       // 最大深度
    'max_pages' => 50,      // 最多爬 50 页
    'delay_min' => 1000,    // 最小延迟 1 秒
    'delay_max' => 3000,    // 最大延迟 3 秒
]);

// 爬取单页
$page = $crawler->fetch('https://example.com/article');
```

### CrawlPage 页面解析

```php
// 基本信息
echo $page->getTitle();          // "文章标题 - Example"
echo $page->getDescription();   // "这是一篇文章的摘要描述"
echo $page->getKeywords();      // "ThinkPHP,PHP,教程"
echo $page->getStatusCode();    // 200

// 提取链接
$links = $page->getLinks();
// 返回: ['/about', '/contact', 'https://other.com', '/page/2', ...]

// 提取图片
$images = $page->getImages();
// 返回: ['/img/logo.png', 'https://cdn.com/photo.jpg', ...]

// 提取纯文本（自动去除 script/style）
$text = $page->getText();
// 返回: "文章标题 这是文章的正文内容..."

// 提取表格
$tables = $page->getTables();
// 返回:
// [
//     [
//         ['Name' => 'Alice', 'Age' => '30'],
//         ['Name' => 'Bob', 'Age' => '25'],
//     ]
// ]
```

### CSS 选择器

```php
// 支持的语法：tag, .class, #id, tag.class, tag[attr], tag[attr=value]

// ID 选择器
echo $page->text('#main-title');        // "主标题"

// Class 选择器
echo $page->text('.article-content');   // "文章正文..."

// 标签选择器
echo $page->text('h1');                 // "页面标题"

// 属性提取
echo $page->attr('a.logo', 'href');     // "/home"
echo $page->attr('img.cover', 'src');   // "/img/cover.jpg"

// 查找所有匹配节点
$nodes = $page->findAll('.item');
foreach ($nodes as $node) {
    echo $node->textContent;
}

// 第一个匹配
$node = $page->findFirst('.price');
echo $node ? $node->textContent : 'N/A';

// 原始 DOM/XPath
$dom = $page->getDom();
$xpath = $page->getXpath();
```

### 深度爬取

```php
$crawler = new Crawler([
    'base_uri'  => 'https://example.com',
    'max_depth' => 2,
    'max_pages' => 100,
    'same_domain' => true,  // 只爬同域名
]);

// 深度爬取，带过滤和回调
$pages = $crawler->crawl(
    '/blog',
    2,  // 最大深度
    function ($url) {
        // URL 过滤回调：返回 true 才爬取
        return !str_contains($url, '/admin') && !str_contains($url, '/login');
    },
    function ($page) {
        // 每页回调：实时处理
        echo "已爬取: {$page->getUrl()} - {$page->getTitle()}\n";
    }
);

// $pages 是 CrawlPage[] 数组，key 为 URL
echo count($pages);  // 42

foreach ($pages as $url => $page) {
    echo "{$url}: {$page->getTitle()}\n";
}
```

**预期输出：**
```
已爬取: https://example.com/blog - 博客首页 - Example
已爬取: https://example.com/blog/page/2 - 第2页 - Example
已爬取: https://example.com/blog/post/thinkphp-tutorial - ThinkPHP 教程
...
42
```

### robots.txt 检查

```php
$allowed = $crawler->isAllowedByRobots('https://example.com/private/');
// true = 允许, false = 被 robots.txt 禁止
```

### 爬虫中间件

```php
use think\http\Middleware\CrawlerMiddleware;

// 单独使用各中间件
$client->pushMiddleware(CrawlerMiddleware::randomUserAgent());    // 随机 UA
$client->pushMiddleware(CrawlerMiddleware::browserHeaders());     // 浏览器头伪装
$client->pushMiddleware(CrawlerMiddleware::randomReferer());      // 随机 Referer
$client->pushMiddleware(CrawlerMiddleware::delay(500, 2000));     // 随机延迟
$client->pushMiddleware(CrawlerMiddleware::rateLimit(60, 60));    // 60秒内最多60次

// 一键预设（推荐）
$jar = http_cookie_jar();
$middlewares = CrawlerMiddleware::stealthPreset($jar);
foreach ($middlewares as $mw) {
    $client->pushMiddleware($mw);
}
// 自动包含：随机UA + 浏览器头 + Referer + 延迟 + Cookie管理
```

---

## 助手函数

| 函数 | 说明 | 返回 |
|------|------|------|
| `http_client($config)` | 创建 Client | `Client` |
| `http_get($url, $options)` | GET 请求 | `Response` |
| `http_post($url, $options)` | POST 请求 | `Response` |
| `http_put($url, $options)` | PUT 请求 | `Response` |
| `http_delete($url, $options)` | DELETE 请求 | `Response` |
| `http_patch($url, $options)` | PATCH 请求 | `Response` |
| `http_head($url, $options)` | HEAD 请求 | `Response` |
| `http_options($url, $options)` | OPTIONS 请求 | `Response` |
| `http_json($url, $method, $options)` | 请求并解析 JSON | `mixed` |
| `http_check($url, $timeout)` | 检查 URL 可访问性 | `bool` |
| `http_download($url, $savePath, $options)` | 流式下载文件 | `bool` |
| `http_upload($url, $files, $fields, $options)` | 上传文件 | `Response` |
| `http_cookie_jar($domain)` | 创建 CookieJar | `CookieJar` |
| `http_proxy_manager($strategy)` | 创建代理池 | `ProxyManager` |
| `http_crawler($config)` | 创建爬虫 | `Crawler` |

---

## 安全特性

### SSRF 防护

默认阻止对内网 IP 的请求，防止服务端请求伪造攻击：

```php
// 默认行为 — 阻止内网
$client = new Client();
$client->get('http://127.0.0.1/admin');
// 抛出 ConnectException: Request to private IP address is blocked: 127.0.0.1

$client->get('http://169.254.169.254/latest/meta-data/');
// 抛出 ConnectException（阻止云元数据接口）

$client->get('file:///etc/passwd');
// 抛出 InvalidArgumentException: URL scheme 'file' is not allowed

// 如需允许内网（测试环境）
$client = new Client(['block_private_ips' => false]);
```

阻止的 IP 段：`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0/8`

### 请求头注入防护

自动过滤 header 值中的 `\r\n`，防止 CRLF 注入。

### 敏感信息脱敏

异常信息中的 URL 参数 `token`, `key`, `secret`, `password`, `api_key` 等自动替换为 `***`。

### 跨域重定向认证保护

手动处理重定向，跨域名时自动移除 `Authorization` 头和 Basic Auth 凭据。

### 响应体大小限制

```php
$client = new Client(['max_body_size' => 1024 * 1024]); // 限制 1MB
```

### HTTP 方法白名单

只允许 `GET`, `POST`, `PUT`, `DELETE`, `PATCH`, `HEAD`, `OPTIONS` 七种标准方法。

---

## 完整应用示例

### 示例 1：调用 REST API

```php
$client = new Client([
    'base_uri' => 'https://jsonplaceholder.typicode.com',
    'timeout'  => 5.0,
    'headers'  => ['Accept' => 'application/json'],
]);

// 获取用户列表
$response = $client->get('/users');
$users = $response->json();

// 预期返回：
// [
//     ['id' => 1, 'name' => 'Leanne Graham', 'email' => 'Sincere@april.biz', ...],
//     ['id' => 2, 'name' => 'Ervin Howell', 'email' => 'Shanna@melissa.tv', ...],
//     ...
// ]

// 创建文章
$response = $client->post('/posts', [
    'json' => [
        'title'  => 'ThinkPHP 教程',
        'body'   => '这是一个很好的教程',
        'userId' => 1,
    ],
]);
$newPost = $response->json();
echo $newPost['id'];  // 101

// 预期返回：
// {'title':'ThinkPHP 教程','body':'这是一个很好的教程','userId':1,'id':101}
```

### 示例 2：带重试和日志的稳健请求

```php
$client = new Client([
    'base_uri' => 'https://api.example.com',
    'timeout'  => 10.0,
]);

// 添加重试中间件（最多重试 3 次，对 500/502/503 重试）
$client->pushMiddleware(\think\http\Middleware\Middleware::retry(3, 1000));

// 添加日志中间件
$client->pushMiddleware(\think\http\Middleware\Middleware::log(function ($msg) {
    // 实际项目中写入日志文件
    echo $msg . "\n";
}));

try {
    $response = $client->get('/data');
    echo $response->json()['status'];  // "ok"
} catch (\think\http\Exception\ConnectException $e) {
    echo "连接失败: " . $e->getMessage();
} catch (\think\http\Exception\RequestException $e) {
    echo "请求错误: " . $e->getResponse()->getStatusCode();
}
```

**预期输出（正常情况）：**
```
[HTTP] --> GET https://api.example.com/data
[HTTP] <-- 200 https://api.example.com/data (245.67ms)
ok
```

**预期输出（重试后成功）：**
```
[HTTP] --> GET https://api.example.com/data
[HTTP] <-- ERROR https://api.example.com/data (10012.34ms): Server error: 503 Service Unavailable
[HTTP] --> GET https://api.example.com/data
[HTTP] <-- 200 https://api.example.com/data (198.45ms)
ok
```

### 示例 3：带 Cookie 会话的登录爬取

```php
$jar = http_cookie_jar('example.com');
$client = new Client([
    'base_uri'   => 'https://example.com',
    'cookie_jar' => $jar,
    'block_private_ips' => false,
]);

// 登录
$client->post('/login', [
    'form_params' => ['username' => 'admin', 'password' => '123456'],
]);

// 服务器返回: Set-Cookie: PHPSESSID=abc123; Path=/; HttpOnly
// CookieJar 自动存储

// 访问需要登录的页面
$response = $client->get('/dashboard');
// 请求自动携带: Cookie: PHPSESSID=abc123

echo $response->getStatusCode();  // 200
echo $jar->count();               // 1
echo $jar->get('PHPSESSID', 'example.com');  // abc123
```

### 示例 4：代理池轮询爬取

```php
$pool = http_proxy_manager('round_robin');
$pool->addMany([
    'http://proxy1:8080',
    'http://proxy2:8080',
    'http://proxy3:8080',
]);

$client = new Client([
    'proxy_manager' => $pool,
    'timeout'       => 15.0,
    'block_private_ips' => false,
]);

// 添加重试（代理失败自动切换下一个）
$client->pushMiddleware(\think\http\Middleware\Middleware::retry(3, 500));

// 连续请求，自动轮询代理
for ($i = 0; $i < 10; $i++) {
    try {
        $response = $client->get("https://httpbin.org/get?page={$i}");
        echo "Page {$i}: " . $response->getStatusCode() . "\n";
    } catch (\Throwable $e) {
        echo "Page {$i} failed: " . $e->getMessage() . "\n";
    }
}

// 查看代理健康状态
echo "存活: {$pool->aliveCount()}/{$pool->totalCount()}\n";
```

**预期输出：**
```
Page 0: 200
Page 1: 200
Page 2: 200
...
Page 9: 200
存活: 3/3
```

### 示例 5：网站爬虫完整示例

```php
$crawler = http_crawler([
    'base_uri'  => 'https://example.com',
    'max_depth' => 1,
    'max_pages' => 20,
    'delay_min' => 1000,
    'delay_max' => 2000,
]);

// 爬取首页
$page = $crawler->fetch('/');

echo "标题: " . $page->getTitle() . "\n";
echo "描述: " . $page->getDescription() . "\n";
echo "链接数: " . count($page->getLinks()) . "\n";
echo "图片数: " . count($page->getImages()) . "\n";

// 预期输出：
// 标题: Example Domain
// 描述: 
// 链接数: 1
// 图片数: 0

// 提取特定内容
echo $page->text('h1');           // "Example Domain"
echo $page->attr('a', 'href');    // "https://www.iana.org/domains/example"

// 深度爬取博客
$pages = $crawler->crawl('/blog', 1, function ($url) {
    return !str_contains($url, '/draft/');  // 排除草稿
});

foreach ($pages as $url => $p) {
    echo "[{$p->getStatusCode()}] {$p->getTitle()}\n";
}
// 预期输出：
// [200] Blog - Example
// [200] First Post - Example
// [200] Second Post - Example
```

### 示例 6：文件下载与上传

```php
// 流式下载（不会 OOM）
$ok = http_download(
    'https://example.com/large-file.zip',
    '/tmp/download/large-file.zip'
);
echo $ok ? '下载成功' : '下载失败';  // 下载成功

// 上传文件
$response = http_upload(
    'https://httpbin.org/post',
    [
        ['name' => 'avatar', 'path' => '/path/to/photo.jpg', 'filename' => 'my-photo.jpg'],
    ],
    ['description' => 'My photo']  // 额外表单字段
);

$data = $response->json();
echo $data['files']['avatar'] ?? '';  // 文件内容
```

### 示例 7：并发请求

```php
$client = new Client([
    'base_uri' => 'https://httpbin.org',
    'block_private_ips' => false,
]);

$results = $client->pool([
    ['method' => 'GET', 'uri' => '/get'],
    ['method' => 'GET', 'uri' => '/headers'],
    ['method' => 'GET', 'uri' => '/ip'],
    ['method' => 'GET', 'uri' => '/user-agent'],
]);

foreach ($results as $i => $result) {
    if ($result instanceof \think\http\Response) {
        echo "请求 {$i}: {$result->getStatusCode()}\n";
    } else {
        echo "请求 {$i} 失败: {$result->getMessage()}\n";
    }
}

// 预期输出：
// 请求 0: 200
// 请求 1: 200
// 请求 2: 200
// 请求 3: 200
```

---

## 文件结构

```
vendor/bleeld/think-https/
├── composer.json
└── src/
    ├── Client.php              # HTTP 客户端核心
    ├── Response.php            # 响应类
    ├── Service.php             # ThinkPHP 服务提供者
    ├── helpers.php             # 助手函数
    ├── Cookie/
    │   └── CookieJar.php       # Cookie 管理器
    ├── Crawler/
    │   ├── Crawler.php         # 爬虫主类
    │   └── CrawlPage.php       # 页面解析类
    ├── Exception/
    │   ├── RequestException.php        # 请求异常基类
    │   ├── ConnectException.php        # 连接异常
    │   └── TooManyRedirectsException.php # 重定向异常
    ├── Middleware/
    │   ├── Middleware.php          # 内置中间件工厂
    │   └── CrawlerMiddleware.php   # 爬虫中间件集合
    └── Proxy/
        └── ProxyManager.php    # 代理池管理器
```
