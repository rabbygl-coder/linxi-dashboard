<?php
/**
 * 林夕工作台 —— 云端同步后端（PHP + MySQL 版，适配宝塔 / NGINX + PHP + MySQL 5.7）
 * ------------------------------------------------------------------
 * 接口（与前端「☁️ 云端同步」对接，契约不变）：
 *   GET  ?key=KEY              -> 返回 {ts,data}；该 KEY 无数据则返回 204
 *   POST  body {key,ts,data}   -> 按时间戳 last-write-wins 写入；
 *                                  若传入 ts 小于云端已存 ts（云端更新），返回 409 让客户端拉取
 *   GET  ?health=1             -> 健康检查，返回 {ok:true, db, table}（用于验证数据库连接）
 *   OPTIONS                    -> 204 预检（CORS 跨域用）
 *
 * 数据存储：MySQL 表 sync_states（每个 KEY 一行；首次访问自动建库建表）
 * CORS：允许跨域（GitHub Pages 等异源页面也能调用；若直接用本服务器打开应用则为同源）
 *
 * 部署（宝塔）：
 *   1) 站点根目录需启用 PHP（宝塔建站默认带 PHP，MySQL 5.7 已装）
 *   2) 把本文件 sync.php 与 index.html 等一起上传到网站根目录
 *   3) 下方 DB_* 常量已按你提供的 MySQL 信息填写
 *   4) 前端「服务器地址」填本文件的完整网址，例如 https://ccdashboard.minenotes.com/sync.php
 *   5)（可选）站点 → SSL → Let's Encrypt 开启 HTTPS，同步地址改用 https://
 *
 * 安全提示：本文件含数据库账号密码，仅供服务器使用，切勿提交到 GitHub（GitHub Pages 只需 index.html + 图片）。
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ===================== 数据库配置（已按你提供的填写） ===================== */
define('DB_HOST', '120.24.61.217'); // MySQL 服务器 IP（PHP 与 MySQL 同机时下方会自动回退到 127.0.0.1，绕开 bind/firewall 限制）
define('DB_PORT', 3306);
define('DB_USER', 'ccdashboard');
define('DB_PASS', '3YBdyRMmGFXyBHbp');
define('DB_NAME', 'ccdashboard');    // 库名（首次会自动 CREATE；若用户无建库权限，请先在宝塔手动建库并授权）
/* ======================================================================= */

function fail($code, $msg) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

// 连接数据库：优先用配置的 IP，失败则回退到 127.0.0.1（同机回环，绕开防火墙/bind 限制）
function connectDB() {
  $hosts = [DB_HOST];
  if (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') $hosts[] = '127.0.0.1';
  $lastErr = '';
  foreach ($hosts as $h) {
    $c = @mysqli_connect($h, DB_USER, DB_PASS, '', DB_PORT);
    if ($c) {
      mysqli_set_charset($c, 'utf8mb4');
      return $c;
    }
    $lastErr = mysqli_connect_error();
  }
  fail(500, 'MySQL 连接失败: ' . $lastErr);
}

$conn = connectDB();

// 确保库与表存在（无建库权限时，仅尝试使用已存在的库）
mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
if (!mysqli_select_db($conn, DB_NAME)) {
  fail(500, '无法选择数据库 `' . DB_NAME . '`，请先在宝塔手动创建该数据库并授权给用户 `' . DB_USER . '`');
}
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `sync_states` (
  `sync_key`  VARCHAR(255) NOT NULL,
  `ts`        BIGINT       NOT NULL DEFAULT 0,
  `data`      MEDIUMTEXT   NOT NULL,
  `updated_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sync_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$method = $_SERVER['REQUEST_METHOD'];

// 健康检查（验证数据库连通性用）
if ($method === 'GET' && isset($_GET['health'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true, 'db' => DB_NAME, 'table' => 'sync_states'], JSON_UNESCAPED_UNICODE);
  exit;
}

// 解析 KEY（GET 用 query，POST 用 body）
$key = '';
if (isset($_GET['key'])) $key = $_GET['key'];
$raw = (string)file_get_contents('php://input');
$payload = $raw !== '' ? @json_decode($raw, true) : null;
if (is_array($payload) && isset($payload['key'])) $key = $payload['key'];
$key = trim((string)$key);
if ($key === '') fail(400, 'missing or invalid key');
if (mb_strlen($key, 'UTF-8') > 255) $key = mb_substr($key, 0, 255, 'UTF-8');

if ($method === 'GET') {
  $stmt = mysqli_prepare($conn, "SELECT ts, data FROM sync_states WHERE sync_key = ?");
  if (!$stmt) fail(500, '查询准备失败: ' . mysqli_error($conn));
  mysqli_stmt_bind_param($stmt, 's', $key);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_bind_result($stmt, $ts, $data);
  if (!mysqli_stmt_fetch($stmt)) { http_response_code(204); exit; }
  mysqli_stmt_close($stmt);
  $obj = $data !== null ? @json_decode($data, true) : null;
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ts' => (int)$ts, 'data' => $obj], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method === 'POST') {
  if (!is_array($payload) || !isset($payload['ts']) || !isset($payload['data'])) {
    fail(400, 'bad payload');
  }
  $ts = (int)$payload['ts'];
  $dataStr = json_encode($payload['data'], JSON_UNESCAPED_UNICODE);

  // 检查云端当前 ts，冲突则 409 让客户端拉取
  $stmt = mysqli_prepare($conn, "SELECT ts FROM sync_states WHERE sync_key = ?");
  if (!$stmt) fail(500, '查询准备失败: ' . mysqli_error($conn));
  mysqli_stmt_bind_param($stmt, 's', $key);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_bind_result($stmt, $curTs);
  $has = mysqli_stmt_fetch($stmt);
  mysqli_stmt_close($stmt);
  if ($has && (int)$curTs > $ts) {
    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'conflict' => true, 'ts' => (int)$curTs], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // upsert（插入或按主键更新）
  $stmt = mysqli_prepare($conn, "INSERT INTO sync_states (sync_key, ts, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ts = VALUES(ts), data = VALUES(data)");
  if (!$stmt) fail(500, '写入准备失败: ' . mysqli_error($conn));
  mysqli_stmt_bind_param($stmt, 'sis', $key, $ts, $dataStr);
  if (!mysqli_stmt_execute($stmt)) fail(500, '写入失败: ' . mysqli_stmt_error($stmt));
  mysqli_stmt_close($stmt);

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true, 'ts' => $ts], JSON_UNESCAPED_UNICODE);
  exit;
}

fail(405, 'method not allowed');
