<?php
// includes/ai_helper.php
// AI Integration Engine for Google Gemini API and Ollama Local Models with RAG Knowledge Augmentation

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rag_helper.php';

/**
 * Generate AI response based on ticket details and historical context
 *
 * @param PDO $pdo
 * @param string $ticketSubject
 * @param string $ticketMessage
 * @param array $repliesHistory Array of previous replies
 * @return string|false Generated HTML response or false on failure
 */
function generate_ai_ticket_reply(PDO $pdo, string $ticketSubject, string $ticketMessage, array $repliesHistory = []) {
    if (get_setting($pdo, 'ai_enabled', '0') !== '1') {
        return false;
    }

    $provider           = get_setting($pdo, 'ai_provider', 'gemini');
    $customInstructions = get_setting($pdo, 'ai_custom_instructions', '');

    // Base System Prompt
    $systemPrompt  = "You are a helpful, polite, and professional technical support assistant for a ticket management platform.\n";
    $systemPrompt .= "Your task is to provide a clear, thorough, and direct answer to the customer's questions.\n";

    // Retrieve RAG Context if enabled
    if (get_setting($pdo, 'rag_enabled', '0') === '1') {
        sync_rag_feeds($pdo, false);

        $queryKeywords = $ticketSubject . " " . mb_substr(strip_tags($ticketMessage), 0, 300);
        $ragExcerpts = retrieve_rag_context($pdo, $queryKeywords, 3);

        if (!empty($ragExcerpts)) {
            $systemPrompt .= "\nOFFICIAL KNOWLEDGE BASE & POLICY DOCUMENTATION (SINGLE SOURCE OF TRUTH):\n";
            foreach ($ragExcerpts as $idx => $doc) {
                $snippet = mb_substr($doc['content'], 0, 1500);
                $systemPrompt .= "--- Document #" . ($idx + 1) . ": " . $doc['title'] . " ---\n" . $snippet . "\n\n";
            }
            // Strict anti-evasion directive
            $systemPrompt .= "MANDATORY INSTRUCTION: You MUST directly and thoroughly answer the user's questions using the facts provided in the official documentation above.\n";
            $systemPrompt .= "Do NOT evade questions by telling the user to contact generic support emails or open tickets if the factual answer is in the documentation.\n\n";
        }
    }

    // Administrator Custom Rules
    if (!empty($customInstructions)) {
        $systemPrompt .= "Specific Administrator Rules & Instructions to follow:\n" . $customInstructions . "\n\n";
    }

    $conversation  = "Ticket Subject: " . $ticketSubject . "\n";
    $conversation .= "Initial Customer Message: " . strip_tags($ticketMessage) . "\n\n";

    if (!empty($repliesHistory)) {
        $conversation .= "Previous Replies History:\n";
        foreach ($repliesHistory as $reply) {
            $sender = !empty($reply['username']) ? $reply['username'] . " (Staff)" : "Customer";
            $conversation .= "- " . $sender . ": " . strip_tags($reply['message']) . "\n";
        }
        $conversation .= "\n";
    }

    $fullPrompt = $systemPrompt . "Given the above context and official documentation, write a direct and helpful response in the user's language. Output clean HTML formatting (using <p>, <ul>, <li>, <b>, <br> tags). Do not include markdown code blocks or ```html wrappers.\n\n" . $conversation;

    if ($provider === 'gemini') {
        return call_gemini_api($pdo, $fullPrompt);
    } elseif ($provider === 'ollama') {
        return call_ollama_api($pdo, $fullPrompt);
    }

    return false;
}

/**
 * Call Google Gemini REST API
 */
function call_gemini_api(PDO $pdo, string $prompt) {
    $apiKey = get_setting($pdo, 'ai_gemini_api_key', '');
    $model  = get_setting($pdo, 'ai_gemini_model', 'gemini-1.5-flash');

    if (empty($apiKey)) {
        error_log("Gemini API Error: Missing API Key.");
        return false;
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($apiKey);

    $payload = json_encode([
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return false;
    }

    $data = json_decode($response, true);
    $generatedText = $data['candidates'][0]['content']['parts'][0]['text'] ?? false;

    if ($generatedText) {
        $generatedText = preg_replace('/^```html\s*/i', '', $generatedText);
        $generatedText = preg_replace('/^```\s*/i', '', $generatedText);
        $generatedText = preg_replace('/\s*```$/i', '', $generatedText);
    }

    return $generatedText;
}

/**
 * Call Local Ollama REST API with full security and generation options
 */
function call_ollama_api(PDO $pdo, string $prompt) {
    $ollamaUrl   = rtrim(get_setting($pdo, 'ai_ollama_url', 'http://localhost:11434'), '/');
    $model       = get_setting($pdo, 'ai_ollama_model', 'llama3');
    $numCtx      = (int)get_setting($pdo, 'ai_ollama_num_ctx', '4096');
    $temperature = (float)get_setting($pdo, 'ai_ollama_temperature', '0.7');
    $topK        = (int)get_setting($pdo, 'ai_ollama_top_k', '40');
    $topP        = (float)get_setting($pdo, 'ai_ollama_top_p', '0.9');

    $url = $ollamaUrl . "/api/generate";

    $payload = json_encode([
        "model"  => $model,
        "prompt" => $prompt,
        "stream" => false,
        "options" => [
            "num_ctx"     => $numCtx,
            "temperature" => $temperature,
            "top_k"       => $topK,
            "top_p"       => $topP
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return false;
    }

    $data = json_decode($response, true);
    $generatedText = $data['response'] ?? false;

    if ($generatedText) {
        $generatedText = preg_replace('/^```html\s*/i', '', $generatedText);
        $generatedText = preg_replace('/^```\s*/i', '', $generatedText);
        $generatedText = preg_replace('/\s*```$/i', '', $generatedText);
    }

    return $generatedText;
}
