<?php
// migrate.php
// Safe incremental database migration script executed automatically during system updates

require_once __DIR__ . '/includes/config.php';

// Force global scope for $pdo variable when called inside functions/updaters
global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Attempt fallback PDO instantiation if not globally set
    if (isset($db_host, $db_port, $db_name, $db_user, $db_pass)) {
        try {
            $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (Exception $e) {
            error_log("Migration PDO Connection Error: " . $e->getMessage());
            return;
        }
    } else {
        return; // Silent exit to allow updater completion
    }
}

/**
 * Helper: Check if a table exists in the database
 */
if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Helper: Check if a column exists in a given table
 */
if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Helper: Check if an index exists in a given table
 */
if (!function_exists('index_exists')) {
    function index_exists(PDO $pdo, string $table, string $indexName): bool {
        try {
            $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
            $stmt->execute([$indexName]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
}

try {
    // --- INCREMENTAL DB MIGRATIONS HERE ---

    // v1.0.39: Create Remember Me Tokens table for persistent login
    if (!table_exists($pdo, 'user_remember_tokens')) {
        $pdo->exec("
            CREATE TABLE `user_remember_tokens` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `selector` VARCHAR(24) NOT NULL,
              `token_hash` VARCHAR(64) NOT NULL,
              `expires_at` DATETIME NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_selector` (`selector`),
              KEY `idx_user_id` (`user_id`),
              CONSTRAINT `fk_remember_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // v1.0.38: Create API Keys Table for Agent REST API integration
    if (!table_exists($pdo, 'api_keys')) {
        $pdo->exec("
            CREATE TABLE `api_keys` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `key_name` VARCHAR(100) DEFAULT 'Agent API Key',
              `api_key` VARCHAR(64) NOT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT '1',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `revoked_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_api_key` (`api_key`),
              UNIQUE KEY `uk_user_api_key` (`user_id`),
              CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // v1.0.28: Create Translations Cache Table for LibreTranslate on-demand caching
    if (!table_exists($pdo, 'translations_cache')) {
        $pdo->exec("
            CREATE TABLE `translations_cache` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `item_type` ENUM('ticket_message', 'reply_message') NOT NULL,
              `item_id` INT UNSIGNED NOT NULL,
              `source_lang` VARCHAR(10) DEFAULT 'auto',
              `target_lang` VARCHAR(10) NOT NULL,
              `translated_text` MEDIUMTEXT NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_item_lang` (`item_type`, `item_id`, `target_lang`),
              KEY `idx_item` (`item_type`, `item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // v1.0.27: Add is_approved column to users table if not exists
    if (!column_exists($pdo, 'users', 'is_approved')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_approved` TINYINT(1) NOT NULL DEFAULT '1' AFTER `is_banned`");
    }

    // v1.0.19: Create Notifications Table for Internal Platform Alerts
    if (!table_exists($pdo, 'notifications')) {
        $pdo->exec("
            CREATE TABLE `notifications` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `ticket_id` INT UNSIGNED NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `message` TEXT NOT NULL,
              `is_read` TINYINT(1) NOT NULL DEFAULT '0',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_notif_user_read` (`user_id`, `is_read`),
              CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_notif_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // v1.0.19: Create Ticket Followers Table (Agent Follow/Unfollow system)
    if (!table_exists($pdo, 'ticket_followers')) {
        $pdo->exec("
            CREATE TABLE `ticket_followers` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `ticket_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_ticket_follower` (`ticket_id`, `user_id`),
              CONSTRAINT `fk_tf_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_tf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // v1.0.17: Add is_banned column to users table
    if (!column_exists($pdo, 'users', 'is_banned')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_banned` TINYINT(1) NOT NULL DEFAULT '0' AFTER `two_factor_enabled`");
    }

    // v1.0.16: Add auto_assign_tickets column for automatic ticket routing
    if (!column_exists($pdo, 'users', 'auto_assign_tickets')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `auto_assign_tickets` TINYINT(1) NOT NULL DEFAULT '0'");
    }

    // v1.0.5: Add agency_id to users table for Agency role support
    if (!column_exists($pdo, 'users', 'agency_id')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `agency_id` INT UNSIGNED DEFAULT NULL");
        $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
    }

    // v1.0.2: Add Password Reset Tokens
    if (!column_exists($pdo, 'users', 'reset_token')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL, ADD COLUMN `reset_token_expires` DATETIME DEFAULT NULL");
    }

    // v1.0.5: Create Rate Limits Table for Brute-Force and Spam Protection
    if (!table_exists($pdo, 'rate_limits')) {
        $pdo->exec("
            CREATE TABLE `rate_limits` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `action_key` VARCHAR(50) NOT NULL,
              `ip_address` VARCHAR(45) NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_action_ip_created` (`action_key`, `ip_address`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

} catch (Exception $e) {
    error_log("Database Migration Error: " . $e->getMessage());
}
