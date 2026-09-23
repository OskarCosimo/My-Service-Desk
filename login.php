<?php
// login.php
// Unified Login page with OAuth integration, Ban checks, Account Approval Verification, and Remember Me
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/turnstile.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rateError = '';
    if (!check_rate_limit($pdo, 'login', $rateError)) {
        $error = $rateError;
    } else {
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        if (!verify_turnstile($pdo, $turnstileToken)) {
            $error = __('captcha_failed', 'Captcha verification failed.');
        } else {
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $rememberMe = !empty($_POST['remember_me']);

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Validate password using password_verify or legacy fallback
            $passwordValid = false;
            if ($user) {
                if (!empty($user['password_hash'])) {
                    $passwordValid = password_verify($password, $user['password_hash']);
                } elseif (!empty($user['password'])) {
                    $passwordValid = password_verify($password, $user['password']);
                }
            }

            if ($user && $passwordValid) {
                // 1. Check if user account is Banned
                if (!empty($user['is_banned'])) {
                    $error = __('account_banned', 'Your account has been suspended or banned. Please contact support.');
                }
                // 2. Check if account is Approved (Agencies require Admin approval, Agents require Agency approval)
                elseif (isset($user['is_approved']) && (int)$user['is_approved'] === 0) {
                    if ($user['role'] === 'agency') {
                        $error = __('agency_pending_approval', 'Your agency account registration is pending approval by an Administrator. You will be able to log in once approved.');
                    } elseif ($user['role'] === 'agent') {
                        $error = __('agent_pending_approval', 'Your agent account registration is pending approval by your Agency Manager. You will be able to log in once approved.');
                    } else {
                        $error = __('account_pending_approval', 'Your account is pending approval by an administrator.');
                    }
                }
                // 3. Process 2FA Verification if enabled
                elseif ((int)($user['two_factor_enabled'] ?? 0) === 1 && !empty($user['two_factor_secret'])) {
                    $_SESSION['2fa_pending_user'] = [
                        'id'                 => $user['id'],
                        'username'           => $user['username'] ?? $user['email'],
                        'role'               => $user['role'],
                        'email'              => $user['email'],
                        'two_factor_secret'  => $user['two_factor_secret'],
                        'remember_me'        => $rememberMe
                    ];
                    header("Location: /login_2fa.php");
                    exit;
                } 
                // 4. Complete Direct Login
                else {
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];
                    $_SESSION['username']   = $user['username'] ?? $user['email'];

                    if ($rememberMe) {
                        create_remember_me_token($pdo, (int)$user['id']);
                    }

                    header("Location: " . (in_array($user['role'], ['admin', 'agent', 'agency'], true) ? "/admin/dashboard.php" : "/index.php"));
                    exit;
                }
            } else {
                $error = __('invalid_credentials', 'Invalid email or password.');
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

$myetvEnabled  = get_setting($pdo, 'oauth_myetv_enabled') === '1';
$googleEnabled = get_setting($pdo, 'oauth_google_enabled') === '1';
$fbEnabled     = get_setting($pdo, 'oauth_facebook_enabled') === '1';
$msEnabled     = get_setting($pdo, 'oauth_microsoft_enabled') === '1';
$hasSso        = $myetvEnabled || $googleEnabled || $fbEnabled || $msEnabled;
?>

<main class="main-content">
    <div class="container my-5" style="max-width: 450px;">
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="card-title text-center mb-4"><?php echo __('login', 'Login'); ?></h3>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('email_address', 'Email Address'); ?></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0"><?php echo __('password', 'Password'); ?></label>
                            <a href="/forgot_password.php" class="small text-decoration-none"><?php echo __('forgot_password', 'Forgot Password?'); ?></a>
                        </div>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <!-- Remember Me Checkbox -->
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="rememberMeCheck" name="remember_me" value="1">
                        <label class="form-check-label user-select-none" for="rememberMeCheck"><?php echo __('remember_me', 'Remember me'); ?></label>
                    </div>

                    <?php if (get_setting($pdo, 'turnstile_enabled', '0') === '1'): ?>
                        <div class="mb-3">
                            <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(get_setting($pdo, 'turnstile_site_key')); ?>"></div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100"><?php echo __('sign_in', 'Sign In'); ?></button>
                </form>

                <?php if ($hasSso): ?>
                    <hr class="my-4">

                    <!-- SSO Login Options -->
                    <div class="d-grid gap-2">
                        <?php if ($myetvEnabled): ?>
                            <a href="/auth/myetv-login.php" class="btn btn-outline-dark"><i class="fa-solid fa-tv me-2"></i> <?php echo __('login_with_myetv', 'Login with MYETV'); ?></a>
                        <?php endif; ?>
                        <?php if ($googleEnabled): ?>
                            <a href="/auth/google-login.php" class="btn btn-outline-danger"><i class="fa-brands fa-google me-2"></i> <?php echo __('login_with_google', 'Login with Google'); ?></a>
                        <?php endif; ?>
                        <?php if ($fbEnabled): ?>
                            <a href="/auth/facebook-login.php" class="btn btn-outline-primary"><i class="fa-brands fa-facebook me-2"></i> <?php echo __('login_with_facebook', 'Login with Facebook'); ?></a>
                        <?php endif; ?>
                        <?php if ($msEnabled): ?>
                            <a href="/auth/microsoft-login.php" class="btn btn-outline-secondary"><i class="fa-brands fa-microsoft me-2"></i> <?php echo __('login_with_microsoft', 'Login with Microsoft'); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
