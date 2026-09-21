<?php
// admin/users.php
// User Management Page with Account Approval System, API Key Revocation, Ban Control, and Modal Settings
session_start();
require_once __DIR__ . '/../includes/config.php';

// Ensure user is authorized as Admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: /admin/dashboard.php");
    exit;
}

$message = '';
$error = '';

// Process Account Approval / Revocation Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_approval') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_approved = CASE WHEN is_approved = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $message = "User approval status updated successfully.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Process API Key Toggle (Revoke / Activate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_api_key') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE api_keys 
                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                    revoked_at = CASE WHEN is_active = 1 THEN NOW() ELSE NULL END
                WHERE user_id = ?
            ");
            $stmt->execute([$targetUserId]);
            $message = "User API key status updated successfully.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Process Ban / Unban Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_ban') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId <= 0) {
        $error = "Invalid user selection.";
    } elseif ($targetUserId === (int)$_SESSION['user_id']) {
        $error = "You cannot ban your own active Admin account.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_banned = CASE WHEN is_banned = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $message = "User ban status updated successfully.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Process Role, Agency & Auto-Assign Update Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user_settings') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newRole      = trim($_POST['role'] ?? '');
    $agencyId     = (!empty($_POST['agency_id']) && !in_array($newRole, ['agency', 'admin'], true)) ? (int)$_POST['agency_id'] : null;
    $autoAssign   = isset($_POST['auto_assign_tickets']) ? 1 : 0;
    $allowedRoles = ['user', 'agent', 'agency', 'admin'];

    if ($targetUserId <= 0 || !in_array($newRole, $allowedRoles, true)) {
        $error = "Invalid user or role selection.";
    } elseif ($targetUserId === (int)$_SESSION['user_id'] && $newRole !== 'admin') {
        $error = "You cannot demote your own active Admin account.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET role = ?, agency_id = ?, auto_assign_tickets = ? WHERE id = ?");
            $stmt->execute([$newRole, $agencyId, $autoAssign, $targetUserId]);
            $message = "User settings updated successfully.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch all registered agencies for the dropdown list
$stmtAgencies = $pdo->query("SELECT id, username FROM users WHERE role = 'agency' ORDER BY username ASC");
$agenciesList = $stmtAgencies->fetchAll();

// Fetch users with their agency name, ban status, and API key details
$stmtUsers = $pdo->query("
    SELECT u.id, u.username, u.email, u.role, u.agency_id, u.auto_assign_tickets, u.is_banned, COALESCE(u.is_approved, 1) AS is_approved, u.auth_provider, u.two_factor_enabled, u.created_at,
           ag.username AS agency_name,
           ak.id AS api_key_id, ak.api_key, ak.is_active AS api_key_active
    FROM users u
    LEFT JOIN users ag ON u.agency_id = ag.id
    LEFT JOIN api_keys ak ON u.id = ak.user_id
    ORDER BY u.is_approved ASC, u.id DESC
");
$users = $stmtUsers->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<main class="main-content">
    <div class="container-fluid my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="fa-solid fa-users-gear me-2"></i> User & Agency Management</h2>
        </div>
        <hr>

        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="usersTable" class="table table-striped table-hover align-middle w-100">
                        <thead class="table-dark">
                            <tr>
                                <th>Username / Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Approval Status</th>
                                <th>API Key</th>
                                <th>Assigned Agency</th>
                                <th>Auto-Assign</th>
                                <th>Registered At</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($u['username'] ?? 'N/A'); ?></strong>
                                        <?php if (!empty($u['is_banned'])): ?>
                                            <span class="badge bg-danger ms-1">BANNED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td>
                                        <?php
                                        $badgeStyle = match($u['role']) {
                                            'admin'  => 'class="badge bg-danger"',
                                            'agency' => 'class="badge bg-primary text-white"',
                                            'agent'  => 'class="badge bg-warning text-dark"',
                                            default  => 'class="badge bg-info text-dark"'
                                        };
                                        ?>
                                        <span <?php echo $badgeStyle; ?>><?php echo strtoupper($u['role']); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['is_approved'])): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i> Pending Approval</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['api_key_id'])): ?>
                                            <?php if (!empty($u['api_key_active'])): ?>
                                                <span class="badge bg-success" title="<?php echo htmlspecialchars($u['api_key']); ?>"><i class="fa-solid fa-key me-1"></i> Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="fa-solid fa-ban me-1"></i> Revoked</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['role'] === 'agency'): ?>
                                            <span class="text-muted small"><em>N/A (Agency)</em></span>
                                        <?php elseif ($u['agency_name']): ?>
                                            <span class="badge bg-secondary"><i class="fa-solid fa-building me-1"></i> <?php echo htmlspecialchars($u['agency_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['auto_assign_tickets'])): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-robot me-1"></i> Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo htmlspecialchars(date('Y-m-d', strtotime($u['created_at']))); ?></small></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- Revoke / Restore API Key (if key exists) -->
                                            <?php if (!empty($u['api_key_id'])): ?>
                                                <form method="POST" action="users.php" class="d-inline m-0">
                                                    <input type="hidden" name="action" value="toggle_api_key">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                    <button type="submit" class="btn btn-sm <?php echo !empty($u['api_key_active']) ? 'btn-outline-danger' : 'btn-outline-success'; ?>" title="<?php echo !empty($u['api_key_active']) ? 'Revoke API Key' : 'Restore API Key'; ?>">
                                                        <i class="fa-solid <?php echo !empty($u['api_key_active']) ? 'fa-key' : 'fa-key'; ?> text-decoration-line-through"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Approve / Revoke Approval Button -->
                                            <form method="POST" action="users.php" class="d-inline m-0">
                                                <input type="hidden" name="action" value="toggle_approval">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo empty($u['is_approved']) ? 'btn-success' : 'btn-outline-warning'; ?>" title="<?php echo empty($u['is_approved']) ? 'Approve Account' : 'Revoke Approval'; ?>">
                                                    <i class="fa-solid <?php echo empty($u['is_approved']) ? 'fa-user-check' : 'fa-user-clock'; ?>"></i>
                                                </button>
                                            </form>

                                            <!-- User Settings Modal Trigger Button -->
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#userModal<?php echo (int)$u['id']; ?>" title="Configure User Settings">
                                                <i class="fa-solid fa-user-gear"></i>
                                            </button>

                                            <!-- Ban / Unban Button -->
                                            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                                <form method="POST" action="users.php" class="d-inline m-0">
                                                    <input type="hidden" name="action" value="toggle_ban">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                    <button type="submit" class="btn btn-sm <?php echo !empty($u['is_banned']) ? 'btn-success' : 'btn-outline-danger'; ?>" title="<?php echo !empty($u['is_banned']) ? 'Unban User' : 'Ban User'; ?>" onclick="return confirm('Change ban status for this user?');">
                                                        <i class="fa-solid <?php echo !empty($u['is_banned']) ? 'fa-user-check' : 'fa-user-slash'; ?>"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <!-- User Settings Modal -->
                                        <div class="modal fade text-start" id="userModal<?php echo (int)$u['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <form method="POST" action="users.php">
                                                        <input type="hidden" name="action" value="update_user_settings">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">

                                                        <div class="modal-header bg-dark text-white">
                                                            <h5 class="modal-title">
                                                                <i class="fa-solid fa-user-gear me-2"></i> Settings for <?php echo htmlspecialchars($u['username'] ?? 'User'); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>

                                                        <div class="modal-body">
                                                            <!-- Role Selection -->
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">User System Role</label>
                                                                <select name="role" class="form-select role-select" data-user-id="<?php echo (int)$u['id']; ?>">
                                                                    <option value="user" <?php echo $u['role'] === 'user' ? 'selected' : ''; ?>>User (Client)</option>
                                                                    <option value="agent" <?php echo $u['role'] === 'agent' ? 'selected' : ''; ?>>Agent (Support Staff)</option>
                                                                    <option value="agency" <?php echo $u['role'] === 'agency' ? 'selected' : ''; ?>>Agency (Agency Manager)</option>
                                                                    <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Admin (Full System Control)</option>
                                                                </select>
                                                            </div>

                                                            <!-- Agency Assignment -->
                                                            <div class="mb-3 agency-group" id="agency_group_<?php echo (int)$u['id']; ?>" style="<?php echo in_array($u['role'], ['agency', 'admin'], true) ? 'display: none;' : ''; ?>">
                                                                <label class="form-label fw-bold">Assigned Agency</label>
                                                                <select name="agency_id" class="form-select">
                                                                    <option value="">-- Independent / No Agency --</option>
                                                                    <?php foreach ($agenciesList as $ag): ?>
                                                                        <?php if ((int)$ag['id'] !== (int)$u['id']): ?>
                                                                            <option value="<?php echo $ag['id']; ?>" <?php echo (int)$u['agency_id'] === (int)$ag['id'] ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($ag['username']); ?>
                                                                            </option>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div class="form-text">Associates this staff member/user with a specific managing agency.</div>
                                                            </div>

                                                            <!-- Auto-Assignment Switch -->
                                                            <div class="border rounded p-3 bg-light mb-2">
                                                                <div class="form-check form-switch m-0">
                                                                    <input class="form-check-input" type="checkbox" name="auto_assign_tickets" value="1" id="autoAssignModal<?php echo (int)$u['id']; ?>" <?php echo !empty($u['auto_assign_tickets']) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label fw-bold ms-2" for="autoAssignModal<?php echo (int)$u['id']; ?>">
                                                                        Automatic Ticket Assignment
                                                                    </label>
                                                                </div>
                                                                <p class="text-muted small mt-2 mb-0">
                                                                    When enabled, new incoming support tickets will be automatically routed and assigned to this account upon submission.
                                                                </p>
                                                            </div>
                                                        </div>

                                                        <div class="modal-footer py-2">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#usersTable').DataTable({
            "order": [[ 3, "asc" ]],
            "pageLength": 10,
            "language": {
                "search": "Filter users:"
            }
        });

        $('.role-select').on('change', function() {
            const userId = $(this).data('user-id');
            const selectedRole = $(this).val();
            const agencyGroup = $('#agency_group_' + userId);

            if (selectedRole === 'agency' || selectedRole === 'admin') {
                agencyGroup.find('select').val('');
                agencyGroup.hide();
            } else {
                agencyGroup.show();
            }
        });
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
