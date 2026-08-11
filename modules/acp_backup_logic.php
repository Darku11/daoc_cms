<?php
if (!defined('IN_ACP')) exit;

if (!isset($userPriv) || $userPriv < 4) {
    header("Location: index.php");
    exit;
}

$backup_dir = __DIR__ . '/../backups/';

if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
}
if (!file_exists($backup_dir . '.htaccess')) {
    @file_put_contents($backup_dir . '.htaccess', "Order Allow,Deny\nDeny from all\n<Files *>\nRequire all denied\n</Files>");
}
if (!file_exists($backup_dir . 'index.html')) {
    @file_put_contents($backup_dir . 'index.html', "");
}

$meta_file = $backup_dir . 'metadata.json';

$backup_settings = [
    'backup_active' => '0',
    'backup_interval' => '86400',
    'backup_format' => '7z',
    'backup_7z_path' => 'C:\\Program Files\\7-Zip\\7z.exe',
    'backup_mysqldump_path' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'backup_mysql_path' => 'C:\\xampp\\mysql\\bin\\mysql.exe',
    'backup_last_run' => '0'
];

try {
    $stmt = $db->query("SELECT setting_key, value FROM settings WHERE setting_key LIKE 'backup_%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $backup_settings[$row['setting_key']] = $row['value'];
    }
} catch (\Throwable $e) {}

$metadata = [];
if (file_exists($meta_file)) {
    $metadata = json_decode(file_get_contents($meta_file), true) ?? [];
}

function verify_backup_file($file, $backup_dir, $exe_7z) {
    $has_sql = false;
    $intact = false;
    $has_manifest = false;
    $size_ok = (filesize($backup_dir . $file) > 10240);

    if (str_ends_with($file, '.zip') && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($backup_dir . $file) === true) {
            $intact = true;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_contains($name, '.sql')) $has_sql = true;
                if (str_contains($name, 'index.php') || str_contains($name, 'modules/')) $has_manifest = true;
            }
            $zip->close();
        }
    } else {
        if (!empty($exe_7z) && file_exists($exe_7z)) {
            exec(sprintf('"%s" t "%s"', $exe_7z, $backup_dir . $file), $out, $return_code);
            if ($return_code === 0) $intact = true;
            exec(sprintf('"%s" l "%s"', $exe_7z, $backup_dir . $file), $out_l);
            foreach ($out_l as $line) {
                if (str_contains($line, '.sql')) $has_sql = true;
                if (str_contains($line, 'index.php') || str_contains($line, 'modules/')) $has_manifest = true;
            }
        }
    }

    if ($intact && $has_sql && $has_manifest && $size_ok) {
        return 'valid';
    } elseif ($intact && ($has_sql || $has_manifest)) {
        return 'warning';
    } else {
        return 'invalid';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkToken($_POST['csrf_token'] ?? '');

    if (isset($_POST['save_settings'])) {
        $active = isset($_POST['backup_active']) ? '1' : '0';
        $interval = (int)($_POST['backup_interval'] ?? 86400);
        $format = ($_POST['backup_format'] === 'zip') ? 'zip' : '7z';
        $path_7z = trim($_POST['backup_7z_path'] ?? '');
        $path_mysql = trim($_POST['backup_mysqldump_path'] ?? '');
        $path_my = trim($_POST['backup_mysql_path'] ?? '');

        $upd = $db->prepare("INSERT INTO settings (setting_key, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $upd->execute(['backup_active', $active]);
        $upd->execute(['backup_interval', $interval]);
        $upd->execute(['backup_format', $format]);
        $upd->execute(['backup_7z_path', $path_7z]);
        $upd->execute(['backup_mysqldump_path', $path_mysql]);
        $upd->execute(['backup_mysql_path', $path_my]);

        header("Location: acp.php?s=backup&msg=settings_saved");
        exit;
    }

    if (isset($_POST['update_meta'])) {
        $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['file'] ?? '');
        if (!empty($file) && file_exists($backup_dir . $file)) {
            if (!isset($metadata[$file])) {
                $metadata[$file] = ['comment' => '', 'pinned' => 0, 'status' => 'unknown'];
            }
            $metadata[$file]['comment'] = trim($_POST['comment'] ?? '');
            $metadata[$file]['pinned'] = isset($_POST['pinned']) ? 1 : 0;
            file_put_contents($meta_file, json_encode($metadata));
            header("Location: acp.php?s=backup&msg=meta_updated");
            exit;
        }
        header("Location: acp.php?s=backup&err=file_not_found");
        exit;
    }

    if (isset($_POST['delete_backup'])) {
        $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['file'] ?? '');
        if (!empty($file) && file_exists($backup_dir . $file)) {
            if (!empty($metadata[$file]['pinned'])) {
                header("Location: acp.php?s=backup&err=file_pinned");
                exit;
            }
            @unlink($backup_dir . $file);
            unset($metadata[$file]);
            file_put_contents($meta_file, json_encode($metadata));
            header("Location: acp.php?s=backup&msg=deleted");
            exit;
        }
        header("Location: acp.php?s=backup&err=file_not_found");
        exit;
    }

    if (isset($_POST['create_backup'])) {
        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            header("Location: acp.php?s=backup&err=dir_not_writable");
            exit;
        }

        $format = $backup_settings['backup_format'];
        $exe_7z = $backup_settings['backup_7z_path'];
        $exe_mysql = $backup_settings['backup_mysqldump_path'];

        if ($format === '7z' && !file_exists($exe_7z)) {
            header("Location: acp.php?s=backup&err=exe_7z_missing");
            exit;
        }

        $timestamp = date('Ymd_His');
        $sql_dump = $backup_dir . 'db_dump_' . $timestamp . '.sql';
        $cms_sql_dump = $backup_dir . 'cms_db_dump_' . $timestamp . '.sql';
        $ext = ($format === 'zip') ? '.zip' : '.7z';
        $archive_out = $backup_dir . 'backup_' . $timestamp . $ext;

        $db_host = $GLOBALS['cms_settings']['db_host'] ?? '127.0.0.1';
        $db_user = $GLOBALS['cms_settings']['db_user'] ?? '';
        $db_pass = $GLOBALS['cms_settings']['db_pass'] ?? '';
        $db_name = $GLOBALS['cms_settings']['db_name'] ?? '';

        if (file_exists($exe_mysql)) {
            // DOL Server DB Dump
            if (!empty($db_name)) {
                $cmd_mysql = sprintf(
                    '"%s" --host=%s --user=%s --password=%s %s > "%s"',
                    $exe_mysql,
                    escapeshellarg($db_host),
                    escapeshellarg($db_user),
                    escapeshellarg($db_pass),
                    escapeshellarg($db_name),
                    $sql_dump
                );
                exec($cmd_mysql);
            }
            
            // CMS Server DB Dump
            if (defined('DB_NAME') && !empty(DB_NAME)) {
                $cmd_cms_mysql = sprintf(
                    '"%s" --host=%s --user=%s --password=%s %s > "%s"',
                    $exe_mysql,
                    escapeshellarg(DB_HOST),
                    escapeshellarg(DB_USER),
                    escapeshellarg(DB_PASS),
                    escapeshellarg(DB_NAME),
                    $cms_sql_dump
                );
                exec($cmd_cms_mysql);
            }
        }

        $root_dir = realpath(__DIR__ . '/../');

        if ($format === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($archive_out, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                if (file_exists($sql_dump)) {
                    $zip->addFile($sql_dump, 'backups/' . basename($sql_dump));
                }
                if (file_exists($cms_sql_dump)) {
                    $zip->addFile($cms_sql_dump, 'backups/' . basename($cms_sql_dump));
                }
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($root_dir) + 1);
                        $relativePath = str_replace('\\', '/', $relativePath);
                        if (str_starts_with($relativePath, 'backups/')) continue;
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                $zip->close();
            }
        } else {
            $type_param = ($format === 'zip') ? '-tzip' : '-t7z';
            $cmd_7z = sprintf(
                '"%s" a %s "%s" "%s" -xr!backups',
                $exe_7z,
                $type_param,
                $archive_out,
                $root_dir . DIRECTORY_SEPARATOR . '*'
            );
            exec($cmd_7z);
            
            // Add dumps to the archive explicitly, since -xr!backups excludes them initially
            if (file_exists($sql_dump)) {
                exec(sprintf('"%s" a %s "%s" "%s"', $exe_7z, $type_param, $archive_out, $sql_dump));
            }
            if (file_exists($cms_sql_dump)) {
                exec(sprintf('"%s" a %s "%s" "%s"', $exe_7z, $type_param, $archive_out, $cms_sql_dump));
            }
        }

        if (file_exists($sql_dump)) @unlink($sql_dump);
        if (file_exists($cms_sql_dump)) @unlink($cms_sql_dump);

        if (file_exists($archive_out)) {
            $filename = basename($archive_out);
            $metadata[$filename] = [
                'comment' => '',
                'pinned' => 0,
                'status' => verify_backup_file($filename, $backup_dir, $exe_7z)
            ];
            file_put_contents($meta_file, json_encode($metadata));

            header("Location: acp.php?s=backup&msg=created");
            exit;
        } else {
            header("Location: acp.php?s=backup&err=failed");
            exit;
        }
    }

    if (isset($_POST['verify_backup'])) {
        $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['file'] ?? '');
        if (!empty($file) && file_exists($backup_dir . $file)) {
            $metadata[$file]['status'] = verify_backup_file($file, $backup_dir, $backup_settings['backup_7z_path']);
            file_put_contents($meta_file, json_encode($metadata));
            header("Location: acp.php?s=backup&msg=verified");
            exit;
        }
        header("Location: acp.php?s=backup&err=file_not_found");
        exit;
    }

    if (isset($_POST['restore_backup'])) {
        $file = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['file'] ?? '');
        $mode = $_POST['restore_mode'] ?? 'all';

        if (!empty($file) && file_exists($backup_dir . $file)) {
            @set_time_limit(300);
            $root_dir = realpath(__DIR__ . '/../');
            $exe_7z = $backup_settings['backup_7z_path'];
            $exe_mysql = $backup_settings['backup_mysql_path'];

            $db_host = $GLOBALS['cms_settings']['db_host'] ?? '127.0.0.1';
            $db_user = $GLOBALS['cms_settings']['db_user'] ?? '';
            $db_pass = $GLOBALS['cms_settings']['db_pass'] ?? '';
            $db_name = $GLOBALS['cms_settings']['db_name'] ?? '';

            $restore_db = ($mode === 'all' || $mode === 'db');
            $restore_files = ($mode === 'all' || $mode === 'acp' || $mode === 'dol');

            if (str_ends_with($file, '.zip') && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($backup_dir . $file) === true) {
                    if ($restore_db) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = $zip->getNameIndex($i);
                            if (str_contains($name, '.sql')) {
                                $zip->extractTo($backup_dir, $name);
                                if (file_exists($backup_dir . $name) && file_exists($exe_mysql)) {
                                    if (str_contains($name, 'cms_db_dump')) {
                                        exec(sprintf('"%s" --host=%s --user=%s --password=%s %s < "%s"', $exe_mysql, escapeshellarg(DB_HOST), escapeshellarg(DB_USER), escapeshellarg(DB_PASS), escapeshellarg(DB_NAME), $backup_dir . $name));
                                    } else {
                                        exec(sprintf('"%s" --host=%s --user=%s --password=%s %s < "%s"', $exe_mysql, escapeshellarg($db_host), escapeshellarg($db_user), escapeshellarg($db_pass), escapeshellarg($db_name), $backup_dir . $name));
                                    }
                                    @unlink($backup_dir . $name);
                                }
                            }
                        }
                    }
                    if ($restore_files) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = $zip->getNameIndex($i);
                            if (str_starts_with($name, 'backups/')) continue;
                            if ($mode === 'acp' && !str_starts_with($name, 'modules/') && !str_contains($name, 'acp.php')) continue;
                            if ($mode === 'dol' && !str_starts_with($name, 'dol/')) continue;
                            $zip->extractTo($root_dir, $name);
                        }
                    }
                    $zip->close();
                }
            } else {
                if (file_exists($exe_7z)) {
                    if ($restore_db) {
                        exec(sprintf('"%s" x "%s" -o"%s" *.sql -r -y', $exe_7z, $backup_dir . $file, $backup_dir));
                        $found_sqls = glob($backup_dir . '*.sql');
                        if (!empty($found_sqls) && file_exists($exe_mysql)) {
                            foreach ($found_sqls as $fs) {
                                if (str_contains($fs, 'cms_db_dump')) {
                                    exec(sprintf('"%s" --host=%s --user=%s --password=%s %s < "%s"', $exe_mysql, escapeshellarg(DB_HOST), escapeshellarg(DB_USER), escapeshellarg(DB_PASS), escapeshellarg(DB_NAME), $fs));
                                } else {
                                    exec(sprintf('"%s" --host=%s --user=%s --password=%s %s < "%s"', $exe_mysql, escapeshellarg($db_host), escapeshellarg($db_user), escapeshellarg($db_pass), escapeshellarg($db_name), $fs));
                                }
                                @unlink($fs);
                            }
                        }
                    }
                    if ($restore_files) {
                        if ($mode === 'all') {
                            exec(sprintf('"%s" x "%s" -o"%s" -xr!backups -y', $exe_7z, $backup_dir . $file, $root_dir));
                        } elseif ($mode === 'acp') {
                            exec(sprintf('"%s" x "%s" -o"%s" modules/* acp.php -y', $exe_7z, $backup_dir . $file, $root_dir));
                        } elseif ($mode === 'dol') {
                            exec(sprintf('"%s" x "%s" -o"%s" dol/* -y', $exe_7z, $backup_dir . $file, $root_dir));
                        }
                    }
                }
            }
            header("Location: acp.php?s=backup&msg=restored");
            exit;
        }
        header("Location: acp.php?s=backup&err=file_not_found");
        exit;
    }
}

$backup_files = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $f) {
        if ((str_ends_with($f, '.7z') || str_ends_with($f, '.zip')) && file_exists($backup_dir . $f)) {
            if (!isset($metadata[$f]['status']) || $metadata[$f]['status'] === 'unknown') {
                $metadata[$f]['status'] = verify_backup_file($f, $backup_dir, $backup_settings['backup_7z_path']);
            }
            $backup_files[] = [
                'name' => $f,
                'size' => filesize($backup_dir . $f),
                'date' => filemtime($backup_dir . $f),
                'comment' => $metadata[$f]['comment'] ?? '',
                'pinned' => $metadata[$f]['pinned'] ?? 0,
                'status' => $metadata[$f]['status'] ?? 'warning'
            ];
        }
    }
    file_put_contents($meta_file, json_encode($metadata));
    usort($backup_files, function($a, $b) {
        return $b['date'] <=> $a['date'];
    });
}