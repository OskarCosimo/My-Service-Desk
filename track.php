<?php
// track.php
// Public tracking page with Access Token direct authentication, Email verification fallback, Reply capability, Staff actions, and Translations

session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/turnstile.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/notifications_helper.php';

$code        = trim($_REQUEST['code'] ?? '');
$token       = trim($_REQUEST['token'] ?? '');
$searchEmail = trim($_REQUEST['email'] ?? ($_SESSION['user_email'] ?? ''));

$userRole = $_SESSION['user_role'] ?? '';
$userId   = $_SESSION['user_id'] ?? 0;

// Determine if user has Agency context
$hasAgency = false;
if ($userRole === 'agent' && $userId > 0) {
    $stmtAgCheck = $pdo->prepare("SELECT agency_id FROM users WHERE id = ?");
    $stmtAgCheck->execute([$userId]);
    $hasAgency = (bool)$stmtAgCheck->fetchColumn();
}

// Full Search Exemption: Admin, Agency, or Agent with Agency
$canSearchWithoutEmail = in_array($userRole, ['admin', 'agency'], true) || ($userRole === 'agent' && $hasAgency);
$isStaff = in_array($userRole, ['admin', 'agency', 'agent'], true);

$ticket      = null;
$replies     = [];
$error       = '';
$success     = '';
$isFollowing = false;

// Check for newly created ticket flag or session flash
$justCreated = isset($_GET['created']) || isset($_SESSION['flash_created_ticket']);
if (isset($_SESSION['flash_created_ticket'])) {
    unset($_SESSION['flash_created_ticket']);
}

if (!empty($code)) {
    // 1. Direct secure authentication if access_token is present in URL
    if (!empty($token)) {
        $stmt = $pdo->prepare("
            SELECT t.*, c.name as category_name, u.email as user_email 
            FROM tickets t 
            LEFT JOIN categories c ON t.category_id = c.id 
            LEFT JOIN users u ON t.user_id = u.id 
            WHERE t.tracking_code = ? AND t.access_token = ?
        ");
        $stmt->execute([$code, $token]);
        $ticket = $stmt->fetch();

        if ($ticket) {
            // Automatically ensure searchEmail is populated for replies
            if (empty($searchEmail)) {
                $searchEmail = $ticket['guest_email'] ?: ($ticket['user_email'] ?? '');
            }
        } else {
            $error = __('ticket_not_found', 'No ticket found matching the specified criteria.');
        }
    } 
    // 2. Staff query by code only
    elseif ($canSearchWithoutEmail) {
        $stmt = $pdo->prepare("
            SELECT t.*, c.name as category_name, u.email as user_email 
            FROM tickets t 
            LEFT JOIN categories c ON t.category_id = c.id 
            LEFT JOIN users u ON t.user_id = u.id 
            WHERE t.tracking_code = ?
        ");
        $stmt->execute([$code]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            $error = __('ticket_not_found', 'No ticket found matching the specified criteria.');
        }
    } 
    // 3. Manual guest search requiring both code and email
    else {
        if (empty($searchEmail)) {
            $error = __('email_required', 'Please enter the email address associated with the ticket.');
        } else {
            $stmt = $pdo->prepare("
                SELECT t.*, c.name as category_name, u.email as user_email 
                FROM tickets t 
                LEFT JOIN categories c ON t.category_id = c.id 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE t.tracking_code = ? 
                  AND (t.guest_email = ? OR u.email = ?)
            ");
            $stmt->execute([$code, $searchEmail, $searchEmail]);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                $error = __('ticket_not_found', 'No ticket found matching the specified criteria.');
            }
        }
    }

    // Toggle Follow Ticket
    if ($ticket && $isStaff && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_follow'])) {
        $staffUserId = (int)$_SESSION['user_id'];
        $stmtCheckFollow = $pdo->prepare("SELECT id FROM ticket_followers WHERE ticket_id = ? AND user_id = ?");
        $stmtCheckFollow->execute([$ticket['id'], $staffUserId]);
        
        if ($stmtCheckFollow->fetch()) {
            $stmtUnfollow = $pdo->prepare("DELETE FROM ticket_followers WHERE ticket_id = ? AND user_id = ?");
            $stmtUnfollow->execute([$ticket['id'], $staffUserId]);
            $success = __('stopped_following_ticket', 'You have stopped following this ticket.');
            $isFollowing = false;
        } else {
            $stmtFollow = $pdo->prepare("INSERT INTO ticket_followers (ticket_id, user_id) VALUES (?, ?)");
            $stmtFollow->execute([$ticket['id'], $staffUserId]);
            $success = __('started_following_ticket', 'You are now following this ticket. You will receive internal notifications for all updates.');
            $isFollowing = true;
        }
    }

    if ($ticket && $isStaff && !isset($_POST['toggle_follow'])) {
        $stmtIsFollow = $pdo->prepare("SELECT id FROM ticket_followers WHERE ticket_id = ? AND user_id = ?");
        $stmtIsFollow->execute([$ticket['id'], $_SESSION['user_id']]);
        $isFollowing = (bool)$stmtIsFollow->fetch();
    }

    // Staff Manual Status Update
    if ($ticket && $isStaff && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
        $allowedStatuses = ['open', 'answered', 'customer_reply', 'closed'];
        $newStatus = trim($_POST['status'] ?? '');

        if (in_array($newStatus, $allowedStatuses, true) && $newStatus !== $ticket['status']) {
            $oldStatus = $ticket['status'];
            $stmtUpdateStatus = $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?");
            if ($stmtUpdateStatus->execute([$newStatus, $ticket['id']])) {
                $ticket['status'] = $newStatus;
                
                $staffName = $_SESSION['username'] ?? 'Staff Member';
                $logMessage = "<em>System Note: Ticket status changed from <strong>" . strtoupper(str_replace('_', ' ', $oldStatus)) . "</strong> to <strong>" . strtoupper(str_replace('_', ' ', $newStatus)) . "</strong> by <strong>" . htmlspecialchars($staffName) . "</strong> (" . ucfirst($userRole) . ").</em>";
                
                $stmtLogReply = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)");
                $stmtLogReply->execute([$ticket['id'], $userId, $logMessage]);

                $notifTitle = "Status updated on ticket #" . $ticket['tracking_code'];
                $notifMsg   = "Status changed to " . strtoupper(str_replace('_', ' ', $newStatus)) . " by " . $staffName;
                notify_ticket_followers($pdo, $ticket, $notifTitle, $notifMsg, $userId);

                $success = __('status_updated', 'Ticket status updated successfully!');
            } else {
                $error = __('status_update_failed', 'Failed to update ticket status.');
            }
        }
    }

    // Handle Reply Submission
    if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reply'])) {
        $rateError = '';
        if (!check_rate_limit($pdo, 'reply', $rateError)) {
            $error = $rateError;
        } else {
            $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
            
            if (!verify_turnstile($pdo, $turnstileToken)) {
                $error = __('captcha_failed', 'Captcha verification failed. Please try again.');
            } else {
                $replyMessage = trim($_POST['reply_message'] ?? '');

                if (empty($replyMessage)) {
                    $error = __('message_required', 'Please enter a message before replying.');
                } else {
                    $senderUserId = $_SESSION['user_id'] ?? null;
                    $senderEmail = $senderUserId ? ($_SESSION['user_email'] ?? '') : $searchEmail;

                    $stmtReply = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)");
                    if ($stmtReply->execute([$ticket['id'], $senderUserId, $replyMessage])) {
                        $newStatus = $isStaff ? 'answered' : 'customer_reply';
                        $stmtUpdate = $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?");
                        $stmtUpdate->execute([$newStatus, $ticket['id']]);

                        notify_ticket_participants($pdo, $ticket, $replyMessage, $senderEmail);

                        $notifTitle = "New reply on ticket #" . $ticket['tracking_code'];
                        $notifMsg   = "Reply posted by " . ($senderUserId ? ($_SESSION['username'] ?? 'Staff') : ($ticket['guest_name'] ?: 'Customer'));
                        notify_ticket_followers($pdo, $ticket, $notifTitle, $notifMsg, $senderUserId);

                        trigger_hook('on_ticket_replied', [
                            'ticket' => $ticket,
                            'reply' => ['message' => $replyMessage],
                            'sender_name' => $senderUserId ? ($_SESSION['user_email'] ?? 'User') : ($ticket['guest_name'] ?: 'Customer')
                        ]);

                        $success = __('reply_sent', 'Your reply has been posted successfully!');
                        $ticket['status'] = $newStatus;
                    } else {
                        $error = __('reply_failed', 'Failed to post your reply. Please try again.');
                    }
                }
            }
        }
    }

    if ($ticket) {
        $stmtReplies = $pdo->prepare("SELECT r.*, u.username, u.role FROM ticket_replies r LEFT JOIN users u ON r.user_id = u.id WHERE r.ticket_id = ? ORDER BY r.created_at ASC");
        $stmtReplies->execute([$ticket['id']]);
        $replies = $stmtReplies->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

// Detect active language from header/app environment
$activeLang = $currentLang ?? $_SESSION['lang'] ?? $_COOKIE['site_lang'] ?? $_COOKIE['lang'] ?? 'en';
?>

<!-- Local My-WYSIWYG Assets (CSS, i18n, JS) -->
<link rel="stylesheet" href="/assets/vendor/my-wysiwyg/my_wysiwyg.css">
<script src="/assets/vendor/my-wysiwyg/my_wysiwyg_i18n.js"></script>
<script src="/assets/vendor/my-wysiwyg/my_wysiwyg.js"></script>

<main class="main-content">
    <div class="container my-4" style="max-width: 850px;">
        
        <?php if ($ticket): ?>
            <!-- Ticket Already Loaded View: Clean header without input search boxes -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="m-0"><?php echo __('track_your_ticket', 'Track Your Ticket'); ?></h2>
                <a href="track.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-magnifying-glass me-1"></i> <?php echo __('search_another_ticket', 'Search Another Ticket'); ?>
                </a>
            </div>
            <hr>

            <?php if ($justCreated): ?>
                <!-- Prominent Success Box shown immediately after redirection from submit.php -->
                <div class="alert alert-success d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-4 shadow-sm">
                    <div>
                        <i class="fa-solid fa-circle-check fa-lg me-2"></i>
                        <strong><?php echo __('ticket_submitted_success', 'Ticket submitted successfully!'); ?></strong>
                        <div class="small text-muted mt-1">
                            <?php echo __('tracking_code_saved_notice', 'Save your reference code to retrieve this ticket at any time:'); ?>
                        </div>
                    </div>
                    <div class="input-group" style="max-width: 250px;">
                        <input type="text" class="form-control font-monospace fw-bold bg-white text-center" id="copyCodeInput" value="<?php echo htmlspecialchars($ticket['tracking_code']); ?>" readonly>
                        <button class="btn btn-dark" type="button" onclick="copyTrackingCode()">
                            <i class="fa-regular fa-copy me-1"></i> <?php echo __('copy', 'Copy'); ?>
                        </button>
                    </div>
                </div>

                <script>
                    function copyTrackingCode() {
                        const input = document.getElementById('copyCodeInput');
                        input.select();
                        input.setSelectionRange(0, 99999);
                        navigator.clipboard.writeText(input.value);
                    }
                </script>
            <?php endif; ?>

        <?php else: ?>
            <!-- Standard Search Box displayed only when no ticket is loaded -->
            <h2><?php echo __('track_your_ticket', 'Track Your Ticket'); ?></h2>
            <hr>

            <?php if ($canSearchWithoutEmail): ?>
                <?php 
                    $roleLabel = match($userRole) {
                        'admin'  => __('role_admin', 'Admin'),
                        'agency' => __('role_agency_manager', 'Agency Manager'),
                        'agent'  => __('role_agent', 'Agent'),
                        default  => __('role_staff_member', 'Staff Member')
                    };
                ?>
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i> 
                    <?php printf(__('staff_search_notice', 'You are logged in as an <strong>%s</strong>, so you can search tickets using only the Tracking Code.'), $roleLabel); ?>
                </div>
            <?php elseif ($userRole === 'agent'): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> 
                    <?php echo __('agent_independent_search_notice', 'As an independent <strong>Agent</strong> (no agency assigned), you must enter both the Tracking Code and the associated Email address to search for tickets.'); ?>
                </div>
            <?php endif; ?>

            <form method="GET" action="track.php" class="row g-3 mb-4">
                <?php if ($canSearchWithoutEmail): ?>
                    <div class="col-md-8">
                        <input type="text" name="code" class="form-control" placeholder="<?php echo htmlspecialchars(__('enter_tracking_code_placeholder', 'Enter Tracking Code (e.g. ABC-123-XYZ)')); ?>" value="<?php echo htmlspecialchars($code); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-magnifying-glass me-1"></i> <?php echo __('search', 'Search Ticket'); ?></button>
                    </div>
                <?php else: ?>
                    <div class="col-md-5">
                        <input type="text" name="code" class="form-control" placeholder="<?php echo htmlspecialchars(__('tracking_code_placeholder', 'Tracking Code (e.g. ABC-123-XYZ)')); ?>" value="<?php echo htmlspecialchars($code); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <input type="email" name="email" class="form-control" placeholder="<?php echo htmlspecialchars(__('ticket_email_placeholder', 'Ticket Email')); ?>" value="<?php echo htmlspecialchars($searchEmail); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-magnifying-glass me-1"></i> <?php echo __('search', 'Search'); ?></button>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <?php if ($ticket): ?>
            <!-- Main Ticket Container -->
            <div class="card mb-4 shadow-sm" id="ticket_box">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="m-0">[#<?php echo htmlspecialchars($ticket['tracking_code']); ?>] <?php echo htmlspecialchars($ticket['subject']); ?></h5>
                    <span class="badge bg-info text-dark"><?php echo strtoupper($ticket['status']); ?></span>
                </div>
                <div class="card-body">
                    <!-- Original Message Content -->
                    <div class="ticket-description mb-3 content-original"><?php echo $ticket['message']; ?></div>
                    
                    <!-- Translated Content Area (Hidden initially) -->
                    <div class="content-translated alert alert-light border p-3 mb-3 d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <small class="text-primary fw-bold">
                                <i class="fa-solid fa-language me-1"></i> <?php echo __('translated_content', 'Translated content'); ?> (<span class="target-lang-label"><?php echo strtoupper($activeLang); ?></span>) 
                                <span class="badge bg-secondary font-monospace cache-badge ms-1" style="font-size:0.7em;"></span>
                            </small>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-restore-orig">
                                <i class="fa-solid fa-rotate-left me-1"></i> <?php echo __('show_original', 'Show Original'); ?>
                            </button>
                        </div>
                        <div class="translated-text"></div>
                    </div>

                    <!-- Ticket Footer with Translate Action -->
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2 border-top">
                        <div class="d-flex align-items-center gap-3">
                            <small class="text-muted"><?php echo __('submitted_on', 'Submitted on:'); ?> <?php echo $ticket['created_at']; ?> | <?php echo __('category_label', 'Category:'); ?> <strong><?php echo htmlspecialchars($ticket['category_name'] ?? __('general', 'General')); ?></strong></small>
                            
                            <!-- Main Ticket Translate Toggle Button -->
                            <button type="button" class="btn btn-sm btn-outline-primary btn-translate-toggle" 
                                    data-item-type="ticket_message" 
                                    data-item-id="<?php echo $ticket['id']; ?>">
                                <i class="fa-solid fa-language me-1"></i> <span class="btn-text"><?php echo __('translate', 'Translate'); ?> (<span class="target-lang-label"><?php echo strtoupper($activeLang); ?></span>)</span>
                            </button>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <?php if ($isStaff): ?>
                                <form method="POST" action="track.php?code=<?php echo urlencode($code); ?>&token=<?php echo urlencode($token); ?>&email=<?php echo urlencode($searchEmail); ?>" class="d-inline">
                                    <input type="hidden" name="toggle_follow" value="1">
                                    <button type="submit" class="btn btn-sm <?php echo $isFollowing ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                        <i class="fa-solid <?php echo $isFollowing ? 'fa-star' : 'fa-star-half-stroke'; ?> me-1"></i>
                                        <?php echo $isFollowing ? __('following_ticket', 'Following Ticket') : __('follow_ticket', 'Follow Ticket'); ?>
                                    </button>
                                </form>

                                <form method="POST" action="track.php?code=<?php echo urlencode($code); ?>&token=<?php echo urlencode($token); ?>&email=<?php echo urlencode($searchEmail); ?>" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="update_status" value="1">
                                    <select name="status" class="form-select form-select-sm" style="width: auto;">
                                        <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>><?php echo __('status_open', 'Open'); ?></option>
                                        <option value="answered" <?php echo $ticket['status'] === 'answered' ? 'selected' : ''; ?>><?php echo __('status_answered', 'Answered'); ?></option>
                                        <option value="customer_reply" <?php echo $ticket['status'] === 'customer_reply' ? 'selected' : ''; ?>><?php echo __('status_customer_reply', 'Customer Reply'); ?></option>
                                        <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>><?php echo __('status_closed', 'Closed'); ?></option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo __('update_status', 'Update Status'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Replies Section -->
            <h4 class="mb-3"><?php echo __('replies', 'Replies & Activity'); ?></h4>
            <?php foreach ($replies as $reply): ?>
                <div class="card mb-3 <?php echo $reply['user_id'] ? 'border-primary' : ''; ?>" id="reply_<?php echo $reply['id']; ?>">
                    <div class="card-header py-1 bg-light d-flex justify-content-between align-items-center">
                        <strong>
                            <?php if ($reply['username']): ?>
                                <?php echo htmlspecialchars($reply['username']); ?> 
                                <span class="badge bg-secondary ms-1"><?php echo strtoupper($reply['role'] ?? __('staff', 'Staff')); ?></span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($ticket['guest_name'] ?: __('customer', 'Customer')); ?>
                            <?php endif; ?>
                        </strong>
                        <small class="text-muted"><?php echo $reply['created_at']; ?></small>
                    </div>
                    <div class="card-body">
                        <!-- Reply Original Message -->
                        <div class="content-original mb-2"><?php echo $reply['message']; ?></div>

                        <!-- Reply Translated Box (Hidden initially) -->
                        <div class="content-translated alert alert-light border p-3 mb-2 d-none">
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                <small class="text-primary fw-bold">
                                    <i class="fa-solid fa-language me-1"></i> <?php echo __('translated_content', 'Translated content'); ?> (<span class="target-lang-label"><?php echo strtoupper($activeLang); ?></span>) 
                                    <span class="badge bg-secondary font-monospace cache-badge ms-1" style="font-size:0.7em;"></span>
                                </small>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-restore-orig">
                                    <i class="fa-solid fa-rotate-left me-1"></i> <?php echo __('show_original', 'Show Original'); ?>
                                </button>
                            </div>
                            <div class="translated-text"></div>
                        </div>

                        <!-- Reply Action Bar with Translate Toggle -->
                        <div class="d-flex justify-content-end pt-1">
                            <button type="button" class="btn btn-sm btn-outline-primary btn-translate-toggle" 
                                    data-item-type="reply_message" 
                                    data-item-id="<?php echo $reply['id']; ?>">
                                <i class="fa-solid fa-language me-1"></i> <span class="btn-text"><?php echo __('translate', 'Translate'); ?> (<span class="target-lang-label"><?php echo strtoupper($activeLang); ?></span>)</span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($ticket['status'] !== 'closed'): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-secondary text-white"><?php echo __('post_a_reply', 'Post a Reply'); ?></div>
                    <div class="card-body">
                        <form id="reply_form" method="POST" action="track.php?code=<?php echo urlencode($code); ?>&token=<?php echo urlencode($token); ?>&email=<?php echo urlencode($searchEmail); ?>">
                            <input type="hidden" name="submit_reply" value="1">
                            <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($searchEmail); ?>">

                            <?php if ($isStaff && get_setting($pdo, 'ai_enabled', '0') === '1'): ?>
                                <div class="mb-3">
                                    <button type="button" id="btn_generate_ai" class="btn btn-sm btn-outline-dark">
                                        <i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> <?php echo __('generate_ai_suggestion', 'Generate AI Suggestion'); ?>
                                    </button>
                                    <span id="ai_spinner" class="spinner-border spinner-border-sm text-primary d-none ms-2" role="status"></span>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label"><?php echo __('your_message', 'Your Message'); ?></label>
                                <textarea id="reply_message" name="reply_message" class="form-control" rows="5"></textarea>
                            </div>

                            <?php if (get_setting($pdo, 'turnstile_enabled', '0') === '1'): ?>
                                <div class="mb-3">
                                    <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(get_setting($pdo, 'turnstile_site_key')); ?>"></div>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-success"><i class="fa-solid fa-reply me-1"></i> <?php echo __('send_reply', 'Send Reply'); ?></button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mt-4"><?php echo __('ticket_closed_notice', 'This ticket is closed. You cannot post further replies.'); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Initialize WYSIWYG editor
        let editorInstance = null;
        if (typeof MyWysiwyg !== 'undefined' && document.getElementById('reply_message')) {
            editorInstance = new MyWysiwyg('#reply_message', {
                lang: '<?php echo htmlspecialchars($activeLang); ?>',
                maxChars: 5000
            });
        }

        function getSelectedHeaderLang() {
            const select = document.querySelector('select[name="lang"], select#lang_select, select.language-selector');
            if (select && select.value) {
                return select.value.toLowerCase();
            }
            return '<?php echo htmlspecialchars($activeLang); ?>';
        }

        const btnAI = document.getElementById('btn_generate_ai');
        const spinner = document.getElementById('ai_spinner');
        if (btnAI) {
            btnAI.addEventListener('click', function() {
                btnAI.disabled = true;
                if (spinner) spinner.classList.remove('d-none');

                const formData = new FormData();
                formData.append('ticket_id', '<?php echo $ticket['id'] ?? 0; ?>');

                fetch('/api/generate_ai_reply.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    btnAI.disabled = false;
                    if (spinner) spinner.classList.add('d-none');
                    if (data.success && data.reply) {
                        if (editorInstance && typeof editorInstance.setHtml === 'function') {
                            editorInstance.setHtml(data.reply);
                        } else {
                            document.getElementById('reply_message').value = data.reply;
                        }
                    } else {
                        alert('AI Error: ' + (data.error || 'Failed to generate response.'));
                    }
                })
                .catch(() => {
                    btnAI.disabled = false;
                    if (spinner) spinner.classList.add('d-none');
                    alert('Server connection error while calling AI.');
                });
            });
        }

        const ticketCode  = '<?php echo htmlspecialchars($ticket['tracking_code'] ?? ''); ?>';
        const ticketToken = '<?php echo htmlspecialchars($ticket['access_token'] ?? ''); ?>';

        const labelTranslate    = "<?php echo addslashes(__('translate', 'Translate')); ?>";
        const labelShowOriginal = "<?php echo addslashes(__('show_original', 'Show Original')); ?>";
        const labelTranslating  = "<?php echo addslashes(__('translating', 'Translating...')); ?>";

        function restoreOriginal(card) {
            const origBox     = card.querySelector('.content-original');
            const transBox    = card.querySelector('.content-translated');
            const toggleBtn   = card.querySelector('.btn-translate-toggle');
            const targetLang  = getSelectedHeaderLang().toUpperCase();

            transBox.classList.add('d-none');
            origBox.classList.remove('d-none');

            if (toggleBtn) {
                toggleBtn.setAttribute('data-state', 'original');
                toggleBtn.className = 'btn btn-sm btn-outline-primary btn-translate-toggle';
                toggleBtn.innerHTML = `<i class="fa-solid fa-language me-1"></i> <span class="btn-text">${labelTranslate} (${targetLang})</span>`;
            }
        }

        function showTranslation(card) {
            const origBox     = card.querySelector('.content-original');
            const transBox    = card.querySelector('.content-translated');
            const toggleBtn   = card.querySelector('.btn-translate-toggle');

            origBox.classList.add('d-none');
            transBox.classList.remove('d-none');

            if (toggleBtn) {
                toggleBtn.setAttribute('data-state', 'translated');
                toggleBtn.className = 'btn btn-sm btn-outline-warning btn-translate-toggle';
                toggleBtn.innerHTML = `<i class="fa-solid fa-rotate-left me-1"></i> <span class="btn-text">${labelShowOriginal}</span>`;
            }
        }

        document.querySelectorAll('.btn-translate-toggle').forEach(btn => {
            btn.addEventListener('click', function() {
                const card       = this.closest('.card-body') || this.closest('.card');
                const transBox   = card.querySelector('.content-translated');
                const transText  = transBox.querySelector('.translated-text');
                const cacheBadge = transBox.querySelector('.cache-badge');
                const langLabels = transBox.querySelectorAll('.target-lang-label');
                const state      = this.getAttribute('data-state') || 'original';
                const targetLang = getSelectedHeaderLang();

                if (state === 'translated') {
                    restoreOriginal(card);
                    return;
                }

                if (transText.innerHTML.trim() !== '') {
                    showTranslation(card);
                    return;
                }

                const itemType    = this.getAttribute('data-item-type');
                const itemId      = this.getAttribute('data-item-id');
                const origBtnHtml = this.innerHTML;

                this.disabled  = true;
                this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> ${labelTranslating}`;

                const formData = new FormData();
                formData.append('item_type', itemType);
                formData.append('item_id', itemId);
                formData.append('target_lang', targetLang);
                formData.append('code', ticketCode);
                formData.append('token', ticketToken);

                fetch('/api/translate.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(res => {
                    this.disabled = false;

                    if (res.success && res.translated) {
                        transText.innerHTML = res.translated;
                        langLabels.forEach(l => l.textContent = targetLang.toUpperCase());
                        if (cacheBadge) {
                            cacheBadge.textContent = res.cached ? 'cached' : 'live';
                        }
                        showTranslation(card);
                    } else {
                        this.innerHTML = origBtnHtml;
                        alert('Translation Error: ' + (res.error || 'Failed to translate.'));
                    }
                })
                .catch(() => {
                    this.disabled = false;
                    this.innerHTML = origBtnHtml;
                    alert('Communication error with the translation API.');
                });
            });
        });

        document.querySelectorAll('.btn-restore-orig').forEach(btn => {
            btn.addEventListener('click', function() {
                const card = this.closest('.card-body') || this.closest('.card');
                restoreOriginal(card);
            });
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
