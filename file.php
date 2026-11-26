<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';

$id = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['id'] ?? ''));
if (!$id) { http_response_code(400); exit; }

$row = Db::getById($id);
if (!$row || !is_file($row['path'])) { http_response_code(404); exit; }

$settings = pf_load_settings();
if (!pf_path_inside_roots($row['path'], $settings['photo_dirs'])) {
    http_response_code(403); exit;
}

$ext = strtolower(pathinfo($row['path'], PATHINFO_EXTENSION));
header('Content-Type: ' . pf_mime_for_ext($ext));
header('Content-Length: ' . filesize($row['path']));
header('Cache-Control: public, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($row['path']) . '"');
readfile($row['path']);
