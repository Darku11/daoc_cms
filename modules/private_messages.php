<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

class CorePrivateMessages
{
    private \PDO $db;
    private int  $userPriv;
    private int  $currentUserId;

    public function __construct(\PDO $db, int $userPriv, int $currentUserId)
    {
        $this->db            = $db;
        $this->userPriv      = $userPriv;
        $this->currentUserId = $currentUserId;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `pm_messages` (
                    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `sender_id`   INT UNSIGNED NOT NULL,
                    `receiver_id` INT UNSIGNED NOT NULL,
                    `subject`     VARCHAR(200) NOT NULL DEFAULT '(no subject)',
                    `body`        TEXT NOT NULL,
                    `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
                    `deleted_by_sender`   TINYINT(1) NOT NULL DEFAULT 0,
                    `deleted_by_receiver` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_receiver (`receiver_id`, `is_read`),
                    INDEX idx_sender   (`sender_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Exception $e) {
            error_log("CorePrivateMessages: ensureTable failed: " . $e->getMessage());
        }
    }

    public function render(): string
    {
        if ($this->currentUserId <= 0) {
            return '<div class="um-nexus-wrapper"><p class="pm-not-logged-in">Please log in to use Private Messages.</p></div>';
        }

        $is_verified = (int)($_SESSION['is_verified'] ?? 0);
        if ($is_verified === 0) {
            return '<div class="um-nexus-wrapper pm-verify-required">
                <i class="fas fa-envelope-open-text pm-verify-icon"></i>
                <h2 class="pm-verify-title">' . t('pm_verification_req_title', [], 'Verification Required') . '</h2>
                <p class="pm-verify-desc">' . t('pm_verification_req_desc', [], 'You must verify your email address to read and send private messages.') . '</p>
            </div>';
        }

        $action = $_GET['pm_action'] ?? 'inbox';
        $output = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_send'])) {
            $output .= $this->handleSend();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_delete'])) {
            $output .= $this->handleDelete();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_recall'])) {
            $output .= $this->handleRecall();
        }

        switch ($action) {
            case 'send':  $output .= $this->renderCompose(); break;
            case 'sent':  $output .= $this->renderSent();    break;
            case 'read':  $output .= $this->renderRead();     break;
            default:      $output .= $this->renderInbox();    break;
        }

        return $output;
    }

    private function handleSend(): string
    {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            return $this->alert('error', 'Invalid request.');
        }

        $to      = trim($_POST['pm_to']      ?? '');
        $subject = trim($_POST['pm_subject'] ?? '(no subject)');
        $body    = trim($_POST['pm_body']    ?? '');

        if (empty($to) || empty($body)) {
            return $this->alert('error', 'Recipient and message body are required.');
        }

        $stmt = $this->db->prepare("SELECT id, username, priv_level FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$to]);
        $receiver = $stmt->fetch();

        if (!$receiver) {
            return $this->alert('error', "User '$to' not found.");
        }
        if ((int)$receiver['id'] === $this->currentUserId) {
            return $this->alert('error', 'You cannot send a message to yourself.');
        }

        $myStanding = (int)($_SESSION['standing'] ?? 0);
        if ($myStanding >= 2 && (int)$receiver['priv_level'] < 3) {
            return $this->alert('error', 'Due to your account standing, you can only message staff members.');
        }

        if ($this->userPriv < 2) {
            $stmtBlock = $this->db->prepare("SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmtBlock->execute([(int)$receiver['id'], $this->currentUserId]);
            if ($stmtBlock->fetchColumn()) {
                return $this->alert('error', 'You cannot send messages to this user.');
            }
        }

        $stmtLast = $this->db->prepare("
            SELECT created_at FROM pm_messages
            WHERE sender_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmtLast->execute([$this->currentUserId]);
        $last = $stmtLast->fetch();
        if ($last && (time() - strtotime($last['created_at'])) < 10) {
            return $this->alert('error', 'Please wait a moment before sending another message.');
        }

        $subject = mb_substr($subject, 0, 200);
        $body    = mb_substr($body, 0, 5000);

        $this->db->prepare("
            INSERT INTO pm_messages (sender_id, receiver_id, subject, body)
            VALUES (?, ?, ?, ?)
        ")->execute([$this->currentUserId, (int)$receiver['id'], $subject, $body]);

        return $this->alert('success', 'Message sent to ' . h($receiver['username']) . '.');
    }

    private function handleDelete(): string
    {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            return $this->alert('error', 'Invalid request.');
        }

        $msgId   = (int)($_POST['pm_id']   ?? 0);
        $context = $_POST['pm_context'] ?? 'inbox';

        if ($msgId <= 0) return '';

        if ($context === 'sent') {
            $this->db->prepare("UPDATE pm_messages SET deleted_by_sender = 1 WHERE id = ? AND sender_id = ?")->execute([$msgId, $this->currentUserId]);
        } else {
            $this->db->prepare("UPDATE pm_messages SET deleted_by_receiver = 1 WHERE id = ? AND receiver_id = ?")->execute([$msgId, $this->currentUserId]);
        }

        $this->db->exec("DELETE FROM pm_messages WHERE deleted_by_sender = 1 AND deleted_by_receiver = 1");

        return $this->alert('success', 'Message deleted.');
    }

    private function handleRecall(): string
    {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            return $this->alert('error', 'Invalid request.');
        }

        $msgId = (int)($_POST['pm_id'] ?? 0);
        if ($msgId <= 0) return '';

        $stmt = $this->db->prepare("SELECT sender_id, is_read FROM pm_messages WHERE id = ? LIMIT 1");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch();

        if (!$msg || (int)$msg['sender_id'] !== $this->currentUserId) {
            return $this->alert('error', 'You cannot recall this message.');
        }
        if ((int)$msg['is_read'] === 1) {
            return $this->alert('error', 'This message has already been read and cannot be recalled.');
        }

        $this->db->prepare("DELETE FROM pm_messages WHERE id = ? AND sender_id = ?")->execute([$msgId, $this->currentUserId]);

        return $this->alert('success', 'Message recalled.');
    }

    private function renderInbox(): string
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.username AS sender_name
            FROM pm_messages m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE m.receiver_id = ? AND m.deleted_by_receiver = 0
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$this->currentUserId]);
        $messages = $stmt->fetchAll();

        $rows = '';
        foreach ($messages as $msg) {
            $unread_class   = !$msg['is_read'] ? 'pm-row--unread' : '';
            $subject_class  = !$msg['is_read'] ? 'pm-subject--unread' : 'pm-subject--read';

            $rows .= '
                <div class="pm-row ' . $unread_class . '">
                    <div class="pm-row-content">
                        <a href="?p=private_messages&pm_action=read&id=' . (int)$msg['id'] . '" class="pm-row-link">
                            <div class="' . $subject_class . '">' . h($msg['subject']) . '</div>
                            <div class="pm-row-meta">
                                From: <span class="pm-row-sender">' . h($msg['sender_name'] ?? 'Unknown') . '</span>
                                &nbsp;·&nbsp; ' . date('d.m.Y H:i', strtotime($msg['created_at'])) . '
                            </div>
                        </a>
                    </div>
                    <form method="POST" action="?p=private_messages&pm_action=inbox" class="pm-delete-form">
                        <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                        <input type="hidden" name="pm_delete" value="1">
                        <input type="hidden" name="pm_id" value="' . (int)$msg['id'] . '">
                        <input type="hidden" name="pm_context" value="inbox">
                        <button type="submit" onclick="return confirm(\'Delete this message?\')" class="pm-delete-btn">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </form>
                </div>
            ';
        }

        if (empty($messages)) {
            $rows = '<div class="pm-empty">YOUR INBOX IS EMPTY</div>';
        }

        return $this->wrapLayout('Inbox', $rows, 'inbox');
    }

    private function renderSent(): string
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.username AS receiver_name
            FROM pm_messages m
            LEFT JOIN users u ON m.receiver_id = u.id
            WHERE m.sender_id = ? AND m.deleted_by_sender = 0
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$this->currentUserId]);
        $messages = $stmt->fetchAll();

        $rows = '';
        foreach ($messages as $msg) {
            $read_status = $msg['is_read']
                ? '<span class="pm-read-badge pm-read-badge--read">Read</span>'
                : '<span class="pm-read-badge pm-read-badge--unread">Unread</span>';

            $rows .= '
                <div class="pm-row">
                    <div class="pm-row-content">
                        <div class="pm-subject--read">' . h($msg['subject']) . '</div>
                        <div class="pm-row-meta">
                            To: <span class="pm-row-sender">' . h($msg['receiver_name'] ?? 'Unknown') . '</span>
                            &nbsp;·&nbsp; ' . date('d.m.Y H:i', strtotime($msg['created_at'])) . '
                            &nbsp;·&nbsp; ' . $read_status . '
                        </div>
                    </div>
                    <form method="POST" action="?p=private_messages&pm_action=sent" class="pm-delete-form">
                        <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                        <input type="hidden" name="pm_delete" value="1">
                        <input type="hidden" name="pm_id" value="' . (int)$msg['id'] . '">
                        <input type="hidden" name="pm_context" value="sent">
                        <button type="submit" onclick="return confirm(\'Delete this message?\')" class="pm-delete-btn">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </form>' . (!$msg['is_read'] ? '
                    <form method="POST" action="?p=private_messages&pm_action=sent" class="pm-delete-form">
                        <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                        <input type="hidden" name="pm_recall" value="1">
                        <input type="hidden" name="pm_id" value="' . (int)$msg['id'] . '">
                        <button type="submit" onclick="return confirm(\'Recall this message? It will be removed for the recipient.\')" class="pm-recall-btn" title="Recall">
                            <i class="fas fa-undo"></i>
                        </button>
                    </form>' : '') . '
                </div>
            ';
        }

        if (empty($messages)) {
            $rows = '<div class="pm-empty">NO SENT MESSAGES</div>';
        }

        return $this->wrapLayout('Sent', $rows, 'sent');
    }

    private function renderRead(): string
    {
        $msgId = (int)($_GET['id'] ?? 0);
        if ($msgId <= 0) {
            return $this->wrapLayout('Read', $this->alert('error', 'Invalid message.'), 'inbox');
        }

        $stmt = $this->db->prepare("
            SELECT m.*,
                   s.username AS sender_name,
                   r.username AS receiver_name
            FROM pm_messages m
            LEFT JOIN users s ON m.sender_id   = s.id
            LEFT JOIN users r ON m.receiver_id = r.id
            WHERE m.id = ?
              AND (m.receiver_id = ? OR m.sender_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$msgId, $this->currentUserId, $this->currentUserId]);
        $msg = $stmt->fetch();

        if (!$msg) {
            return $this->wrapLayout('Read', $this->alert('error', 'Message not found.'), 'inbox');
        }

        if ((int)$msg['receiver_id'] === $this->currentUserId && !$msg['is_read']) {
            $this->db->prepare("UPDATE pm_messages SET is_read = 1 WHERE id = ?")->execute([$msgId]);
        }

        $isSender     = ((int)$msg['sender_id'] === $this->currentUserId);
        $replyTo      = h($isSender ? ($msg['receiver_name'] ?? '') : ($msg['sender_name'] ?? ''));
        $replySubject = 'Re: ' . h($msg['subject']);

        $content = '
            <div class="pm-read-box">
                <div class="pm-read-header">
                    <div>
                        <div class="pm-read-subject">' . h($msg['subject']) . '</div>
                        <div class="pm-read-from">
                            From: <span class="pm-read-name">' . h($msg['sender_name'] ?? 'Unknown') . '</span>
                            &nbsp;→&nbsp;
                            To: <span class="pm-read-name">' . h($msg['receiver_name'] ?? 'Unknown') . '</span>
                            &nbsp;·&nbsp; ' . date('d.m.Y H:i', strtotime($msg['created_at'])) . '
                        </div>
                    </div>
                    <div class="pm-read-actions">
                        <form method="POST" action="?p=private_messages&pm_action=inbox" class="pm-delete-form">
                            <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                            <input type="hidden" name="pm_delete" value="1">
                            <input type="hidden" name="pm_id" value="' . $msgId . '">
                            <input type="hidden" name="pm_context" value="' . ($isSender ? 'sent' : 'inbox') . '">
                            <button type="submit" onclick="return confirm(\'Delete this message?\')" class="pm-action-btn pm-action-btn--delete">
                                <i class="fas fa-trash-alt"></i> DELETE
                            </button>
                        </form>' . ($isSender && !$msg['is_read'] ? '
                        <form method="POST" action="?p=private_messages&pm_action=sent" class="pm-delete-form">
                            <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                            <input type="hidden" name="pm_recall" value="1">
                            <input type="hidden" name="pm_id" value="' . $msgId . '">
                            <button type="submit" onclick="return confirm(\'Recall this message? It will be removed for the recipient.\')" class="pm-action-btn pm-action-btn--recall">
                                <i class="fas fa-undo"></i> RECALL
                            </button>
                        </form>' : '') . '
                    </div>
                </div>
                <div class="pm-read-body">' . h($msg['body']) . '</div>
            </div>

            <div class="pm-reply-box">
                <div class="pm-reply-title">REPLY</div>
                <form method="POST" action="?p=private_messages&pm_action=send">
                    <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                    <input type="hidden" name="pm_send" value="1">
                    <input type="hidden" name="pm_to" value="' . $replyTo . '">
                    <input type="hidden" name="pm_subject" value="' . $replySubject . '">
                    <textarea name="pm_body" rows="4" required class="pm-textarea" placeholder="Write your reply..."></textarea>
                    <button type="submit" class="pm-send-btn">
                        <i class="fas fa-reply"></i> SEND REPLY
                    </button>
                </form>
            </div>
        ';

        return $this->wrapLayout('Read Message', $content, 'inbox');
    }

    private function renderCompose(): string
    {
        $prefillTo      = h($_GET['to'] ?? '');
        $prefillSubject = h($_GET['subject'] ?? '');

        $content = '
            <div class="pm-compose-box">
                <form method="POST" action="?p=private_messages&pm_action=send">
                    <input type="hidden" name="csrf_token" value="' . generateToken() . '">
                    <input type="hidden" name="pm_send" value="1">

                    <div class="pm-field">
                        <label class="pm-label">TO (Username)</label>
                        <input type="text" name="pm_to" value="' . $prefillTo . '" required class="pm-input" placeholder="Username...">
                    </div>
                    <div class="pm-field">
                        <label class="pm-label">SUBJECT</label>
                        <input type="text" name="pm_subject" value="' . $prefillSubject . '" class="pm-input" placeholder="(optional)">
                    </div>
                    <div class="pm-field">
                        <label class="pm-label">MESSAGE</label>
                        <textarea name="pm_body" rows="8" required class="pm-textarea" placeholder="Write your message... (max 5000 characters)"></textarea>
                    </div>
                    <button type="submit" class="pm-send-btn">
                        <i class="fas fa-paper-plane"></i> SEND MESSAGE
                    </button>
                </form>
            </div>
        ';

        return $this->wrapLayout('New Message', $content, 'send');
    }

    private function wrapLayout(string $title, string $content, string $activeTab): string
    {
        $unreadCount = 0;
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM pm_messages WHERE receiver_id = ? AND is_read = 0 AND deleted_by_receiver = 0");
            $stmt->execute([$this->currentUserId]);
            $unreadCount = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {}

        $unreadBadge = $unreadCount > 0
            ? '<span class="pm-unread-badge">' . $unreadCount . '</span>'
            : '';

        $tabs = [
            'inbox' => 'Inbox' . $unreadBadge,
            'sent'  => 'Sent',
            'send'  => '<i class="fas fa-plus pm-tab-icon"></i> New',
        ];

        $tabHtml = '';
        foreach ($tabs as $tab => $label) {
            $active_class = ($tab === $activeTab) ? 'pm-tab--active' : '';
            $tabHtml .= '
                <a href="?p=private_messages&pm_action=' . $tab . '" class="pm-tab ' . $active_class . '">
                    ' . $label . '
                </a>
            ';
        }

        return '
            <div class="um-nexus-wrapper">
                <div class="pm-header">
                    <h2 class="pm-title"><i class="fas fa-envelope"></i> MESSAGES</h2>
                </div>

                <div class="pm-tabs">
                    ' . $tabHtml . '
                </div>

                ' . $content . '
            </div>
        ';
    }

    private function alert(string $type, string $message): string
    {
        $class = $type === 'success' ? 'pm-alert--success' : 'pm-alert--error';
        return '<div class="pm-alert ' . $class . '">' . h($message) . '</div>';
    }
}

$pm_module = new CorePrivateMessages($db, $userPriv ?? 0, $currentUserId ?? 0);
echo $pm_module->render();