<?php
/**
 * TeleOps Bridge - Remote Webhook Gateway & Terminal Controller
 * 
 * An ultra-lightweight PHP webhook bridge connecting messaging interfaces
 * to web hosting environments (cPanel/VPS) and local terminal daemons
 * with heartbeat-based state awareness and failover NLP processing.
 *
 * @author Arthur (@kodigos88)
 * @license MIT
 */

@set_time_limit(120);
@ini_set('max_execution_time', 120);

// 1. Load Environment Configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    define('GATEWAY_TOKEN', getenv('GATEWAY_TOKEN') ?: 'YOUR_GATEWAY_TOKEN');
    define('OPERATOR_CHAT_ID', getenv('OPERATOR_CHAT_ID') ?: 'YOUR_CHAT_ID');
    define('SECRET_AUTH_KEY', getenv('SECRET_AUTH_KEY') ?: 'CHANGE_ME_SECRET_KEY');
    define('NLP_ENGINE_KEY', getenv('NLP_ENGINE_KEY') ?: '');
    define('PRIMARY_MODEL', getenv('PRIMARY_MODEL') ?: 'gemini-3.1-flash-lite-preview');
    define('FALLBACK_MODEL', getenv('FALLBACK_MODEL') ?: 'gemini-3.6-flash');
    define('AUDIO_SYNTH_KEY', getenv('AUDIO_SYNTH_KEY') ?: '');
    define('VOICE_PROFILE_ID', getenv('VOICE_PROFILE_ID') ?: '');
    define('AUDIO_SYNTH_MODEL', getenv('AUDIO_SYNTH_MODEL') ?: 's2.1-pro-free');
    define('TASK_QUEUE_FILE', __DIR__ . '/task_queue.json');
}

define('SESSION_HISTORY_FILE', __DIR__ . '/session_history.json');
define('NODE_STATUS_FILE', __DIR__ . '/node_status.json');

// 2. Control Plane Endpoints (Terminal Node Heartbeat & Queue Dispatch)
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Authenticate secure daemon requests
    if (in_array($action, ['get_tasks', 'heartbeat', 'node_status'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!isset($_GET['key']) || $_GET['key'] !== SECRET_AUTH_KEY) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
            exit;
        }
    }

    // Terminal Node Heartbeat Ping
    if ($action === 'heartbeat') {
        $info = [
            'last_seen' => time(),
            'updated_at' => date('Y-m-d H:i:s'),
            'status' => 'online',
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        @file_put_contents(NODE_STATUS_FILE, json_encode($info, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'node' => 'online', 'timestamp' => time()]);
        exit;
    }

    // Node Status Query
    if ($action === 'node_status') {
        $online = isNodeOnline();
        $lastSeen = getNodeLastPing();
        echo json_encode([
            'status' => 'success',
            'online' => $online,
            'state' => $online ? 'online' : 'offline',
            'elapsed_seconds' => $lastSeen ? (time() - $lastSeen) : null,
            'last_ping' => $lastSeen ? date('Y-m-d H:i:s', $lastSeen) : null
        ]);
        exit;
    }

    // Task Queue Ingestion / Consumption
    if ($action === 'get_tasks') {
        if (file_exists(TASK_QUEUE_FILE)) {
            $data = file_get_contents(TASK_QUEUE_FILE);
            $tasks = json_decode($data, true) ?: [];
            if (isset($_GET['clear']) && $_GET['clear'] === '1') {
                file_put_contents(TASK_QUEUE_FILE, json_encode([]));
            }
            echo json_encode(['status' => 'success', 'tasks' => $tasks]);
        } else {
            echo json_encode(['status' => 'success', 'tasks' => []]);
        }
        exit;
    }
}

// 3. Webhook Entry Point
$input = file_get_contents('php://input');
if (!$input) {
    echo "⚡ TeleOps Bridge Gateway is active and listening.";
    exit;
}

$update = json_decode($input, true);
if (!$update || !isset($update['message'])) {
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'] ?? '';
$fromId = $message['from']['id'] ?? '';

// Access Control Verification
if (defined('OPERATOR_CHAT_ID') && !empty(OPERATOR_CHAT_ID) && (string)$chatId !== (string)OPERATOR_CHAT_ID) {
    sendGatewayMessage($chatId, "⛔ Access restricted to authorized operators only.");
    exit;
}

// Configurable Assistant / Engine Persona
$systemDirective = defined('SYSTEM_INSTRUCTION') ? SYSTEM_INSTRUCTION : "You are a senior technical assistant and DevOps copilot. Maintain a professional, concise, and helpful style.";

$sessionHistory = loadSessionHistory();

// 4. Voice Ingestion Handler (Audio -> Processor -> Synthesis Out)
if (isset($message['voice']) || isset($message['audio'])) {
    sendActionNotice($chatId, 'typing');
    $fileId = isset($message['voice']) ? $message['voice']['file_id'] : $message['audio']['file_id'];
    $mimeType = isset($message['voice']['mime_type']) ? $message['voice']['mime_type'] : 'audio/ogg';

    $audioBytes = downloadGatewayFile($fileId);

    if ($audioBytes && strlen($audioBytes) > 500) {
        $promptAudio = "Listen to this voice message carefully. $systemDirective";
        $engineResponse = executeNlpQuery($promptAudio, $sessionHistory, $audioBytes, $mimeType, $systemDirective);

        if (!empty($engineResponse)) {
            saveHistoryTurn('[Voice Instruction Received]', $engineResponse);
            appendTaskQueue([
                'type' => 'voice_instruction',
                'timestamp' => date('Y-m-d H:i:s'),
                'payload' => $engineResponse
            ]);

            // Dispatch response immediately as text
            sendGatewayMessage($chatId, $engineResponse);

            // Optional voice synthesis dispatch
            if (defined('AUDIO_SYNTH_KEY') && !empty(AUDIO_SYNTH_KEY)) {
                sendActionNotice($chatId, 'record_voice');
                sendAudioStream($chatId, $engineResponse);
            }
            exit;
        }
    }

    sendGatewayMessage($chatId, "⚠️ Audio transmission received but could not be resolved. Logged for review.");
    exit;
}

// 5. Text Command & Query Router
$text = trim($message['text'] ?? '');
if (!empty($text)) {
    sendActionNotice($chatId, 'typing');

    if ($text === '/start') {
        $welcome = "👋 TeleOps Bridge Active.\n\nAvailable commands:\n• `/do <task>` - Dispatch command to local terminal\n• `/create <file>` - Dispatch file creation routine\n• `/status` - Verify server and local terminal connectivity\n• `/clear` - Flush session history";
        sendGatewayMessage($chatId, $welcome);
        exit;
    }

    if ($text === '/clear') {
        file_put_contents(SESSION_HISTORY_FILE, json_encode([]));
        sendGatewayMessage($chatId, "🧹 Session memory flushed successfully.");
        exit;
    }

    if ($text === '/status' || $text === '/estado') {
        $online = isNodeOnline();
        $lastPing = getNodeLastPing();
        $minutes = $lastPing ? round((time() - $lastPing) / 60, 1) : null;
        $nodeLabel = $online ? "🟢 Online & Listening" : ($lastPing ? "💤 Offline / Inactive (Last seen {$minutes}m ago)" : "❓ Unregistered");

        $statusMsg = "📊 *System Status:*\n\n" .
                     "💻 *Terminal Node:* $nodeLabel\n" .
                     "☁️ *Gateway Server:* 🟢 Connected\n\n" .
                     "Commands:\n" .
                     "• `/do <command>` ➔ Send instruction to terminal node\n" .
                     "• `/status` ➔ Refresh node status";

        sendGatewayMessage($chatId, $statusMsg);
        exit;
    }

    // Terminal Dispatch Commands: /do, /create, /run, /hacer, /crear
    if (preg_match('/^\/(do|create|run|hacer|crear)\s*(.*)/is', $text, $matches)) {
        $actionTag = strtolower($matches[1]);
        $commandText = trim($matches[2] ?? '');

        if (empty($commandText)) {
            sendGatewayMessage($chatId, "⚠️ Please provide parameters for `/{$actionTag}`.");
            exit;
        }

        $online = isNodeOnline();

        appendTaskQueue([
            'type' => 'cli_dispatch',
            'action' => $actionTag,
            'command' => $commandText,
            'timestamp' => date('Y-m-d H:i:s'),
            'node_online' => $online,
            'status' => 'pending'
        ]);

        if (!$online) {
            $lastPing = getNodeLastPing();
            $timeStr = $lastPing ? round((time() - $lastPing) / 60) . " min ago" : "unknown";
            $reply = "🔌 *Node Offline:* Terminal node is currently inactive (last heartbeat: $timeStr).\n\n" .
                     "Instruction has been queued in remote storage with priority. It will be dispatched as soon as the node reconnects.\n\n" .
                     "📌 *Queued Instruction:* `{$commandText}`";
        } else {
            $reply = "⚡ *Node Active:* Terminal node is online.\n\n" .
                     "Instruction dispatched to execution daemon: `{$commandText}`.\n\n" .
                     "Check your local terminal window for real-time output.";
        }

        saveHistoryTurn("/$actionTag $commandText", $reply);
        sendGatewayMessage($chatId, $reply);
        exit;
    }

    // General NLP query processing
    $response = executeNlpQuery($text, $sessionHistory, null, null, $systemDirective);

    if (empty($response)) {
        $response = "Acknowledge: \"$text\". Logged in remote session queue.";
    }

    saveHistoryTurn($text, $response);
    appendTaskQueue([
        'type' => 'text_event',
        'timestamp' => date('Y-m-d H:i:s'),
        'query' => $text,
        'summary' => substr($response, 0, 300)
    ]);

    sendGatewayMessage($chatId, $response);

    if (defined('AUDIO_SYNTH_KEY') && !empty(AUDIO_SYNTH_KEY)) {
        sendActionNotice($chatId, 'record_voice');
        sendAudioStream($chatId, $response);
    }
    exit;
}

// 6. Core Processing & Integration Functions

function executeNlpQuery($promptText, $history, $audioData = null, $mimeType = 'audio/ogg', $systemDirective = '') {
    if (!defined('NLP_ENGINE_KEY') || empty(NLP_ENGINE_KEY)) return null;

    $models = [
        defined('PRIMARY_MODEL') ? PRIMARY_MODEL : 'gemini-3.1-flash-lite-preview',
        defined('FALLBACK_MODEL') ? FALLBACK_MODEL : 'gemini-3.6-flash'
    ];
    $models = array_unique($models);

    $contents = [];

    foreach ($history as $entry) {
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $entry['user']]]
        ];
        $contents[] = [
            'role' => 'model',
            'parts' => [['text' => $entry['model']]]
        ];
    }

    $currentParts = [];
    if ($audioData) {
        $currentParts[] = [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data' => base64_encode($audioData)
            ]
        ];
    }
    $currentParts[] = ['text' => $promptText];

    $contents[] = [
        'role' => 'user',
        'parts' => $currentParts
    ];

    $payload = [
        'contents' => $contents,
        'systemInstruction' => ['parts' => [['text' => $systemDirective]]],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 2500
        ]
    ];

    $jsonPayload = json_encode($payload);

    foreach ($models as $mod) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $mod . ":generateContent?key=" . NLP_ENGINE_KEY;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 35);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $json = json_decode($response, true);
            if (isset($json['candidates'][0]['content']['parts'])) {
                $chunks = [];
                foreach ($json['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['text'])) {
                        $chunks[] = $part['text'];
                    }
                }
                if (!empty($chunks)) {
                    return trim(implode("\n", $chunks));
                }
            }
        }
    }
    return null;
}

function sendAudioStream($chatId, $rawText) {
    $cleanText = sanitizeVoiceText($rawText);
    if (empty($cleanText)) return false;

    if (mb_strlen($cleanText) > 1200) {
        $cleanText = mb_substr($cleanText, 0, 1190);
        $lastPeriod = mb_strrpos($cleanText, '.');
        if ($lastPeriod !== false && $lastPeriod > 600) {
            $cleanText = mb_substr($cleanText, 0, $lastPeriod + 1);
        } else {
            $cleanText .= '...';
        }
    }

    $audioBuffer = renderAudioSynthesis($cleanText);
    if (!$audioBuffer) return false;

    $tempFile = sys_get_temp_dir() . '/stream_' . uniqid() . '.mp3';
    file_put_contents($tempFile, $audioBuffer);

    $url = "https://api.telegram.org/bot" . GATEWAY_TOKEN . "/sendVoice";
    $cFile = new CURLFile($tempFile, 'audio/mpeg', 'audio_stream.mp3');

    $postData = [
        'chat_id' => $chatId,
        'voice' => $cFile,
        'caption' => '🎙️ Voice Dispatch'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    @unlink($tempFile);
    return $res;
}

function renderAudioSynthesis($text) {
    if (!defined('AUDIO_SYNTH_KEY') || empty(AUDIO_SYNTH_KEY)) return null;

    $url = "https://api.fish.audio/v1/tts";
    $payload = [
        'text' => $text,
        'reference_id' => VOICE_PROFILE_ID,
        'format' => 'mp3'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . AUDIO_SYNTH_KEY,
        'Content-Type: application/json',
        'model: ' . (defined('AUDIO_SYNTH_MODEL') ? AUDIO_SYNTH_MODEL : 's2.1-pro-free')
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && strlen($response) > 500) {
        return $response;
    }
    return null;
}

function downloadGatewayFile($fileId) {
    $infoUrl = "https://api.telegram.org/bot" . GATEWAY_TOKEN . "/getFile?file_id=" . $fileId;
    $info = curlFetch($infoUrl);
    if (!$info) return null;

    $json = json_decode($info, true);
    if (!isset($json['result']['file_path'])) return null;

    $filePath = $json['result']['file_path'];
    $downloadUrl = "https://api.telegram.org/file/bot" . GATEWAY_TOKEN . "/" . $filePath;
    return curlFetch($downloadUrl);
}

function curlFetch($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (TeleOps-Bridge)');
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function sendGatewayMessage($chatId, $text) {
    $url = "https://api.telegram.org/bot" . GATEWAY_TOKEN . "/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function sendActionNotice($chatId, $action = 'typing') {
    $url = "https://api.telegram.org/bot" . GATEWAY_TOKEN . "/sendChatAction";
    $payload = ['chat_id' => $chatId, 'action' => $action];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function sanitizeVoiceText($text) {
    if (strpos($text, '📝') !== false) {
        $parts = explode('📝', $text);
        $text = trim($parts[0]);
    }
    $text = preg_replace('/https?:\/\/\S+/', '', $text);
    $text = preg_replace('/[*_`#~>]/', '', $text);
    $text = preg_replace('/[👮‍♂️🤖🎙️📝✅✨🚀🎧☕💧]/u', '', $text);
    return trim($text);
}

function loadSessionHistory() {
    if (file_exists(SESSION_HISTORY_FILE)) {
        $data = file_get_contents(SESSION_HISTORY_FILE);
        $hist = json_decode($data, true);
        if (is_array($hist)) {
            return array_slice($hist, -4);
        }
    }
    return [];
}

function saveHistoryTurn($userText, $modelText) {
    $history = loadSessionHistory();
    $history[] = [
        'user' => substr($userText, 0, 300),
        'model' => substr($modelText, 0, 400)
    ];
    if (count($history) > 6) {
        $history = array_slice($history, -6);
    }
    @file_put_contents(SESSION_HISTORY_FILE, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function appendTaskQueue($task) {
    $queue = [];
    if (file_exists(TASK_QUEUE_FILE)) {
        $data = file_get_contents(TASK_QUEUE_FILE);
        $queue = json_decode($data, true) ?: [];
    }
    $queue[] = $task;
    @file_put_contents(TASK_QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function isNodeOnline() {
    $last = getNodeLastPing();
    if (!$last) return false;
    return (time() - $last) <= 180;
}

function getNodeLastPing() {
    if (!file_exists(NODE_STATUS_FILE)) return null;
    $data = json_decode(@file_get_contents(NODE_STATUS_FILE), true);
    return $data['last_seen'] ?? null;
}
