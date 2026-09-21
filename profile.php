<?php
// profile.php
// User Profile Settings Page with QR Code 2FA Activation, Auto-Assignment, and REST API Key Management for Staff (Admin, Agency, Agent)
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/totp_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$success = '';
$error = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: /logout.php");
    exit;
}

// Generate temp secret if 2FA is disabled
if (empty($_SESSION['temp_2fa_secret'])) {
    $_SESSION['temp_2fa_secret'] = generate_totp_secret();
}
$tempSecret = $_SESSION['temp_2fa_secret'];
$siteTitle  = get_setting($pdo, 'site_title', 'Support Tickets');
$qrUri      = get_totp_qr_url($user['email'], $siteTitle, $tempSecret);

// Staff check: Admins, Agencies, and Agents can have an API Key
$isStaffUser = in_array($user['role'], ['admin', 'agency', 'agent'], true);

// Fetch existing API Key
$userApiKey = null;
if ($isStaffUser) {
    $stmtKey = $pdo->prepare("SELECT * FROM api_keys WHERE user_id = ? LIMIT 1");
    $stmtKey->execute([$userId]);
    $userApiKey = $stmtKey->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Update Profile Info
    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');

        if (empty($username)) {
            $error = __('username_empty', 'Username cannot be empty.');
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            if ($stmtUpdate->execute([$username, $userId])) {
                $_SESSION['username'] = $username;
                $user['username'] = $username;
                $success = __('profile_updated', 'Profile information updated successfully.');
            } else {
                $error = __('profile_update_failed', 'Failed to update profile information.');
            }
        }
    }

    // Update Ticket Auto-Assignment Setting (Agencies & Agents only)
    if ($action === 'update_auto_assign' && in_array($user['role'], ['agency', 'agent'], true)) {
        $autoAssign = isset($_POST['auto_assign_tickets']) ? 1 : 0;
        
        $stmtAssign = $pdo->prepare("UPDATE users SET auto_assign_tickets = ? WHERE id = ?");
        if ($stmtAssign->execute([$autoAssign, $userId])) {
            $user['auto_assign_tickets'] = $autoAssign;
            $success = __('preferences_updated', 'Ticket management preferences updated successfully.');
        } else {
            $error = __('preferences_update_failed', 'Failed to update auto-assignment setting.');
        }
    }

    // Generate or Regenerate API Key (Admin / Agency / Agent)
    if ($action === 'generate_api_key' && $isStaffUser) {
        $newApiKey = 'tmk_' . bin2hex(random_bytes(24));
        $keyName = trim($_POST['key_name'] ?? ($user['role'] === 'admin' ? 'Admin API Key' : 'Staff API Key'));

        if ($userApiKey) {
            $stmtKeyUpdate = $pdo->prepare("UPDATE api_keys SET api_key = ?, key_name = ?, is_active = 1, revoked_at = NULL, created_at = NOW() WHERE user_id = ?");
            $stmtKeyUpdate->execute([$newApiKey, $keyName, $userId]);
        } else {
            $stmtKeyInsert = $pdo->prepare("INSERT INTO api_keys (user_id, key_name, api_key, is_active) VALUES (?, ?, ?, 1)");
            $stmtKeyInsert->execute([$userId, $keyName, $newApiKey]);
        }

        $stmtKey = $pdo->prepare("SELECT * FROM api_keys WHERE user_id = ? LIMIT 1");
        $stmtKey->execute([$userId]);
        $userApiKey = $stmtKey->fetch();

        $success = __('api_key_generated', 'API Key generated successfully. Make sure to keep it secure.');
    }

    // Revoke API Key
    if ($action === 'revoke_api_key' && $isStaffUser) {
        if ($userApiKey) {
            $stmtRevoke = $pdo->prepare("UPDATE api_keys SET is_active = 0, revoked_at = NOW() WHERE user_id = ?");
            $stmtRevoke->execute([$userId]);

            $stmtKey = $pdo->prepare("SELECT * FROM api_keys WHERE user_id = ? LIMIT 1");
            $stmtKey->execute([$userId]);
            $userApiKey = $stmtKey->fetch();

            $success = __('api_key_revoked', 'Your API Key has been revoked.');
        }
    }

    // Change Password
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = __('fill_password_fields', 'Please fill in all password fields.');
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            $error = __('current_password_incorrect', 'Current password is incorrect.');
        } elseif ($newPassword !== $confirmPassword) {
            $error = __('passwords_do_not_match', 'New password and confirmation do not match.');
        } elseif (strlen($newPassword) < 8) {
            $error = __('password_min_length', 'New password must be at least 8 characters long.');
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtPass = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($stmtPass->execute([$hashedPassword, $userId])) {
                $user['password_hash'] = $hashedPassword;
                $success = __('password_changed_success', 'Password changed successfully.');
            } else {
                $error = __('password_update_failed', 'Failed to update password.');
            }
        }
    }

    // Enable 2FA (Verify TOTP code)
    if ($action === 'enable_2fa') {
        $totpCode = trim($_POST['totp_code'] ?? '');

        if (verify_totp_code($tempSecret, $totpCode)) {
            $stmt2FA = $pdo->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1 WHERE id = ?");
            $stmt2FA->execute([$tempSecret, $userId]);
            
            unset($_SESSION['temp_2fa_secret']);
            $user['two_factor_enabled'] = 1;
            $user['two_factor_secret']  = $tempSecret;
            $success = __('two_factor_enabled_success', 'Two-Factor Authentication (2FA) has been enabled for your account.');
        } else {
            $error = __('invalid_totp_code', 'Invalid 2FA Verification Code. Please try again.');
        }
    }

    // Disable 2FA
    if ($action === 'disable_2fa') {
        $stmtDisable = $pdo->prepare("UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = ?");
        $stmtDisable->execute([$userId]);

        $user['two_factor_enabled'] = 0;
        $user['two_factor_secret']  = null;
        $_SESSION['temp_2fa_secret'] = generate_totp_secret();
        $success = __('two_factor_disabled_success', 'Two-Factor Authentication (2FA) has been disabled.');
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- QRCode.js Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<main class="main-content">
    <div class="container my-4" style="max-width: 800px;">
        <h2><i class="fa-solid fa-user-gear me-2"></i> <?php echo __('account_settings', 'Account Settings'); ?></h2>
        <hr>

        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <!-- Personal Information Card -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-id-card me-1"></i> <?php echo __('personal_information', 'Personal Information'); ?>
            </div>
            <div class="card-body">
                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('email_address', 'Email Address'); ?></label>
                        <input type="email" class="form-control bg-light" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo __('username_display_name', 'Username / Display Name'); ?></label>
                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> <?php echo __('save_information', 'Save Information'); ?></button>
                </form>
            </div>
        </div>

        <!-- REST API Key Management (Admins, Agencies, Agents) -->
        <?php if ($isStaffUser): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-key me-1"></i> <?php echo __('rest_api_key', 'REST API Key'); ?></span>
                    <?php if ($userApiKey && !empty($userApiKey['is_active'])): ?>
                        <span class="badge bg-success"><?php echo __('active', 'Active'); ?></span>
                    <?php elseif ($userApiKey): ?>
                        <span class="badge bg-danger"><?php echo __('revoked', 'Revoked'); ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?php echo __('not_generated', 'Not Generated'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        <?php echo __('api_key_description', 'You can use this API Key to create tickets via the REST API endpoint (<code>/api/v1/tickets.php</code>). Each user is allowed a single active API key.'); ?>
                    </p>

                    <?php if ($userApiKey && !empty($userApiKey['is_active'])): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><?php echo __('your_api_key', 'Your API Key:'); ?></label>
                            <div class="input-group">
                                <input type="text" id="api_key_field" class="form-control font-monospace bg-light" value="<?php echo htmlspecialchars($userApiKey['api_key']); ?>" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('api_key_field').value); alert('API Key copied to clipboard!');">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                            <small class="text-muted"><?php echo __('created_at', 'Created on:'); ?> <?php echo htmlspecialchars($userApiKey['created_at']); ?></small>
                        </div>

                        <div class="d-flex gap-2">
                            <form method="POST" action="profile.php" onsubmit="return confirm('Regenerating will invalidate your current API Key. Continue?');">
                                <input type="hidden" name="action" value="generate_api_key">
                                <input type="hidden" name="key_name" value="<?php echo htmlspecialchars($userApiKey['key_name']); ?>">
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fa-solid fa-rotate me-1"></i> <?php echo __('regenerate_key', 'Regenerate Key'); ?>
                                </button>
                            </form>

                            <form method="POST" action="profile.php" onsubmit="return confirm('Are you sure you want to revoke this API Key?');">
                                <input type="hidden" name="action" value="revoke_api_key">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fa-solid fa-ban me-1"></i> <?php echo __('revoke_key', 'Revoke Key'); ?>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="profile.php">
                            <input type="hidden" name="action" value="generate_api_key">
                            <div class="mb-3">
                                <label class="form-label"><?php echo __('key_description_label', 'Key Description / Name'); ?></label>
                                <input type="text" name="key_name" class="form-control" value="<?php echo $user['role'] === 'admin' ? 'Admin API Key' : 'Staff API Key'; ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-key me-1"></i> <?php echo __('generate_new_api_key', 'Generate New API Key'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Ticket Auto-Assignment Card (For Agencies and Agents only) -->
        <?php if (in_array($user['role'], ['agency', 'agent'], true)): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">
                    <i class="fa-solid fa-robot me-1"></i> <?php echo __('ticket_management_settings', 'Ticket Management Settings'); ?>
                </div>
                <div class="card-body">
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="update_auto_assign">
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="auto_assign_tickets" value="1" id="autoAssignSwitch" <?php echo !empty($user['auto_assign_tickets']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="autoAssignSwitch">
                                <?php echo __('auto_assign_tickets_label', 'Automatically assign new incoming tickets to my account'); ?>
                            </label>
                        </div>
                        
                        <p class="text-muted small mb-3">
                            <?php if ($user['role'] === 'agency'): ?>
                                <?php echo __('auto_assign_agency_help', 'When enabled, new tickets will be automatically assigned to your agency upon creation and will instantly become accessible to all your agents.'); ?>
                            <?php else: ?>
                                <?php echo __('auto_assign_agent_help', 'When enabled, new incoming tickets will be automatically assigned directly to your agent account upon submission.'); ?>
                            <?php endif; ?>
                        </p>

                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> <?php echo __('save_preferences', 'Save Preferences'); ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Security & Password Card -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-secondary text-white">
                <i class="fa-solid fa-key me-1"></i> <?php echo __('security_change_password', 'Security & Change Password'); ?>
            </div>
            <div class="card-body">
                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label"><?php echo __('current_password', 'Current Password'); ?></label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('new_password', 'New Password'); ?></label>
                            <input type="password" name="new_password" class="form-control" required minlength="8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('confirm_new_password', 'Confirm New Password'); ?></label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-warning"><i class="fa-solid fa-shield-halved me-1"></i> <?php echo __('update_password', 'Update Password'); ?></button>
                </form>
            </div>
        </div>

        <!-- Two-Factor Authentication (2FA) Card -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-mobile-screen-button me-1"></i> <?php echo __('two_factor_auth', 'Two-Factor Authentication (2FA)'); ?></span>
                <?php if ($user['two_factor_enabled']): ?>
                    <span class="badge bg-success"><?php echo __('active', 'Active'); ?></span>
                <?php else: ?>
                    <span class="badge bg-secondary"><?php echo __('disabled', 'Disabled'); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($user['two_factor_enabled']): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-circle-check me-1"></i> <?php echo __('two_factor_active_notice', 'Two-Factor Authentication is currently active on your account.'); ?>
                    </div>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="disable_2fa">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('<?php echo addslashes(__('confirm_disable_2fa', 'Are you sure you want to disable 2FA?')); ?>');">
                            <i class="fa-solid fa-lock-open me-1"></i> <?php echo __('disable_2fa', 'Disable Two-Factor Authentication'); ?>
                        </button>
                    </form>
                <?php else: ?>
                    <p><?php echo __('enable_2fa_instructions', 'To enable 2FA, scan the QR code below with your Authenticator App (Google Authenticator, Authy, etc.) and enter the generated 6-digit code to confirm setup.'); ?></p>
                    
                    <div class="row align-items-center mb-3">
                        <div class="col-md-4 text-center">
                            <div id="qrcode" class="p-2 border bg-white d-inline-block rounded"></div>
                        </div>
                        <div class="col-md-8">
                            <p class="mb-1"><strong><?php echo __('secret_key_manual', 'Secret Key (Manual Entry):'); ?></strong></p>
                            <code class="fs-5 bg-light p-2 rounded d-block mb-3 text-break"><?php echo htmlspecialchars($tempSecret); ?></code>

                            <form method="POST" action="profile.php">
                                <input type="hidden" name="action" value="enable_2fa">
                                <div class="input-group mb-2">
                                    <input type="text" name="totp_code" class="form-control" placeholder="<?php echo __('enter_totp_code', 'Enter 6-digit code'); ?>" maxlength="6" required autocomplete="off">
                                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-check me-1"></i> <?php echo __('verify_enable_2fa', 'Verify & Enable 2FA'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            new QRCode(document.getElementById("qrcode"), {
                                text: "<?php echo $qrUri; ?>",
                                width: 150,
                                height: 150
                            });
                        });
                    </script>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
