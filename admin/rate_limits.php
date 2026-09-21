<?php
// admin/rate_limits.php
// Admin page to configure Rate Limits dynamically (Forms, Auth, and REST API Keys)
session_start();
require_once __DIR__ . '/../includes/config.php';

$userRole = $_SESSION['user_role'] ?? '';
if ($userRole !== 'admin') {
    header("Location: /login.php");
    exit;
}

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rate_limits'])) {
    $settingsToSave = [
        // Login
        'rate_limit_login_max'             => (int)($_POST['login_max'] ?? 5),
        'rate_limit_login_seconds'         => (int)($_POST['login_seconds'] ?? 60),
        // Forgot Password
        'rate_limit_forgot_max'            => (int)($_POST['forgot_max'] ?? 3),
        'rate_limit_forgot_seconds'        => (int)($_POST['forgot_seconds'] ?? 300),
        // Submit Ticket
        'rate_limit_submit_ticket_max'     => (int)($_POST['submit_max'] ?? 3),
        'rate_limit_submit_ticket_seconds' => (int)($_POST['submit_seconds'] ?? 120),
        // Ticket Reply
        'rate_limit_reply_max'             => (int)($_POST['reply_max'] ?? 5),
        'rate_limit_reply_seconds'         => (int)($_POST['reply_seconds'] ?? 60),
        // REST API Tickets Creation Rate Limit
        'rate_limit_api_ticket_max'        => (int)($_POST['api_ticket_max'] ?? 100),
        'rate_limit_api_ticket_seconds'    => (int)($_POST['api_ticket_seconds'] ?? 86400),
    ];

    $stmtSave = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($settingsToSave as $key => $val) {
        $stmtSave->execute([$key, (string)$val]);
    }
    $success = "Rate limits updated successfully!";
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="container-fluid my-4" style="max-width: 900px;">
        <h2><i class="fa-solid fa-gauge-high me-2"></i> Security & Rate Limiting Settings</h2>
        <p class="text-muted">Set maximum allowed attempts and delay time windows (in seconds) to prevent brute-force attacks, spam, and API abuse.</p>
        <hr>

        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <form method="POST" action="rate_limits.php">
            <input type="hidden" name="save_rate_limits" value="1">

            <!-- REST API Key Rate Limit -->
            <div class="card mb-4 shadow-sm border-primary">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="fa-solid fa-key me-2"></i> REST API Ticket Ingestion Limit (Per API Key)
                </div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Ticket Submissions via API</label>
                        <input type="number" name="api_ticket_max" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_api_ticket_max', '100')); ?>" min="0" required>
                        <div class="form-text">Set to 0 to disable API rate limiting (unlimited).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Time Window (Seconds)</label>
                        <input type="number" name="api_ticket_seconds" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_api_ticket_seconds', '86400')); ?>" min="1" required>
                        <div class="form-text">Examples: 86400 = 1 Day, 3600 = 1 Hour, 60 = 1 Minute.</div>
                    </div>
                </div>
            </div>

            <!-- Login Rate Limit -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">
                    <i class="fa-solid fa-right-to-bracket me-2"></i> User Login Protection
                </div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Login Attempts</label>
                        <input type="number" name="login_max" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_login_max', '5')); ?>" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Time Window (Seconds)</label>
                        <input type="number" name="login_seconds" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_login_seconds', '60')); ?>" min="1" required>
                    </div>
                </div>
            </div>

            <!-- Forgot Password Rate Limit -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">
                    <i class="fa-solid fa-key me-2"></i> Password Reset Recovery Protection
                </div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Reset Requests</label>
                        <input type="number" name="forgot_max" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_forgot_max', '3')); ?>" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Time Window (Seconds)</label>
                        <input type="number" name="forgot_seconds" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_forgot_seconds', '300')); ?>" min="1" required>
                    </div>
                </div>
            </div>

            <!-- Ticket Creation Rate Limit -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">
                    <i class="fa-solid fa-ticket me-2"></i> New Ticket Submission Protection (Web Form)
                </div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Ticket Submissions</label>
                        <input type="number" name="submit_max" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_submit_ticket_max', '3')); ?>" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Time Window (Seconds)</label>
                        <input type="number" name="submit_seconds" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_submit_ticket_seconds', '120')); ?>" min="1" required>
                    </div>
                </div>
            </div>

            <!-- Ticket Reply Rate Limit -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">
                    <i class="fa-solid fa-comments me-2"></i> Ticket Reply Protection
                </div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Replies</label>
                        <input type="number" name="reply_max" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_reply_max', '5')); ?>" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Time Window (Seconds)</label>
                        <input type="number" name="reply_seconds" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rate_limit_reply_seconds', '60')); ?>" min="1" required>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fa-solid fa-floppy-disk me-1"></i> Save Rate Limits</button>
        </form>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
