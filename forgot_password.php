<?php
// /var/www/support.myetv.tv/forgot_password.php
// Password recovery request page with mandatory 2FA check
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

$error = '';
$message = '';
$step = 'email_form'; // 'email_form' or '2fa_verify'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // STEP 1: Process email submission
    if (isset($_POST['action']) && $_POST['action'] === 'request_reset') {
        $rateError = '';
        if (!check_rate_limit($pdo, 'forgot', $rateError)) {
            $error = $rateError;
        } else {
            $email = trim($_POST['email'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } else {
                $stmt = $pdo->prepare("SELECT id, username, email, two_factor_secret, two_factor_enabled FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Require 2FA verification prior to sending email if enabled
                    if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
                        $_SESSION['reset_2fa_user_id'] = $user['id'];
                        $step = '2fa_verify';
                    } else {
                        // Send reset email immediately if 2FA is disabled
                        sendResetEmail($pdo, $user);
                        $message = "If an account matches that email address, a password reset link has been sent.";
                    }
                } else {
                    // Generic response to prevent user enumeration
                    $message = "If an account matches that email address, a password reset link has been sent.";
                }
            }
        }
    }
    // STEP 2: Process 2FA code verification
    elseif (isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
        $userId = $_SESSION['reset_2fa_user_id'] ?? 0;
        $code   = trim($_POST['code_2fa'] ?? '');

        if (!$userId) {
            $error = "Session expired. Please try again.";
            $step = 'email_form';
        } else {
            $stmt = $pdo->prepare("SELECT id, username, email, two_factor_secret FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            require_once __DIR__ . '/includes/totp_helper.php';

            if ($user && verify_totp_code($user['two_factor_secret'], $code)) {
                unset($_SESSION['reset_2fa_user_id']);
                sendResetEmail($pdo, $user);
                $message = "2FA verified! A password reset link has been sent to your email address.";
            } else {
                $error = "Invalid 2FA code. Please check your authenticator app and try again.";
                $step = '2fa_verify';
            }
        }
    }
}

/**
 * Generate password reset token and dispatch email notification
 */
function sendResetEmail(PDO $pdo, array $user) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $user['id']]);

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $domain   = $_SERVER['HTTP_HOST'];
    $resetUrl = "{$protocol}://{$domain}/reset_password.php?token=" . $token;

    $subject = "Password Reset Request - " . get_setting($pdo, 'site_title', 'Tickets Manager');
    $recipientName = $user['username'] ?? 'User';

    $body  = "<h3>Password Reset Request</h3>";
    $body .= "<p>Hello <strong>" . htmlspecialchars($recipientName) . "</strong>,</p>";
    $body .= "<p>We received a request to reset your password. Click the link below to set a new password:</p>";
    $body .= "<p><a href='{$resetUrl}' style='display:inline-block; padding:10px 15px; background:#007bff; color:#fff; text-decoration:none; border-radius:4px;'>Reset Password</a></p>";
    $body .= "<p>Or copy and paste this link into your browser:<br><a href='{$resetUrl}'>{$resetUrl}</a></p>";
    $body .= "<p><small>This link will expire in 1 hour. If you did not request this reset, please ignore this email.</small></p>";

    // Send email using the function defined in includes/mailer.php
    send_ticket_email($pdo, $user['email'], $recipientName, $subject, $body);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5" style="max-width: 500px;">
    <div class="card shadow">
        <div class="card-header bg-primary text-white text-center py-3">
            <h4 class="m-0"><i class="fa-solid fa-key me-2"></i> Reset Password</h4>
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($message); ?>
                </div>
                <a href="/login.php" class="btn btn-primary w-100 mt-2">Return to Login</a>
            <?php else: ?>

                <?php if ($step === 'email_form'): ?>
                    <form method="POST" action="forgot_password.php">
                        <input type="hidden" name="action" value="request_reset">
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" required placeholder="your.email@domain.com">
                            <div class="form-text">Enter your registered account email address.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-paper-plane me-1"></i> Send Reset Link</button>
                    </form>
                <?php elseif ($step === '2fa_verify'): ?>
                    <div class="alert alert-info">
                        <i class="fa-solid fa-shield-halved me-1"></i> Two-Factor Authentication is enabled on this account. Enter the 6-digit code from your authenticator app to authorize the password reset request.
                    </div>
                    <form method="POST" action="forgot_password.php">
                        <input type="hidden" name="action" value="verify_2fa">
                        <div class="mb-3">
                            <label class="form-label">2FA Verification Code</label>
                            <input type="text" name="code_2fa" class="form-control text-center fs-4" required maxlength="6" pattern="[0-9]{6}" autocomplete="off" placeholder="000000" autofocus>
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-check me-1"></i> Verify & Send Email</button>
                    </form>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
