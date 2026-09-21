<?php
// includes/rag_helper.php
// RAG Helper with smart chunking for XML feeds and JSON/REST API endpoints

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

        // Clean existing records for this URL to prevent orphaned chunks
        $stmtClean = $pdo->prepare("DELETE FROM rag_knowledge WHERE feed_url = ?");
        $stmtClean->execute([$url]);

        // 1. Try parsing as generic JSON or REST API endpoint
        $jsonData = json_decode($rawPayload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            $totalImported += process_json_feed($pdo, $sourceType, $url, $jsonData);
            continue;
        }

        // 2. Fallback to parsing as XML (RSS 2.0 / Atom)
        $totalImported += process_xml_feed($pdo, $sourceType, $url, $rawPayload);
    }

    // Update last sync timestamp
    $stmtUpdateSync = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rag_last_sync_time', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmtUpdateSync->execute([time()]);

    return ['synced' => true, 'total_items' => $totalImported];
}

/**
 * Parse and store items from generic JSON or REST API endpoints with section chunking
 *
 * @param PDO $pdo
 * @param string $sourceType
 * @param string $url
 * @param array $data
 * @return int Number of processed items
 */
function process_json_feed(PDO $pdo, string $sourceType, string $url, array $data): int {
    if (isset($data['id']) || isset($data['title']) || isset($data['name']) || isset($data['content']) || isset($data['body'])) {
        $items = [$data];
    } else {
        $items = $data;
    }

    $imported = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        // 1. Resolve Identifier (GUID)
        $baseGuid = '';
        if (!empty($item['id'])) {
            $baseGuid = (string)$item['id'];
        } elseif (!empty($item['guid']['rendered'])) {
            $baseGuid = (string)$item['guid']['rendered'];
        } elseif (!empty($item['guid']) && is_string($item['guid'])) {
            $baseGuid = $item['guid'];
        } elseif (!empty($item['slug'])) {
            $baseGuid = (string)$item['slug'];
        }

        // 2. Resolve Title
        $mainTitle = '';
        if (isset($item['title']['rendered'])) {
            $mainTitle = $item['title']['rendered'];
        } elseif (isset($item['title']) && is_scalar($item['title'])) {
            $mainTitle = (string)$item['title'];
        } elseif (isset($item['name']) && is_scalar($item['name'])) {
            $mainTitle = (string)$item['name'];
        } elseif (isset($item['subject']) && is_scalar($item['subject'])) {
            $mainTitle = (string)$item['subject'];
        }

        // 3. Resolve Content
        $rawContent = '';
        if (isset($item['content']['rendered'])) {
            $rawContent = $item['content']['rendered'];
        } elseif (isset($item['content']) && is_scalar($item['content'])) {
            $rawContent = (string)$item['content'];
        } elseif (isset($item['body']) && is_scalar($item['body'])) {
            $rawContent = (string)$item['body'];
        } elseif (isset($item['text']) && is_scalar($item['text'])) {
            $rawContent = (string)$item['text'];
        } elseif (isset($item['description']) && is_scalar($item['description'])) {
            $rawContent = (string)$item['description'];
        }

        if (empty($baseGuid)) {
            $baseGuid = md5($url . $mainTitle);
        }

        $cleanMainTitle = trim(strip_tags(html_entity_decode($mainTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // Split long HTML content into searchable section chunks
        $chunks = split_content_into_chunks($cleanMainTitle, $rawContent);

        foreach ($chunks as $idx => $chunk) {
            $chunkGuid = $baseGuid . '_sec_' . $idx;
            $stmt = $pdo->prepare("
                INSERT INTO rag_knowledge (source_type, feed_url, item_guid, title, content, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title),
                    content = VALUES(content),
                    updated_at = NOW()
            ");
            $stmt->execute([$sourceType, $url, $chunkGuid, $chunk['title'], $chunk['content']]);
            $imported++;
        }
    }

    return $imported;
}

/**
 * Parse and store items from standard XML feeds (RSS 2.0 / Atom)
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
        $baseGuid  = (string)($item->guid ?? $item->id ?? $item->link ?? md5((string)$item->title));
        $mainTitle = trim((string)$item->title);

        $rawContent = (string)$item->description;
        if (isset($item->children('content', true)->encoded)) {
            $rawContent = (string)$item->children('content', true)->encoded;
        } elseif (isset($item->content)) {
            $rawContent = (string)$item->content;
        }

        $cleanMainTitle = trim(strip_tags(html_entity_decode($mainTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $chunks = split_content_into_chunks($cleanMainTitle, $rawContent);

        foreach ($chunks as $idx => $chunk) {
            $chunkGuid = $baseGuid . '_sec_' . $idx;
            $stmt = $pdo->prepare("
                INSERT INTO rag_knowledge (source_type, feed_url, item_guid, title, content, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title),
                    content = VALUES(content),
                    updated_at = NOW()
            ");
            $stmt->execute([$sourceType, $url, $chunkGuid, $chunk['title'], $chunk['content']]);
            $imported++;
        }
    }

    return $imported;
}

/**
 * Clean script/style tags and split long HTML articles into distinct section chunks based on headings
 *
 * @param string $mainTitle
 * @param string $htmlContent
 * @return array Array of chunks with title and clean textual content
 */
function split_content_into_chunks(string $mainTitle, string $htmlContent): array {
    // 1. Remove inline scripts, styles, and noscript tags completely along with their inner code
    $cleaned = preg_replace('/<(script|style|noscript)\b[^>]*>(.*?)<\/\1>/is', '', $htmlContent);

    // 2. Look for section headings (h1, h2, h3)
    $pattern = '/<h([1-3])[^>]*>(.*?)<\/h\1>/is';
    $parts = preg_split($pattern, $cleaned, -1, PREG_SPLIT_DELIM_CAPTURE);

    $chunks = [];

    // If no headings found or too few parts, fallback to paragraph or single chunk
    if (count($parts) <= 1) {
        $cleanText = trim(strip_tags(html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (!empty($cleanText)) {
            $chunks[] = [
                'title'   => $mainTitle,
                'content' => $cleanText
            ];
        }
        return $chunks;
    }

    // Capture preamble before first heading if present
    $preamble = trim(strip_tags(html_entity_decode($parts[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (!empty($preamble) && mb_strlen($preamble) > 50) {
        $chunks[] = [
            'title'   => $mainTitle . ' - Overview',
            'content' => $preamble
        ];
    }

    // Iterate through captured heading levels, titles, and section contents
    for ($i = 1; $i < count($parts); $i += 3) {
        $headingTitle = trim(strip_tags(html_entity_decode($parts[$i + 1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $sectionBody  = trim(strip_tags(html_entity_decode($parts[$i + 2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (empty($sectionBody) && empty($headingTitle)) {
            continue;
        }

        $chunkTitle = !empty($headingTitle) ? ($mainTitle . ' - ' . $headingTitle) : $mainTitle;

        $chunks[] = [
            'title'   => $chunkTitle,
            'content' => $sectionBody
        ];
    }

    return $chunks;
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
