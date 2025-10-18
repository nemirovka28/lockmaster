<?php
// api.php
// ====== НАСТРОЙКА ======
$BOT_TOKEN = '8317145433:AAGMV4QAYyfOLdCanQVOfjFgxHYVtN6HLW0';      // <-- токен вашего бота
$CHAT_ID   = -4812627007;         // <-- ID группы/канала (обычно отрицательный). Добавьте бота в группу.

// ====== ОТВЕТ В JSON ======
header('Content-Type: application/json; charset=utf-8');

// Разрешим только POST + multipart
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false, 'error'=>'Method Not Allowed']); exit;
}

// Небольшая валидация/нормализация
function val($key){ return isset($_POST[$key]) ? trim((string)$_POST[$key]) : ''; }
$service  = val('service');
$fullname = val('fullname');
$email    = val('email');
$phone    = val('phone');

// Защита от HTML в сообщении
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Служебная инфа
$ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
date_default_timezone_set('Europe/Kyiv'); 
$dt   = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
$time = $dt->format('Y-m-d H:i:s');

// Текст заявки (HTML-разметка для Telegram)
$message  = "<b>❗️❗️❗️Новая заявка❗️❗️❗️</b>\n";
$message .= "<b>🔑 Услуга:</b> ".esc($service)."\n";
$message .= "<b>👤 Имя и фамилия:</b> ".esc($fullname)."\n";
$message .= "<b>✉️ Email:</b> ".esc($email)."\n";
$message .= "<b>📱 Телефон:</b> ".esc($phone)."\n\n";
$message .= "<i>🕒 Время:</i> {$time}\n";
$message .= "<i>UserAgent:</i> ".esc($ua);

// ================== ОТПРАВКА В TELEGRAM ==================
function tg_api_send($method, $params = [], $files = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  if (!empty($files)) {
    // multipart
    foreach ($files as $k => $path) {
      $params[$k] = new CURLFile($path);
    }
  }
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  $res = curl_exec($ch);
  if ($res === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok'=>false, 'error'=>$err];
  }
  curl_close($ch);
  $json = json_decode($res, true);
  return $json ?: ['ok'=>false, 'error'=>'Invalid JSON'];
}

// 1) Отправим текст заявки
$resp1 = tg_api_send('sendMessage', [
  'chat_id'    => $CHAT_ID,
  'text'       => $message,
  'parse_mode' => 'HTML',
]);

if (empty($resp1['ok'])) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Telegram sendMessage failed', 'tg'=>$resp1]); exit;
}

// 2) Подготовим и отправим фото (если есть)
$allowedMime = ['image/jpeg','image/png','image/webp','image/heic','image/heif'];
$maxSize     = 10 * 1024 * 1024; // 10 MB
$fieldsMap   = [
  'edge'  => 'Торец',
  'front' => 'Вид спереди',
  'back'  => 'Вид сзади',
];

$tempFiles = [];    // локальные пути к файлам
$mediaJson = [];    // описание для sendMediaGroup
$attachIdx = 1;

foreach ($fieldsMap as $field => $label) {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) continue;

  $f = $_FILES[$field];
  if ($f['size'] <= 0 || $f['size'] > $maxSize) continue;

  // иногда MIME пуст; позволим по расширению
  $mime = mime_content_type($f['tmp_name']) ?: $f['type'];
  if ($mime && !in_array($mime, $allowedMime, true)) {
    // допускаем неизвестные типы как изображение — Telegram сам поймёт
  }

  // Перенесём во временный файл (безопаснее)
  $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
  $ext = $ext ? preg_replace('~[^a-z0-9]+~i', '', $ext) : 'jpg';
  $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_', true) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], $dest)) continue;

  $attachName = "file{$attachIdx}";
  $tempFiles[$attachName] = $dest;

  // В подписи оставим краткое имя (только на первом фото можно caption; сделаем без капшенов для стабильности)
  $mediaJson[] = [
    'type'  => 'photo',
    'media' => "attach://{$attachName}",
  ];
  $attachIdx++;
}

// Если есть фото — отправим альбомом
if (!empty($mediaJson)) {
  $params = [
    'chat_id' => $CHAT_ID,
    'media'   => json_encode($mediaJson, JSON_UNESCAPED_UNICODE),
  ];
  $resp2 = tg_api_send('sendMediaGroup', $params, $tempFiles);

  // подчистим временные файлы
  foreach ($tempFiles as $p) { if (is_file($p)) @unlink($p); }

  if (empty($resp2['ok'])) {
    // fallback: попробуем по одному фото, чтобы не потерять вложения
    $okAny = false;
    foreach ($tempFiles as $k => $p) {
      $r = tg_api_send('sendPhoto', [
        'chat_id' => $CHAT_ID,
        'photo'   => new CURLFile($p),
      ]);
      if (!empty($r['ok'])) $okAny = true;
      if (is_file($p)) @unlink($p);
    }
    if (!$okAny) {
      http_response_code(500);
      echo json_encode(['ok'=>false, 'error'=>'Telegram sendMediaGroup/sendPhoto failed']); exit;
    }
  }
}

// Успех
echo json_encode(['ok'=>true]);
