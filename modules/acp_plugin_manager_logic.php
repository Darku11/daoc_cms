<?php
if (!defined('IN_CMS')) exit;
if ((int)($userPriv ?? 0) < 5) {
    header("Location: index.php?p=home"); exit;
}

$pm_action = $_POST['pm_action'] ?? '';
$pm_msg    = '';
$pm_error  = '';

const PM_RESERVED_SLUGS = [
    'home', 'login', 'logout', 'register', 'profile', 'rules',
    'spike', 'viewboard', 'viewthread', 'newthread', 'editpost',
    'herald', 'rvr_map', 'warmap', 'faq', 'team', 'pve',
    'pve_bestiary', 'pve_dungeons', 'pve_quests', 'notifications',
    'realmwar_json', 'general_settings', 'um', 'admin_log',
];

if (!interface_exists('PluginInterface')) {
    interface PluginInterface {
        public static function getMetadata(): array;
        public function __construct(\PDO $db, int $userPriv, int $currentUserId);
        public function initialize(): void;
        public function registerHooks(): void;
        public function render(): string;
        public function uninstall(): bool;
    }
}

if ($pm_action === 'install' && isset($_FILES['plugin_file'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $file     = $_FILES['plugin_file'];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($ext !== 'php') {
        $pm_error = t('pm_err_php_only', [], 'Only .php files are allowed.');
    } elseif ($file['size'] > 512000) {
        $pm_error = t('pm_err_too_large', [], 'File too large (max 512 KB).');
    } elseif (!preg_match('/^[A-Za-z0-9_]+Plugin\.php$/', $origName)) {
        $pm_error = t('pm_err_naming', [], 'Filename must match the pattern "NamePlugin.php".');
    } else {
        $tmpContent = file_get_contents($file['tmp_name']);

        if (strpos($tmpContent, 'getMetadata') === false || strpos($tmpContent, 'implements PluginInterface') === false) {
            $pm_error = t('pm_err_interface', [], 'Plugin does not implement PluginInterface correctly.');
        } else {
            $tmpValidatePath = sys_get_temp_dir() . '/' . uniqid('pm_v_') . '_' . $origName;
            file_put_contents($tmpValidatePath, $tmpContent);
            $className = pathinfo($origName, PATHINFO_FILENAME);
            $loadError = null;

            $requiredMethods = ['getMetadata', '__construct', 'initialize', 'registerHooks', 'uninstall', 'render'];
            $missingMethods  = [];
            foreach ($requiredMethods as $m) {
                if (!preg_match('/function\s+' . preg_quote($m, '/') . '\s*\(/i', $tmpContent)) {
                    $missingMethods[] = $m;
                }
            }

            if (!empty($missingMethods)) {
                $pm_error = t('pm_err_missing_methods', ['methods' => implode(', ', $missingMethods)], 'Plugin is missing required methods: ' . implode(', ', $missingMethods) . '. File was NOT loaded.');
            } else {
                try {
                    if (!class_exists($className)) require_once $tmpValidatePath;
                } catch (\Throwable $e) {
                    $loadError = $e->getMessage();
                }
            }
            @unlink($tmpValidatePath);

            if ($pm_error !== '') {
            } elseif ($loadError) {
                $pm_error = t('pm_err_load', [], 'Plugin error while loading: ') . h($loadError);
            } elseif (!class_exists($className)) {
                $pm_error = t('pm_err_class_not_found', ['class' => $className], "Class '$className' not found.");
            } elseif (!in_array('PluginInterface', class_implements($className) ?: [], true)) {
                $pm_error = t('pm_err_interface_missing', [], 'Plugin does not implement PluginInterface.');
            } else {
                $meta = array_merge([
                    'name' => $origName, 'slug' => '', 'version' => '?',
                    'author' => '?', 'website' => '', 'description' => '',
                    'dependencies' => [], 'min_priv' => 0,
                ], $className::getMetadata());

                $slugFromFile = strtolower(preg_replace(
                    ['/Plugin$/', '/([a-z])([A-Z])/', '/[^a-zA-Z0-9]+/'],
                    ['', '$1_$2', '_'], $className
                ));
                $meta['slug'] = trim((string)($meta['slug'] ?? ''));
                if ($meta['slug'] === '' || !preg_match('/^[a-z0-9_]+$/', $meta['slug'])) {
                    $meta['slug'] = trim($slugFromFile, '_');
                }
                if (str_starts_with($meta['slug'], 'theme_')) {
                    $meta['slug'] = 'plugin_' . $meta['slug'];
                }

                if (in_array($meta['slug'], PM_RESERVED_SLUGS, true)) {
                    $pm_error = t('pm_err_slug_reserved', ['slug' => $meta['slug']], "Slug '{$meta['slug']}' is reserved.");
                } else {
                    $targetPath = __DIR__ . '/../plugins/' . $origName;
                    move_uploaded_file($file['tmp_name'], $targetPath);

                    $db->prepare("
                        INSERT INTO aldhran_plugins
                            (slug,name,version,author,website,description,dependencies,min_priv,filename,filesize,installed_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            name=VALUES(name),version=VALUES(version),author=VALUES(author),
                            website=VALUES(website),description=VALUES(description),
                            dependencies=VALUES(dependencies),min_priv=VALUES(min_priv),
                            filename=VALUES(filename),filesize=VALUES(filesize),
                            installed_at=NOW(),installed_by=VALUES(installed_by)
                    ")->execute([
                        $meta['slug'],$meta['name'],$meta['version'],$meta['author'],
                        $meta['website'],$meta['description'],json_encode($meta['dependencies']),
                        (int)$meta['min_priv'],$origName,(int)$file['size'],$currentUserId,
                    ]);

                    aldhran_log('PLUGIN_INSTALL', "Plugin '{$meta['name']}' v{$meta['version']} installed", $currentUserId);
                    $pm_msg = t('pm_msg_installed', ['name' => $meta['name']], "Plugin '" . h($meta['name']) . "' installed successfully.");
                }
            }
        }
    }
}

if ($pm_action === 'delete') {
    checkToken($_POST['csrf_token'] ?? '');
    $slug = preg_replace('/[^a-z0-9_]/', '', $_POST['plugin_slug'] ?? '');
    $row  = $db->prepare("SELECT filename, name FROM aldhran_plugins WHERE slug = ?");
    $row->execute([$slug]);
    $plugin_row = $row->fetch();

    if ($plugin_row) {
        $filePath     = __DIR__ . '/../plugins/' . $plugin_row['filename'];
        $uninstall_ok = true;

        if (file_exists($filePath)) {
            try {
                if (!interface_exists('PluginInterface')) require_once __DIR__ . '/../includes/cms_hooks.php';
                require_once $filePath;
                $className = pathinfo($plugin_row['filename'], PATHINFO_FILENAME);
                if (class_exists($className)) {
                    $instance = new $className($db, $userPriv, $currentUserId);
                    if (method_exists($instance, 'uninstall')) $uninstall_ok = $instance->uninstall();
                }
            } catch (\Throwable $e) {
                error_log("Plugin uninstall() [{$slug}]: " . $e->getMessage());
                $uninstall_ok = false;
            }
        }

        if (!$uninstall_ok) {
            $pm_error = t('pm_err_uninstall_failed', ['name' => $plugin_row['name']], "Plugin '" . h($plugin_row['name']) . "' could not be uninstalled cleanly.");
        } else {
            if (file_exists($filePath)) @unlink($filePath);
            $db->prepare("DELETE FROM aldhran_plugins WHERE slug = ?")->execute([$slug]);
            aldhran_log('PLUGIN_DELETE', "Plugin '{$plugin_row['name']}' deleted", $currentUserId);
            $pm_msg = t('pm_msg_uninstalled', ['name' => $plugin_row['name']], "Plugin '" . h($plugin_row['name']) . "' uninstalled successfully.");
        }
    } else {
        $pm_error = t('pm_err_not_found', [], 'Plugin not found.');
    }
}

if ($pm_action === 'toggle') {
    checkToken($_POST['csrf_token'] ?? '');
    $slug = preg_replace('/[^a-z0-9_]/', '', $_POST['plugin_slug'] ?? '');
    $db->prepare("UPDATE aldhran_plugins SET is_active = NOT is_active WHERE slug = ?")->execute([$slug]);
    header("Location: acp.php?s=plugin_manager"); exit;
}

$plugins = $db->query("
    SELECT * FROM aldhran_plugins
    WHERE slug NOT LIKE 'theme_%'
    ORDER BY installed_at DESC
")->fetchAll();