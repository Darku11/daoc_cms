<?php
// SPDX-License-Identifier: GPL-3.0-only
// ── parseBBCode ───────────────────────────────────────────────
if (!function_exists('parseBBCode')) {
function parseBBCode(string $text): string {
    // Do not call htmlspecialchars here; Quill already supplies
    // valid HTML. If we escaped it, tags like <strong> would show up
    // as literal text. BBCode tags remain for backward compatibility.

    $search = [
        '/\[b\](.*?)\[\/b\]/is',
        '/\[i\](.*?)\[\/i\]/is',
        '/\[u\](.*?)\[\/u\]/is',
        '/\[s\](.*?)\[\/s\]/is',
        '/\[code\](.*?)\[\/code\]/is',
        '/\[img\](https?:\/\/[^\s\[<>"\']+?\.(?:jpg|jpeg|png|gif|webp))\[\/img\]/is',
        '/\[url\](https?:\/\/[^\s\[<>"\']+?)\[\/url\]/is',
        '/\[url=(https?:\/\/[^\s\]<>"\']+?)\](.*?)\[\/url\]/is',
        '/\[color=([a-zA-Z0-9#]+)\](.*?)\[\/color\]/is',
        '/\[size=([1-7])\](.*?)\[\/size\]/is',
        // [quote] without an author
        '/\[quote\](.*?)\[\/quote\]/is',
        // [quote=author]
        '/\[quote=([^\]]{1,60})\](.*?)\[\/quote\]/is',
    ];

    $replace = [
        '<strong>$1</strong>',
        '<em>$1</em>',
        '<u>$1</u>',
        '<s>$1</s>',
        '<code class="spike-bb-code">$1</code>',
        '<img src="$1" class="spike-bb-img" alt="Image">',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="spike-bb-link">$1</a>',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="spike-bb-link">$2</a>',
        '<span style="color:$1;">$2</span>',
        '<span style="font-size:$1em;">$2</span>',
        '<div class="spike-bb-quote">$1</div>',
        '<div class="spike-bb-quote-author">
            <div class="spike-bb-quote-author-label"><i class="fas fa-quote-left spike-bb-quote-icon"></i>$1</div>
            <div class="spike-bb-quote-text">$2</div>
         </div>',
    ];

    $text = preg_replace($search, $replace, $text);

    // ── @Mention Highlighting ─────────────────────────────────
    // @Username → highlighted in color (no DB query here, purely visual)
    $text = preg_replace_callback(
        '/@([a-zA-Z0-9_\-]{2,30})/',
        function ($m) {
            return '<span class="spike-mention" title="Mention: @' . htmlspecialchars($m[1], ENT_QUOTES) . '">@' . htmlspecialchars($m[1], ENT_QUOTES) . '</span>';
        },
        $text
    );

    // ── Auto-Link (only outside existing <a> tags) ────────
    $parts = preg_split('/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $anchorDepth = 0;
    foreach ($parts as &$part) {
        if ($part === '') continue;
        if ($part[0] === '<') {
            if (preg_match('/^<a\b/i', $part)) $anchorDepth++;
            elseif (preg_match('/^<\/a>/i', $part)) $anchorDepth = max(0, $anchorDepth - 1);
            continue;
        }
        if ($anchorDepth > 0) continue;
        $part = preg_replace_callback(
            '/(https?:\/\/[^\s<>"\']{8,200})/i',
            function ($m) {
                return '<a href="' . htmlspecialchars($m[1], ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" class="spike-bb-link">' . htmlspecialchars($m[1], ENT_QUOTES) . '</a>';
            },
            $part
        );
    }
    unset($part);
    $text = implode('', $parts);

    // ── Intelligent line breaking ───────────────────────────
    // Quill uses <p> and <br>. Only run nl2br() if there's no HTML line break already.
    if (strpos($text, '<p>') === false && strpos($text, '<br') === false) {
        return nl2br($text);
    }

    return $text;
}
}

// ── Replace smilies in text ──────────────────────────────────
if (!function_exists('spike_parse_smilies')) {
function spike_parse_smilies(string $text, array $smilies): string {
    $replacements = [];
    
    foreach ($smilies as $s) {
        $safe_url = ltrim($s['image_url'] ?? '', '/');
        
        // Since the Quill editor converts things like > to &gt;, we need to
        // escape the code exactly as it appears in the HTML string.
        $code_escaped = htmlspecialchars($s['code'], ENT_QUOTES);
        
        $img  = '<img src="' . htmlspecialchars($safe_url, ENT_QUOTES) . '"'
              . ' alt="' . $code_escaped . '"'
              . ' title="' . htmlspecialchars($s['title'] ?? '', ENT_QUOTES) . '"'
              . ' class="spike-smiley">';
              
        // Build the array for strtr (search string => replacement string)
        $replacements[$code_escaped] = $img;
    }
    
    $parts = preg_split('/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => &$part) {
        if ($i % 2 === 0) {
            $part = strtr($part, $replacements);
        }
    }
    
    return implode('', $parts);
}
}

// Fallback for legacy calls (in case it's still used somewhere in the CMS)
if (!function_exists('parseBBCodeWithSmilies')) {
function parseBBCodeWithSmilies(string $text, array $smilies): string {
    $text = parseBBCode($text);
    return spike_parse_smilies($text, $smilies);
}
}

// ── Process attachment upload ───────────────────────────────
// Process uploads before redirecting so failures can be reported without a
// blank page. The helper uses the actual spike_attachments schema
// (id, post_id, user_id, filename, stored_name, filesize, mime_type,
// downloads, created_at).
if (!function_exists('spike_process_attachments')) {
function spike_process_attachments(PDO $db, int $post_id, int $user_id, string $attach_path, string $attach_path_raw, int $max_size, array $allowed_mimes): void {
    if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'])) return;

    $image_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $inline_html = '';

    $files = $_FILES['attachments'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count === 0) return;

    if (!is_dir($attach_path)) {
        @mkdir($attach_path, 0755, true);
        // Prevents uploaded files from being executed inside the upload folder
        if (!file_exists($attach_path . '.htaccess')) {
            @file_put_contents($attach_path . '.htaccess', "php_flag engine off\nAddHandler none .php .php3 .php4 .php5 .phtml\n");
        }
    }
    if (!is_dir($attach_path) || !is_writable($attach_path)) {
        error_log("spike_process_attachments: attach_path is not writable: $attach_path");
        return;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    for ($i = 0; $i < $count; $i++) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) {
            error_log("spike_process_attachments: upload error code $err for file " . ($files['name'][$i] ?? '?'));
            continue;
        }

        $tmp_path = $files['tmp_name'][$i];
        $orig_name = (string)($files['name'][$i] ?? 'file');
        $size = (int)($files['size'][$i] ?? 0);

        if (!is_uploaded_file($tmp_path)) continue;
        if ($size <= 0 || $size > $max_size) {
            error_log("spike_process_attachments: file '$orig_name' exceeds size limit ($size > $max_size)");
            continue;
        }

        // Check the real MIME type from file content, don't trust the (unreliable) client header
        $real_mime = $finfo ? (finfo_file($finfo, $tmp_path) ?: '') : ($files['type'][$i] ?? '');
        if (!in_array($real_mime, $allowed_mimes, true)) {
            error_log("spike_process_attachments: MIME type '$real_mime' for '$orig_name' not allowed");
            continue;
        }

        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        // Block executable extensions, regardless of what the detected MIME type says
        if (in_array($ext, ['php','php3','php4','php5','phtml','phar','cgi','pl','py','sh','exe','js'], true)) {
            error_log("spike_process_attachments: file extension '$ext' for '$orig_name' not allowed");
            continue;
        }

        $stored_name = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        $dest = rtrim($attach_path, '/') . '/' . $stored_name;

        if (!move_uploaded_file($tmp_path, $dest)) {
            error_log("spike_process_attachments: move_uploaded_file failed for '$orig_name'");
            continue;
        }
        @chmod($dest, 0644);

        // Images are embedded inline in the post - only non-image files (archives,
        // documents, ...) become a spike_attachments entry with a download counter.
        if (in_array($real_mime, $image_mimes, true)) {
            $web_path = rtrim($attach_path_raw, '/') . '/' . $stored_name;
            $inline_html .= '<img src="' . htmlspecialchars($web_path, ENT_QUOTES) . '" alt="' . htmlspecialchars($orig_name, ENT_QUOTES) . '">';
            continue;
        }

        try {
            $db->prepare("
                INSERT INTO spike_attachments (post_id, user_id, filename, stored_name, filesize, mime_type, downloads)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            ")->execute([$post_id, $user_id, $orig_name, $stored_name, $size, $real_mime]);
        } catch (\Throwable $e) {
            error_log("spike_process_attachments: database insert failed: " . $e->getMessage());
            @unlink($dest);
        }
    }

    if ($inline_html !== '') {
        try {
            $db->prepare("UPDATE spike_posts SET content = CONCAT(content, ?) WHERE id = ?")
               ->execute([$inline_html, $post_id]);
        } catch (\Throwable $e) {
            error_log("spike_process_attachments: inline image content update failed: " . $e->getMessage());
        }
    }

    if ($finfo) finfo_close($finfo);
}
}

// ── AJAX: Load edit history ──────────────────────────────────
// Called from viewthread_logic.php as an AJAX handler.
// Wiring: if ($ajax === 'get_edit_history') spike_ajax_edit_history($db);
if (!function_exists('spike_ajax_edit_history')) {
function spike_ajax_edit_history(PDO $db): void {
    header('Content-Type: application/json');
    $myPriv  = (int)($_SESSION['priv_level'] ?? 0);
    $myId    = (int)($_SESSION['user_id']    ?? 0);
    $post_id = (int)($_POST['post_id']       ?? 0);

    if ($post_id <= 0) { echo json_encode(['history' => []]); exit; }

    // Only the author themselves (priv 1+) or staff (priv 2+) may view the history
    if ($myId <= 0 || $myPriv < 1) { echo json_encode(['error' => 'unauthorized']); exit; }

    // Check if the user is the author
    $stmt_chk = $db->prepare("SELECT author_id FROM spike_posts WHERE id = ? LIMIT 1");
    $stmt_chk->execute([$post_id]);
    $post_row = $stmt_chk->fetch();

    $is_author = $post_row && ((int)$post_row['author_id'] === $myId);
    $is_staff  = $myPriv >= 2;

    if (!$is_author && !$is_staff) { echo json_encode(['error' => 'unauthorized']); exit; }

    try {
        $stmt = $db->prepare("
            SELECT e.created_at, e.edit_reason,
                   u.username as editor
            FROM spike_post_edits e
            LEFT JOIN users u ON e.editor_id = u.id
            WHERE e.post_id = ?
            ORDER BY e.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$post_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = array_map(fn($r) => [
            'date'   => date("d.m.Y H:i", strtotime($r['created_at'])),
            'editor' => $r['editor'] ?? 'Unknown',
            'reason' => $r['edit_reason'] ?? '',
        ], $rows);

        echo json_encode(['history' => $history]);
    } catch (\Throwable $e) {
        error_log("Edit history AJAX error: " . $e->getMessage());
        echo json_encode(['history' => []]);
    }
    exit;
}
}
