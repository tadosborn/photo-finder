<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Thumb.php';

$id = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['id'] ?? ''));
if (!$id) { http_response_code(400); exit; }

$row = Db::getById($id);
if (!$row || !is_file($row['path'])) { http_response_code(404); exit; }

$settings = pf_load_settings();
if (!pf_path_inside_roots($row['path'], $settings['photo_dirs'])) {
    http_response_code(403); exit;
}

$thumb = Thumb::ensureThumb($id, $row['path']);
if (!$thumb) { http_response_code(500); exit; }

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
readfile($thumb);
