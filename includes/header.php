<?php
// includes/header.php
// Header with Bootstrap 5.3 Theme Switcher, Branding logo, Custom Colors, Language selector, Header Code Injection, and DataTables for Notifications
require_once __DIR__ . '/config.php';
$siteTitle = get_setting($pdo, 'site_title', 'My Tickets Manager');
$availableLangs = get_available_languages();
global $currentLang;

// Fetch custom theme settings
$logoUrl     = get_setting($pdo, 'theme_logo_url', '');
$headerBg    = get_setting($pdo, 'theme_header_bg', '#212529');
$headerText  = get_setting($pdo, 'theme_header_text', '#ffffff');
$sidebarBg   = get_setting($pdo, 'theme_sidebar_bg', '#212529');
$sidebarText = get_setting($pdo, 'theme_sidebar_text', '#f8f9fa');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>" data-bs-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteTitle); ?></title>
    
    <!-- Early theme initialization to avoid page flash -->
    <script>
        (function() {
            const storedTheme = localStorage.getItem('theme') || 'auto';
            if (storedTheme === 'auto') {
                const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-bs-theme', systemDark ? 'dark' : 'light');
            } else {
                document.documentElement.setAttribute('data-bs-theme', storedTheme);
            }
        })();
    </script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    
    <!-- DataTables Bootstrap 5 CSS -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <!-- jQuery and DataTables JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Cloudflare Turnstile API Script -->
    <?php if (get_setting($pdo, 'turnstile_enabled', '0') === '1'): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>

    <style>
        /* Base Flexbox Layout */
        body { min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
        .wrapper { display: flex; flex: 1; align-items: stretch; width: 100%; }
        .main-content { flex: 1; padding: 20px; min-width: 0; }

        /* Custom Header Styling */
        header.navbar-custom {
            background-color: <?php echo htmlspecialchars($headerBg); ?> !important;
            color: <?php echo htmlspecialchars($headerText); ?> !important;
        }
        header.navbar-custom .nav-link,
        header.navbar-custom .navbar-brand,
        header.navbar-custom #sidebarToggle {
            color: <?php echo htmlspecialchars($headerText); ?> !important;
        }

        /* Dynamic Sidebar Styling with Custom Colors */
        #sidebar-wrapper {
            width: 240px;
            transition: width 0.3s ease;
            white-space: nowrap;
            overflow: hidden;
            flex-shrink: 0;
            background-color: <?php echo htmlspecialchars($sidebarBg); ?> !important;
            color: <?php echo htmlspecialchars($sidebarText); ?> !important;
        }

        #sidebar-wrapper .list-group-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            color: <?php echo htmlspecialchars($sidebarText); ?> !important;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        #sidebar-wrapper .list-group-item:hover {
            background-color: rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
        }

        #sidebar-wrapper .list-group-item i {
            width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        #sidebar-wrapper .sidebar-header {
            color: #0dcaf0 !important;
            letter-spacing: 0.5px;
        }

        /* Collapsed Sidebar State */
        body.sidebar-collapsed #sidebar-wrapper {
            width: 65px;
        }

        body.sidebar-collapsed #sidebar-wrapper .link-text,
        body.sidebar-collapsed #sidebar-wrapper .sidebar-header {
            display: none !important;
        }

        /* Responsive behavior: collapsed by default on small screens */
        @media (max-width: 768px) {
            #sidebar-wrapper {
                width: 65px;
            }
            #sidebar-wrapper .link-text,
            #sidebar-wrapper .sidebar-header {
                display: none !important;
            }
            body.sidebar-expanded #sidebar-wrapper {
                width: 240px;
            }
            body.sidebar-expanded #sidebar-wrapper .link-text,
            body.sidebar-expanded #sidebar-wrapper .sidebar-header {
                display: inline-block !important;
            }
        }

        /* DataTables Modal Styling Adjustments */
        #notificationsTable_wrapper .dataTables_paginate .pagination {
            margin-bottom: 0;
            font-size: 0.85rem;
        }
        #notificationsTable_wrapper .dataTables_info,
        #notificationsTable_wrapper .dataTables_length,
        #notificationsTable_wrapper .dataTables_filter {
            font-size: 0.85rem;
        }
    </style>
    
    <!-- Custom Header Injection -->
    <?php 
    $headerInjection = get_setting($pdo, 'inject_header', '');
    if (!empty($headerInjection)) {
        echo $headerInjection . "\n";
    }
    ?>
</head>
<body>
    <header class="navbar navbar-custom sticky-top p-2 shadow">
        <div class="container-fluid">
            <!-- Sidebar Toggle Hamburger Button -->
            <button id="sidebarToggle" class="btn text-white me-2 border-0 bg-transparent" type="button">
                <i class="fa-solid fa-bars fs-5"></i>
            </button>

            <!-- Brand Logo or Text Title -->
            <a class="navbar-brand me-0 px-2 fs-6 d-flex align-items-center" href="/">
                <?php if (!empty($logoUrl)): ?>
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($siteTitle); ?>" style="max-height: 40px; width: auto;" class="img-fluid">
                <?php else: ?>
                    <span><?php echo htmlspecialchars($siteTitle); ?></span>
                <?php endif; ?>
            </a>
            
            <div class="d-flex align-items-center ms-auto gap-3">
                
                <!-- Bootstrap 5 Theme Switcher Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle border-secondary" type="button" id="themeDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-circle-half-stroke me-1" id="themeIcon"></i>
                        <span id="themeLabel"><?php echo __('theme_auto', 'Auto'); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="themeDropdown">
                        <li>
                            <button class="dropdown-item d-flex align-items-center" type="button" data-bs-theme-value="light">
                                <i class="fa-solid fa-sun me-2 text-warning"></i> <?php echo __('theme_light', 'Light'); ?>
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item d-flex align-items-center" type="button" data-bs-theme-value="dark">
                                <i class="fa-solid fa-moon me-2 text-primary"></i> <?php echo __('theme_dark', 'Dark'); ?>
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item d-flex align-items-center" type="button" data-bs-theme-value="auto">
                                <i class="fa-solid fa-circle-half-stroke me-2 text-secondary"></i> <?php echo __('theme_system_auto', 'System Auto'); ?>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Notifications Bell Icon Dropdown / Modal Trigger (For Logged Staff) -->
                <?php if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['admin', 'agency', 'agent'], true)): ?>
                    <button type="button" class="btn btn-outline-light btn-sm position-relative me-2 border-secondary" id="notifBellBtn" data-bs-toggle="modal" data-bs-target="#notificationsModal">
                        <i class="fa-solid fa-bell"></i>
                        <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">
                            0
                        </span>
                    </button>
                <?php endif; ?>

                <!-- Language Selector Dropdown -->
                <form method="GET" class="m-0">
                    <select name="lang" class="form-select form-select-sm bg-transparent text-white border-secondary" onchange="this.form.submit()">
                        <?php foreach ($availableLangs as $langCode): ?>
                            <option value="<?php echo $langCode; ?>" class="bg-dark text-white" <?php echo $currentLang === $langCode ? 'selected' : ''; ?>>
                                <?php echo strtoupper($langCode); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <div class="navbar-nav flex-row">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a class="nav-link px-2" href="/logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i> <?php echo __('logout', 'Logout'); ?></a>
                    <?php else: ?>
                        <a class="nav-link px-2" href="/login.php"><i class="fa-solid fa-right-to-bracket me-1"></i> <?php echo __('login', 'Login'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Internal Notifications Modal -->
    <div class="modal fade" id="notificationsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content text-start">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-bell me-2 text-warning"></i> <?php echo __('notifications', 'Notifications'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="table-responsive">
                        <table id="notificationsTable" class="table table-striped table-hover align-middle w-100">
                            <thead>
                                <tr>
                                    <th style="width: 10%;"><?php echo __('status', 'Status'); ?></th>
                                    <th style="width: 25%;"><?php echo __('title', 'Title'); ?></th>
                                    <th style="width: 40%;"><?php echo __('message', 'Message'); ?></th>
                                    <th style="width: 15%;"><?php echo __('date', 'Date'); ?></th>
                                    <th style="width: 10%;" class="text-center"><?php echo __('action', 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2 justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMarkAllRead"><i class="fa-solid fa-check-double me-1"></i> <?php echo __('mark_all_read', 'Mark All as Read'); ?></button>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php echo __('close', 'Close'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const notifBadge = document.getElementById('notifBadge');
        const btnMarkRead = document.getElementById('btnMarkAllRead');
        const notifModalEl = document.getElementById('notificationsModal');
        let notifDataTable = null;

        // Initialize DataTables on the notifications table
        if ($('#notificationsTable').length) {
            notifDataTable = $('#notificationsTable').DataTable({
                data: [],
                columns: [
                    { data: 'status' },
                    { data: 'title' },
                    { data: 'message' },
                    { data: 'created_at' },
                    { data: 'action', orderable: false, searchable: false, className: 'text-center' }
                ],
                order: [[3, 'desc']],
                pageLength: 5,
                lengthMenu: [5, 10, 25, 50],
                autoWidth: false
            });
        }

        // Adjust DataTables column widths when the modal is fully shown
        if (notifModalEl) {
            notifModalEl.addEventListener('shown.bs.modal', function() {
                if (notifDataTable) {
                    notifDataTable.columns.adjust().draw();
                }
            });
        }

        function fetchNotifications() {
            fetch('/api/notifications.php?action=get')
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;

                    // Update Badge count
                    if (data.unread_count > 0) {
                        notifBadge.textContent = data.unread_count;
                        notifBadge.classList.remove('d-none');
                    } else {
                        notifBadge.classList.add('d-none');
                    }

                    // Populate DataTables rows
                    if (notifDataTable) {
                        const formattedRows = data.items.map(item => {
                            const isUnread = item.is_read == '0';
                            const statusBadge = isUnread 
                                ? '<span class="badge bg-danger"><?php echo addslashes(__('unread', 'Unread')); ?></span>' 
                                : '<span class="badge bg-secondary"><?php echo addslashes(__('read', 'Read')); ?></span>';

                            const trackUrl = `/track.php?code=${encodeURIComponent(item.tracking_code || '')}&token=${encodeURIComponent(item.access_token || '')}`;
                            const actionBtn = `<a href="${trackUrl}" class="btn btn-sm btn-outline-primary" title="<?php echo addslashes(__('view', 'View')); ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>`;

                            return {
                                status: statusBadge,
                                title: `<span class="${isUnread ? 'fw-bold' : ''}">${item.title}</span>`,
                                message: `<small class="text-body-secondary">${item.message}</small>`,
                                created_at: `<small>${item.created_at}</small>`,
                                action: actionBtn
                            };
                        });

                        notifDataTable.clear().rows.add(formattedRows).draw(false);
                    }
                }).catch(() => {});
        }

        if (document.getElementById('notifBellBtn')) {
            fetchNotifications();
            setInterval(fetchNotifications, 15000); // Polling every 15s

            if (btnMarkRead) {
                btnMarkRead.addEventListener('click', function() {
                    fetch('/api/notifications.php?action=mark_read')
                        .then(res => res.json())
                        .then(() => fetchNotifications());
                });
            }
        }
    });
    </script>

    <div class="wrapper">
