<?php
// admin/settings.php
// Admin configuration settings page with OAuth providers, AI, RAG Knowledge, Theme Branding Customization, Legal links, and Code Injection
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/rag_helper.php';

// Ensure user is authorized as Admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: /login.php");
    exit;
}

$success = '';

// Handle manual RAG Feed Sync request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_sync_rag'])) {
    $syncResult = sync_rag_feeds($pdo, true);
    $success = 'Knowledge Base feeds synchronized successfully! Processed ' . ($syncResult['total_items'] ?? 0) . ' items.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings'])) {
    foreach ($_POST['settings'] as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        // We do not trim HTML code injections to preserve formatting
        if ($key === 'inject_header' || $key === 'inject_footer') {
            $stmt->execute([$key, $value]);
        } else {
            $stmt->execute([$key, trim($value)]);
        }
    }
    $success = 'Settings updated successfully.';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Build dynamic Callback Redirect URIs based on the current HTTP host
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$baseUrl = $scheme . "://" . $_SERVER['HTTP_HOST'];

$myetvCallbackUrl   = $baseUrl . '/auth/myetv-callback.php';
$googleCallbackUrl  = $baseUrl . '/auth/google-callback.php';
$fbCallbackUrl      = $baseUrl . '/auth/facebook-callback.php';
$msCallbackUrl      = $baseUrl . '/auth/microsoft-callback.php';

// Check if at least one RAG Feed URL has been saved in the database
$savedFeed1 = trim(get_setting($pdo, 'rag_feed_url_1', get_setting($pdo, 'rag_tos_feed_url', '')));
$savedFeed2 = trim(get_setting($pdo, 'rag_feed_url_2', get_setting($pdo, 'rag_privacy_feed_url', '')));
$hasConfiguredFeed = (!empty($savedFeed1) || !empty($savedFeed2));

// Get RAG Stats
$lastSyncTimestamp = (int)get_setting($pdo, 'rag_last_sync_time', '0');
$lastSyncFormatted = $lastSyncTimestamp > 0 ? date('Y-m-d H:i:s', $lastSyncTimestamp) : 'Never';
$ragItemCount = $pdo->query("SELECT COUNT(*) FROM rag_knowledge")->fetchColumn();
?>

<!-- Dedicated standalone form for manual RAG sync to avoid invalid nested form tags -->
<form id="ragSyncForm" method="POST" action="settings.php">
    <input type="hidden" name="action_sync_rag" value="1">
</form>

<main class="main-content">
    <div class="container-fluid">
        <h2>System Settings</h2>
        <hr>

        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <form method="POST" action="settings.php">
            <!-- General Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-secondary text-white">General Configuration</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Site Title</label>
                        <input type="text" name="settings[site_title]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'site_title')); ?>">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[allow_guest_tickets]" value="0">
                        <input type="checkbox" name="settings[allow_guest_tickets]" value="1" class="form-check-input" id="allowGuest" <?php echo get_setting($pdo, 'allow_guest_tickets') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="allowGuest">Allow Guest Ticket Submissions</label>
                    </div>
                </div>
            </div>

            <!-- Legal & Policies Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-secondary text-white"><i class="fa-solid fa-scale-balanced me-2"></i> Legal & Policies (Footer Links)</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Terms of Service URL</label>
                            <input type="url" name="settings[terms_url]" class="form-control" placeholder="https://example.com/terms" value="<?php echo htmlspecialchars(get_setting($pdo, 'terms_url')); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Privacy Policy URL</label>
                            <input type="url" name="settings[privacy_url]" class="form-control" placeholder="https://example.com/privacy" value="<?php echo htmlspecialchars(get_setting($pdo, 'privacy_url')); ?>">
                        </div>
                    </div>
                    <div class="form-text text-muted"><i class="fa-solid fa-circle-info me-1"></i> If you provide a URL, the corresponding link will automatically appear in the public footer. Leave blank to disable.</div>
                </div>
            </div>

            <!-- Theme & Branding Customization -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-palette me-2"></i> Theme & Branding Customization</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Site Logo Image URL (Optional)</label>
                        <input type="url" name="settings[theme_logo_url]" class="form-control" placeholder="https://example.com/logo.png" value="<?php echo htmlspecialchars(get_setting($pdo, 'theme_logo_url', '')); ?>">
                        <div class="form-text text-muted">
                            <i class="fa-solid fa-circle-info me-1"></i> Recommended dimensions: <strong>180 x 40 px</strong> (Max height: 40px). If left empty, the site text title will be displayed instead.
                        </div>
                    </div>

                    <hr>
                    <h6><i class="fa-solid fa-paintbrush me-1"></i> Custom Layout Colors</h6>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label">Header Background</label>
                            <input type="color" name="settings[theme_header_bg]" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars(get_setting($pdo, 'theme_header_bg', '#212529')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Header Text Color</label>
                            <input type="color" name="settings[theme_header_text]" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars(get_setting($pdo, 'theme_header_text', '#ffffff')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sidebar Background</label>
                            <input type="color" name="settings[theme_sidebar_bg]" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars(get_setting($pdo, 'theme_sidebar_bg', '#212529')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sidebar Text Color</label>
                            <input type="color" name="settings[theme_sidebar_text]" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars(get_setting($pdo, 'theme_sidebar_text', '#f8f9fa')); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Custom Code Injection -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-code me-2"></i> Custom Code Injection</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Header Injection (Before &lt;/head&gt;)</label>
                        <textarea name="settings[inject_header]" class="form-control font-monospace" rows="4" placeholder="<!-- e.g. Custom meta tags, CSS, or Analytics script -->"><?php echo htmlspecialchars(get_setting($pdo, 'inject_header')); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Footer Injection (Before &lt;/body&gt;)</label>
                        <textarea name="settings[inject_footer]" class="form-control font-monospace" rows="4" placeholder="<!-- e.g. AdSense script, Live chat widget -->"><?php echo htmlspecialchars(get_setting($pdo, 'inject_footer')); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- OAuth SSO Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-key me-2"></i> OAuth & SSO Login Providers</div>
                <div class="card-body">
                    <!-- MYETV OAuth -->
                    <h5>MYETV Integration</h5>
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[oauth_myetv_enabled]" value="0">
                        <input type="checkbox" name="settings[oauth_myetv_enabled]" value="1" class="form-check-input" id="myetvAuth" <?php echo get_setting($pdo, 'oauth_myetv_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="myetvAuth">Enable MYETV Login</label>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label">MYETV Client ID</label>
                            <input type="text" name="settings[oauth_myetv_client_id]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_myetv_client_id')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">MYETV Client Secret</label>
                            <input type="password" name="settings[oauth_myetv_client_secret]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_myetv_client_secret')); ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">OAuth Redirect Callback URL (Set in MYETV Developer Console)</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm bg-light" id="myetvCb" value="<?php echo htmlspecialchars($myetvCallbackUrl); ?>" readonly>
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToClipboard('myetvCb')"><i class="fa-regular fa-copy me-1"></i> Copy</button>
                        </div>
                    </div>
                    <hr>

                    <!-- Google OAuth -->
                    <h5>Google OAuth</h5>
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[oauth_google_enabled]" value="0">
                        <input type="checkbox" name="settings[oauth_google_enabled]" value="1" class="form-check-input" id="googleAuth" <?php echo get_setting($pdo, 'oauth_google_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="googleAuth">Enable Google Login</label>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Google Client ID</label>
                            <input type="text" name="settings[oauth_google_client_id]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_google_client_id')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Google Client Secret</label>
                            <input type="password" name="settings[oauth_google_client_secret]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_google_client_secret')); ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">Authorized Redirect URI (Set in Google Cloud Console)</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm bg-light" id="googleCb" value="<?php echo htmlspecialchars($googleCallbackUrl); ?>" readonly>
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToClipboard('googleCb')"><i class="fa-regular fa-copy me-1"></i> Copy</button>
                        </div>
                    </div>
                    <hr>

                    <!-- Facebook OAuth -->
                    <h5>Facebook OAuth</h5>
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[oauth_facebook_enabled]" value="0">
                        <input type="checkbox" name="settings[oauth_facebook_enabled]" value="1" class="form-check-input" id="fbAuth" <?php echo get_setting($pdo, 'oauth_facebook_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="fbAuth">Enable Facebook Login</label>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Facebook App ID</label>
                            <input type="text" name="settings[oauth_facebook_client_id]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_facebook_client_id')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Facebook App Secret</label>
                            <input type="password" name="settings[oauth_facebook_client_secret]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_facebook_client_secret')); ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">Valid OAuth Redirect URI (Set in Meta for Developers)</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm bg-light" id="fbCb" value="<?php echo htmlspecialchars($fbCallbackUrl); ?>" readonly>
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToClipboard('fbCb')"><i class="fa-regular fa-copy me-1"></i> Copy</button>
                        </div>
                    </div>
                    <hr>

                    <!-- Microsoft OAuth -->
                    <h5>Microsoft OAuth</h5>
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[oauth_microsoft_enabled]" value="0">
                        <input type="checkbox" name="settings[oauth_microsoft_enabled]" value="1" class="form-check-input" id="msAuth" <?php echo get_setting($pdo, 'oauth_microsoft_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="msAuth">Enable Microsoft Login</label>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Microsoft Application (Client) ID</label>
                            <input type="text" name="settings[oauth_microsoft_client_id]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_microsoft_client_id')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Microsoft Client Secret</label>
                            <input type="password" name="settings[oauth_microsoft_client_secret]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'oauth_microsoft_client_secret')); ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-muted small fw-bold">Redirect URI (Set in Azure Portal App Registration)</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm bg-light" id="msCb" value="<?php echo htmlspecialchars($msCallbackUrl); ?>" readonly>
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToClipboard('msCb')"><i class="fa-regular fa-copy me-1"></i> Copy</button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Artificial Intelligence Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-robot me-2"></i> Artificial Intelligence Assistant</div>
                <div class="card-body">
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[ai_enabled]" value="0">
                        <input type="checkbox" name="settings[ai_enabled]" value="1" class="form-check-input" id="enableAI" <?php echo get_setting($pdo, 'ai_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="enableAI">Enable AI Assistant</label>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">AI Provider</label>
                            <?php $aiProvider = get_setting($pdo, 'ai_provider', 'gemini'); ?>
                            <select name="settings[ai_provider]" class="form-select">
                                <option value="gemini" <?php echo $aiProvider === 'gemini' ? 'selected' : ''; ?>>Google Gemini API (Cloud)</option>
                                <option value="ollama" <?php echo $aiProvider === 'ollama' ? 'selected' : ''; ?>>Ollama (Local Open-Weight Models)</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-check mt-4 ms-2">
                            <input type="hidden" name="settings[ai_auto_respond]" value="0">
                            <input type="checkbox" name="settings[ai_auto_respond]" value="1" class="form-check-input" id="autoAI" <?php echo get_setting($pdo, 'ai_auto_respond') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="autoAI">Auto-Respond on New Ticket Creation</label>
                        </div>
                    </div>

                    <!-- Gemini Settings -->
                    <div class="border p-3 mb-3 bg-light rounded">
                        <h6 class="fw-bold"><i class="fa-brands fa-google me-1"></i> Google Gemini Settings</h6>
                        <div class="row">
                            <div class="col-md-8 mb-2">
                                <label class="form-label">Gemini API Key</label>
                                <input type="password" name="settings[ai_gemini_api_key]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_gemini_api_key')); ?>">
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="form-label">Gemini Model</label>
                                <input type="text" name="settings[ai_gemini_model]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_gemini_model', 'gemini-1.5-flash')); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Ollama Settings & Security Parameters -->
                    <div class="border p-3 mb-3 bg-light rounded">
                        <h6 class="fw-bold"><i class="fa-solid fa-server me-1"></i> Ollama Local Settings & Advanced Parameters</h6>
                        <div class="row mb-3">
                            <div class="col-md-8 mb-2">
                                <label class="form-label">Ollama Server Endpoint URL</label>
                                <input type="text" name="settings[ai_ollama_url]" class="form-control" placeholder="http://localhost:11434" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_ollama_url', 'http://localhost:11434')); ?>">
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="form-label">Ollama Model Name</label>
                                <input type="text" name="settings[ai_ollama_model]" class="form-control" placeholder="llama3, mistral, gemma" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_ollama_model', 'llama3')); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-2">
                                <label class="form-label">Context Window (num_ctx)</label>
                                <input type="number" name="settings[ai_ollama_num_ctx]" class="form-control" placeholder="4096" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_ollama_num_ctx', '4096')); ?>">
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="form-label">Temperature</label>
                                <input type="text" name="settings[ai_ollama_temperature]" class="form-control" placeholder="0.7" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_ollama_temperature', '0.7')); ?>">
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="form-label">Top K</label>
                                <input type="number" name="settings[ai_ollama_top_k]" class="form-control" placeholder="40" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_ollama_top_k', '40')); ?>">
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="form-label">Top P</label>
                                <input type="text" name="settings[ai_ollama_top_p]" class="form-control" placeholder="0.9" value="<?php echo htmlspecialchars(get_setting($pdo, 'ai_ollama_top_p', '0.9')); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Custom Instructions -->
                    <div class="mb-2">
                        <label class="form-label fw-bold">Custom Instructions & Knowledge Rules</label>
                        <textarea name="settings[ai_custom_instructions]" class="form-control" rows="4" placeholder="Specify custom rules for the AI (e.g. 'If asked about pricing, direct them to /pricing.php')..."><?php echo htmlspecialchars(get_setting($pdo, 'ai_custom_instructions')); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- RAG Knowledge Base Integration -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <div><i class="fa-solid fa-book-bookmark me-2"></i> RAG Knowledge Base (XML / JSON Remote Sources)</div>
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#ragFormatModal">
                        <i class="fa-solid fa-circle-question me-1"></i> Format Guide & Examples
                    </button>
                </div>
                <div class="card-body">
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[rag_enabled]" value="0">
                        <input type="checkbox" name="settings[rag_enabled]" value="1" class="form-check-input" id="enableRag" <?php echo get_setting($pdo, 'rag_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="enableRag">Enable RAG Document Retrieval for AI</label>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Knowledge Base Source 1 (XML / JSON)</label>
                            <input type="url" name="settings[rag_feed_url_1]" class="form-control" placeholder="https://example.com/feed.xml or https://example.com/api/posts" value="<?php echo htmlspecialchars(get_setting($pdo, 'rag_feed_url_1', get_setting($pdo, 'rag_tos_feed_url', ''))); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Knowledge Base Source 2 (XML / JSON - Optional)</label>
                            <input type="url" name="settings[rag_feed_url_2]" class="form-control" placeholder="https://example.com/policies.json" value="<?php echo htmlspecialchars(get_setting($pdo, 'rag_feed_url_2', get_setting($pdo, 'rag_privacy_feed_url', ''))); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Auto-Sync Interval (Hours)</label>
                            <input type="number" min="1" max="168" name="settings[rag_sync_interval_hours]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'rag_sync_interval_hours', '24')); ?>">
                            <div class="form-text">Verified automatically on demand when tickets arrive, without requiring background cronjobs.</div>
                        </div>
                        <div class="col-md-8 mb-3 d-flex align-items-center">
                            <div class="border rounded p-3 bg-light w-100">
                                <div class="small"><strong>Indexed Documents:</strong> <?php echo (int)$ragItemCount; ?> items</div>
                                <div class="small"><strong>Last Synchronized:</strong> <?php echo htmlspecialchars($lastSyncFormatted); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Card Footer with Sync Action & Validation Notice -->
                <div class="card-footer bg-light d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <button type="submit" form="ragSyncForm" class="btn btn-primary" <?php echo !$hasConfiguredFeed ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-rotate me-1"></i> Force Sync Knowledge Base Now
                        </button>
                    </div>
                    <div class="small text-muted">
                        <?php if (!$hasConfiguredFeed): ?>
                            <i class="fa-solid fa-circle-exclamation text-danger me-1"></i>
                            Save at least one Knowledge Base URL first before synchronizing.
                        <?php else: ?>
                            <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>
                            Remember: if you modified the URLs above, click "Save Settings" below before syncing.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Turnstile Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-secondary text-white">Cloudflare Turnstile</div>
                <div class="card-body">
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[turnstile_enabled]" value="0">
                        <input type="checkbox" name="settings[turnstile_enabled]" value="1" class="form-check-input" id="enableTurnstile" <?php echo get_setting($pdo, 'turnstile_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enableTurnstile">Enable Turnstile Captcha</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Site Key</label>
                        <input type="text" name="settings[turnstile_site_key]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'turnstile_site_key')); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Secret Key</label>
                        <input type="password" name="settings[turnstile_secret_key]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'turnstile_secret_key')); ?>">
                    </div>
                </div>
            </div>

            <!-- SMTP Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-secondary text-white">SMTP Email Settings</div>
                <div class="card-body">
                    <div class="mb-3 form-check">
                        <input type="hidden" name="settings[smtp_enabled]" value="0">
                        <input type="checkbox" name="settings[smtp_enabled]" value="1" class="form-check-input" id="enableSMTP" <?php echo get_setting($pdo, 'smtp_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enableSMTP">Enable Custom SMTP Server</label>
                    </div>
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="settings[smtp_host]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'smtp_host')); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">SMTP Encryption</label>
                            <?php $crypto = get_setting($pdo, 'smtp_crypto', 'tls'); ?>
                            <select name="settings[smtp_crypto]" class="form-select">
                                <option value="tls" <?php echo ($crypto === 'tls' || $crypto === 'starttls') ? 'selected' : ''; ?>>STARTTLS (Recommended / Port 587)</option>
                                <option value="ssl" <?php echo $crypto === 'ssl' ? 'selected' : ''; ?>>SSL / SMTPS (Port 465)</option>
                                <option value="none" <?php echo $crypto === 'none' ? 'selected' : ''; ?>>None / Plaintext (Port 25)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">SMTP Port</label>
                            <input type="text" name="settings[smtp_port]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'smtp_port', '587')); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SMTP Username</label>
                            <input type="text" name="settings[smtp_user]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'smtp_user')); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SMTP Password</label>
                            <input type="password" name="settings[smtp_pass]" class="form-control" value="<?php echo htmlspecialchars(get_setting($pdo, 'smtp_pass')); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">From Email Address (Optional)</label>
                        <input type="email" name="settings[smtp_from_email]" class="form-control" placeholder="no-reply@yourdomain.com" value="<?php echo htmlspecialchars(get_setting($pdo, 'smtp_from_email')); ?>">
                        <div class="form-text">If left empty, the SMTP Username will be used as the sender address.</div>
                    </div>
                </div>
            </div>

            <!-- LibreTranslate API Settings -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-secondary text-white"><i class="fa-solid fa-language me-2"></i> LibreTranslate Automatic Translation Server</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">LibreTranslate Endpoint URL</label>
                            <input type="url" name="settings[libretranslate_url]" class="form-control" placeholder="http://localhost:5000 o https://libretranslate.com" value="<?php echo htmlspecialchars(get_setting($pdo, 'libretranslate_url', 'https://libretranslate.com')); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">API Key (Optional)</label>
                            <input type="password" name="settings[libretranslate_api_key]" class="form-control" placeholder="Leave blank if not required" value="<?php echo htmlspecialchars(get_setting($pdo, 'libretranslate_api_key', '')); ?>">
                        </div>
                    </div>
                    <div class="form-text text-muted">Tickets and replies can be translated on demand into the visitor's currently selected language and cached locally in MySQL.</div>
                </div>
            </div>

            <!-- Dynamic Plugin Settings Hook Injection -->
            <?php trigger_hook('admin_settings_form'); ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success btn-lg"><i class="fa-solid fa-floppy-disk me-1"></i> Save Settings</button>
            </div>
        </form>
    </div>
</main>

<!-- RAG Knowledge Base Format Documentation Modal -->
<div class="modal fade" id="ragFormatModal" tabindex="-1" aria-labelledby="ragFormatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="ragFormatModalLabel"><i class="fa-solid fa-file-code me-2"></i> Knowledge Base Feed Formatting Guide</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>The RAG ingestion crawler accepts public HTTP/HTTPS URLs delivering either <strong>XML</strong> or <strong>JSON</strong> text payloads. Regular HTML pages, binary files, PDFs, or image links are not supported.</p>

                <!-- Section 1: JSON Feeds & APIs -->
                <h6 class="fw-bold text-primary mt-3"><i class="fa-solid fa-brackets-curly me-1"></i> 1. Generic JSON Feeds & REST APIs</h6>
                <p class="small text-muted mb-2">The endpoint can return an array of objects or a single object. Supported properties are automatically recognized:</p>
                
                <div class="border rounded p-2 bg-light mb-2">
                    <span class="badge bg-secondary mb-1">Generic List Example</span>
                    <pre class="mb-0 small font-monospace"><code>[
  {
    "id": "item-101",
    "title": "Refund and Billing Policy",
    "content": "All subscription fees are non-refundable after 14 days of purchase..."
  },
  {
    "id": "item-102",
    "title": "Account Termination",
    "body": "Users may cancel their account at any moment through profile settings..."
  }
]</code></pre>
                </div>

                <div class="border rounded p-2 bg-light mb-3">
                    <span class="badge bg-secondary mb-1">REST API Nested Example (e.g. Headless CMS / WordPress)</span>
                    <pre class="mb-0 small font-monospace"><code>[
  {
    "id": 45,
    "slug": "privacy-policy",
    "title": { "rendered": "Privacy Policy" },
    "content": { "rendered": "&lt;p&gt;We respect your personal information...&lt;/p&gt;" }
  }
]</code></pre>
                </div>

                <p class="small mb-3"><strong>Recognized JSON property keys:</strong>
                    <br>&bull; <strong>Identifier:</strong> <code>id</code>, <code>guid</code>, <code>slug</code>, or <code>key</code>
                    <br>&bull; <strong>Title:</strong> <code>title</code>, <code>name</code>, <code>subject</code>, or <code>heading</code> (or <code>title.rendered</code>)
                    <br>&bull; <strong>Content:</strong> <code>content</code>, <code>body</code>, <code>text</code>, <code>description</code>, or <code>excerpt</code> (or <code>content.rendered</code>)
                </p>

                <hr>

                <!-- Section 2: XML Feeds -->
                <h6 class="fw-bold text-primary"><i class="fa-solid fa-rss me-1"></i> 2. XML Feeds (RSS 2.0 or Atom)</h6>
                <p class="small text-muted mb-2">Standard RSS 2.0 or Atom feeds are parsed directly:</p>

                <div class="border rounded p-2 bg-light mb-2">
                    <pre class="mb-0 small font-monospace"><code>&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;rss version="2.0"&gt;
  &lt;channel&gt;
    &lt;title&gt;Official Platform Documentation&lt;/title&gt;
    &lt;item&gt;
      &lt;guid&gt;tos-section-1&lt;/guid&gt;
      &lt;title&gt;Terms of Service - General Usage&lt;/title&gt;
      &lt;description&gt;&lt;![CDATA[You agree not to upload abusive or harmful media...]]&gt;&lt;/description&gt;
    &lt;/item&gt;
  &lt;/channel&gt;
&lt;/rss&gt;</code></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    function copyToClipboard(elementId) {
        const copyText = document.getElementById(elementId);
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
