<?php
// includes/rag_helper.php
// RAG Helper supporting both XML (RSS / Atom) feeds and WordPress REST API JSON endpoints with MySQL Fulltext retrieval

require_once __DIR__ . '/config.php';

/**
 * Sync knowledge feeds if cache has expired or force is requested
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

        $rawPayload = fetch_feed_content($url);
        if (!$rawPayload) {
            continue;
        }

        // 1. Try parsing as JSON (e.g. WordPress REST API endpoints)
        $jsonData = json_decode($rawPayload, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($jsonData))) {
            $totalImported += process_json_feed($pdo, $sourceType, $url, $jsonData);
            continue;
        }

        // 2. Fallback to parsing as XML (RSS 2.0 or Atom)
        $totalImported += process_xml_feed($pdo, $sourceType, $url, $rawPayload);
    }

    // Update last sync timestamp
    $stmtUpdateSync = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rag_last_sync_time', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmtUpdateSync->execute([time()]);

    return ['synced' => true, 'total_items' => $totalImported];
}

/**
 * Parse and store items from WordPress REST API or generic JSON payload
 *
 * @param PDO $pdo
 * @param string $sourceType
 * @param string $url
 * @param array $data
 * @return int Number of processed items
 */
function process_json_feed(PDO $pdo, string $sourceType, string $url, array $data): int {
    // If it's a single post object rather than an array of posts, wrap it
    if (isset($data['id']) || isset($data['title'])) {
        $items = [$data];
    } else {
        $items = $data;
    }

    $imported = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        // Extract ID or Slug for GUID
        $guid = (string)($item['id'] ?? $item['slug'] ?? '');
        if (empty($guid) && isset($item['guid']['rendered'])) {
            $guid = (string)$item['guid']['rendered'];
        }

        // Extract Title (WordPress formats this as ['title']['rendered'])
        $title = '';
        if (isset($item['title'])) {
            $title = is_array($item['title']) ? ($item['title']['rendered'] ?? '') : (string)$item['title'];
        }

        // Extract Content (WordPress formats this as ['content']['rendered'])
        $content = '';
        if (isset($item['content'])) {
            $content = is_array($item['content']) ? ($item['content']['rendered'] ?? '') : (string)$item['content'];
        } elseif (isset($item['excerpt'])) {
            $content = is_array($item['excerpt']) ? ($item['excerpt']['rendered'] ?? '') : (string)$item['excerpt'];
        }

        // Fallback guid based on title hash
        if (empty($guid)) {
            $guid = md5($url . $title);
        }

        // Clean HTML markup and decode entities
        $cleanTitle   = trim(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $cleanContent = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (empty($cleanContent) && empty($cleanTitle)) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO rag_knowledge (source_type, feed_url, item_guid, title, content, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                title = VALUES(title),
                content = VALUES(content),
                updated_at = NOW()
        ");
        $stmt->execute([$sourceType, $url, $guid, $cleanTitle, $cleanContent]);
        $imported++;
    }

    return $imported;
}

/**
 * Parse and store items from XML feeds (RSS 2.0 / Atom)
 *
 * @param PDO $pdo
 * @param string $sourceType
 * @param string $url
 * @param string $xmlContent
 * @return int Number of processed items
 */
function process_xml_feed(PDO $pdo, string $sourceType, string $url, string $xmlContent): int {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        libxml_clear_errors();
        return 0;
    }

    $items = isset($xml->channel->item) ? $xml->channel->item : (isset($xml->entry) ? $xml->entry : []);
    $imported = 0;

    foreach ($items as $item) {
        $guid  = (string)($item->guid ?? $item->id ?? $item->link ?? md5((string)$item->title));
        $title = trim((string)$item->title);

        $content = (string)$item->description;
        if (isset($item->children('content', true)->encoded)) {
            $content = (string)$item->children('content', true)->encoded;
        } elseif (isset($item->content)) {
            $content = (string)$item->content;
        }

        $cleanContent = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (empty($cleanContent) && empty($title)) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO rag_knowledge (source_type, feed_url, item_guid, title, content, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                title = VALUES(title),
                content = VALUES(content),
                updated_at = NOW()
        ");
        $stmt->execute([$sourceType, $url, $guid, $title, $cleanContent]);
        $imported++;
    }

    return $imported;
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
