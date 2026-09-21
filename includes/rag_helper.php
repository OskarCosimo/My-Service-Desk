<?php
// includes/rag_helper.php
// RAG (Retrieval-Augmented Generation) Helper with RSS ingestion and MySQL Fulltext retrieval

require_once __DIR__ . '/config.php';

/**
 * Sync RSS feeds if cache has expired or force is requested
 *
 * @param PDO $pdo
 * @param bool $force
 * @return array Sync summary status
 */
function sync_rag_feeds(PDO $pdo, bool $force = false): array {
    $syncInterval = (int)get_setting($pdo, 'rag_sync_interval_hours', '24') * 3600;
    $lastSync     = (int)get_setting($pdo, 'rag_last_sync_time', '0');

    if (!$force && (time() - $lastSync < $syncInterval)) {
        return ['synced' => false, 'reason' => 'Cache still valid'];
    }

    // Support generic keys with fallback to previous keys if already stored
    $feed1 = trim(get_setting($pdo, 'rag_feed_url_1', get_setting($pdo, 'rag_tos_feed_url', '')));
    $feed2 = trim(get_setting($pdo, 'rag_feed_url_2', get_setting($pdo, 'rag_privacy_feed_url', '')));

    $feeds = [
        'feed_1' => $feed1,
        'feed_2' => $feed2
    ];

    $totalImported = 0;

    foreach ($feeds as $sourceType => $url) {
        if (empty($url)) {
            continue;
        }

        $xmlContent = fetch_feed_content($url);
        if (!$xmlContent) {
            continue;
        }

        // Suppress XML parsing errors and handle gracefully
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            libxml_clear_errors();
            continue;
        }

        // Handle RSS 2.0 items or Atom entries
        $items = isset($xml->channel->item) ? $xml->channel->item : (isset($xml->entry) ? $xml->entry : []);

        foreach ($items as $item) {
            $guid  = (string)($item->guid ?? $item->id ?? $item->link ?? md5((string)$item->title));
            $title = trim((string)$item->title);

            // Check standard description or content:encoded (Atom / RSS)
            $content = (string)$item->description;
            if (isset($item->children('content', true)->encoded)) {
                $content = (string)$item->children('content', true)->encoded;
            } elseif (isset($item->content)) {
                $content = (string)$item->content;
            }

            // Normalize content text
            $cleanContent = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if (empty($cleanContent) && empty($title)) {
                continue;
            }

            // Store or update record in database
            $stmt = $pdo->prepare("
                INSERT INTO rag_knowledge (source_type, feed_url, item_guid, title, content, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title),
                    content = VALUES(content),
                    updated_at = NOW()
            ");
            $stmt->execute([$sourceType, $url, $guid, $title, $cleanContent]);
            $totalImported++;
        }
    }

    // Update last sync time
    $stmtUpdateSync = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rag_last_sync_time', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmtUpdateSync->execute([time()]);

    return ['synced' => true, 'total_items' => $totalImported];
}

/**
 * Helper to fetch content over HTTP/HTTPS with timeout and user agent
 */
function fetch_feed_content(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MyTicketsManager-RAG-Bot/1.0');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300 && $response) {
        return $response;
    }

    return null;
}

/**
 * Retrieve relevant excerpts from RAG knowledge base using MySQL FULLTEXT search
 *
 * @param PDO $pdo
 * @param string $searchQuery
 * @param int $limit
 * @return array
 */
function retrieve_rag_context(PDO $pdo, string $searchQuery, int $limit = 3): array {
    // Sanitize query for Full-Text natural language search
    $cleanQuery = preg_replace('/[+\-><()~*\"@]+/', ' ', $searchQuery);
    $cleanQuery = trim($cleanQuery);

    if (empty($cleanQuery)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT title, content, MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
        FROM rag_knowledge
        WHERE MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE)
        ORDER BY relevance DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $cleanQuery, PDO::PARAM_STR);
    $stmt->bindValue(2, $cleanQuery, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
