<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$nested_extension = __DIR__ . '/spike_admin_nested_extension.php';
if (is_file($nested_extension)) {
    require_once $nested_extension;
}
