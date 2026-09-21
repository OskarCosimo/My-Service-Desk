<?php
// includes/rag_helper.php
// RAG Helper with Optional AI Multilingual Translation, Boolean Search, and Smart Chunking

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
    $cleaned = preg_replace('/<(script|style|noscript)\b[^>]*>(.*?)<\/\1>/is', '', $htmlContent);

    $pattern = '/<h([1-3])[^>]*>(.*?)<\/h\1>/is';
    $parts = preg_split($pattern, $cleaned, -1, PREG_SPLIT_DELIM_CAPTURE);

    $chunks = [];

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

    $preamble = trim(strip_tags(html_entity_decode($parts[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (!empty($preamble) && mb_strlen($preamble) > 50) {
        $chunks[] = [
            'title'   => $mainTitle . ' - Overview',
            'content' => $preamble
        ];
    }

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
 * Retrieve relevant excerpts from RAG knowledge base using Boolean Mode (bypassing 50% threshold)
 *
 * @param PDO $pdo
 * @param string $searchQuery
 * @param int $limit
 * @return array
 */
function retrieve_rag_context(PDO $pdo, string $searchQuery, int $limit = 3): array {
    $cleanQuery = trim(preg_replace('/[+\-><()~*\"@]+/', ' ', $searchQuery));
    if (empty($cleanQuery)) {
        return [];
    }

    $englishKeywords = '';
    // Check if query translation to English is enabled in settings (default: enabled)
    if (get_setting($pdo, 'rag_translate_query', '1') === '1') {
        $englishKeywords = extract_english_keywords_via_ai($pdo, $cleanQuery);
    }

    // Build terms array with wildcard support (e.g. registra* matches registration, account* matches account)
    $rawTerms = preg_split('/\s+/', trim($cleanQuery . ' ' . $englishKeywords));
    $booleanTerms = [];

    foreach ($rawTerms as $term) {
        $term = trim(strtolower($term));
        if (mb_strlen($term) >= 3) {
            $booleanTerms[] = $term . '*';
        }
    }

    if (empty($booleanTerms)) {
        return [];
    }

    // Use up to 10 most relevant search terms for boolean search
    $booleanQuery = implode(' ', array_slice(array_unique($booleanTerms), 0, 10));

    // Search using BOOLEAN MODE (bypasses the 50% frequency rule completely)
    $stmt = $pdo->prepare("
        SELECT title, content, MATCH(title, content) AGAINST (? IN BOOLEAN MODE) AS relevance
        FROM rag_knowledge
        WHERE MATCH(title, content) AGAINST (? IN BOOLEAN MODE)
        ORDER BY relevance DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $booleanQuery, PDO::PARAM_STR);
    $stmt->bindValue(2, $booleanQuery, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = $stmt->fetchAll();

    // Fallback: if boolean mode matched 0 rows, try NATURAL LANGUAGE MODE
    if (empty($results)) {
        $searchTerms = trim($cleanQuery . ' ' . $englishKeywords);
        $stmtNatural = $pdo->prepare("
            SELECT title, content, MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
            FROM rag_knowledge
            WHERE MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE)
            ORDER BY relevance DESC
            LIMIT ?
        ");
        $stmtNatural->bindValue(1, $searchTerms, PDO::PARAM_STR);
        $stmtNatural->bindValue(2, $searchTerms, PDO::PARAM_STR);
        $stmtNatural->bindValue(3, $limit, PDO::PARAM_INT);
        $stmtNatural->execute();
        $results = $stmtNatural->fetchAll();
    }

    return $results;
}

/**
 * Fast keyword translation via active LLM (Ollama or Gemini) with low token budget (max 20 tokens)
 *
 * @param PDO $pdo
 * @param string $text
 * @return string Space-separated English keywords
 */
function extract_english_keywords_via_ai(PDO $pdo, string $text): string {
    if (get_setting($pdo, 'ai_enabled', '0') !== '1') {
        return '';
    }

    $provider  = get_setting($pdo, 'ai_provider', 'gemini');
    $shortText = mb_substr(strip_tags($text), 0, 300);
    $prompt    = "Extract 5 to 8 English search keywords from this customer support ticket to find policy/terms documentation:\n\"" . $shortText . "\"\nRespond ONLY with space-separated English keywords without punctuation or conversation:";

    if ($provider === 'ollama') {
        $ollamaUrl = rtrim(get_setting($pdo, 'ai_ollama_url', 'http://localhost:11434'), '/');
        $model     = get_setting($pdo, 'ai_ollama_model', 'llama3');

        $ch = curl_init($ollamaUrl . '/api/generate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => [
                'num_predict' => 20,
                'temperature' => 0.1
            ]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['response'])) {
                return trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $data['response']));
            }
        }
    } elseif ($provider === 'gemini') {
        $apiKey = get_setting($pdo, 'ai_gemini_api_key', '');
        $model  = get_setting($pdo, 'ai_gemini_model', 'gemini-1.5-flash');

        if (!empty($apiKey)) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($apiKey);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                "contents" => [["parts" => [["text" => $prompt]]]]
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            curl_close($ch);

            if ($res) {
                $data = json_decode($res, true);
                $gen = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (!empty($gen)) {
                    return trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $gen));
                }
            }
        }
    }

    return '';
}
