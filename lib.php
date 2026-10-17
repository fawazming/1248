<?php
// lib.php - shared helpers for the MSc Exam Companion chatbot.
// Uses .env for configuration and SQLite for storage. No frameworks, no JS.

function load_env(string $path): array {
    $vars = [];
    if (!is_file($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[0] === substr($val, -1)) {
            $val = substr($val, 1, -1);
        }
        $vars[$key] = $val;
    }
    return $vars;
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function db_init(string $path): PDO {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        created INTEGER NOT NULL,
        updated INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        created INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id, id)');
    return $pdo;
}

function new_id(): string {
    return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

function create_session(PDO $pdo, string $title): string {
    $now = time();
    $id = new_id();
    $st = $pdo->prepare('INSERT INTO sessions (id, title, created, updated) VALUES (?, ?, ?, ?)');
    $st->execute([$id, mb_substr($title, 0, 80), $now, $now]);
    return $id;
}

function touch_session(PDO $pdo, string $id): void {
    $st = $pdo->prepare('UPDATE sessions SET updated = ? WHERE id = ?');
    $st->execute([time(), $id]);
}

function add_message(PDO $pdo, string $sid, string $role, string $content): void {
    $st = $pdo->prepare('INSERT INTO messages (session_id, role, content, created) VALUES (?, ?, ?, ?)');
    $st->execute([$sid, $role, $content, time()]);
    touch_session($pdo, $sid);
}

function get_session(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_messages(PDO $pdo, string $sid): array {
    $st = $pdo->prepare('SELECT role, content, created FROM messages WHERE session_id = ? ORDER BY id ASC');
    $st->execute([$sid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function count_messages(PDO $pdo, string $sid): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE session_id = ?');
    $st->execute([$sid]);
    return (int)$st->fetchColumn();
}

function list_sessions(PDO $pdo): array {
    $sql = 'SELECT s.id, s.title, s.created, s.updated,
                   (SELECT COUNT(*) FROM messages m WHERE m.session_id = s.id) AS n,
                   (SELECT content FROM messages m WHERE m.session_id = s.id AND m.role = "user" ORDER BY id ASC LIMIT 1) AS first_user
            FROM sessions s ORDER BY s.updated DESC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function delete_session(PDO $pdo, string $id): void {
    $pdo->prepare('DELETE FROM messages WHERE session_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
}

// Previous / next session by recency, for history navigation.
function neighbour_session(PDO $pdo, string $id, string $dir): ?string {
    $cur = get_session($pdo, $id);
    if (!$cur) {
        return null;
    }
    if ($dir === 'prev') {
        $st = $pdo->prepare('SELECT id FROM sessions WHERE updated > ? ORDER BY updated ASC LIMIT 1');
    } else {
        $st = $pdo->prepare('SELECT id FROM sessions WHERE updated < ? ORDER BY updated DESC LIMIT 1');
    }
    $st->execute([$cur['updated']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['id'] : null;
}

function system_prompt(): string {
    return 'You are a concise study assistant for a postgraduate (MSc) Computer Science student. '
        . 'The four modules are CSC 711 Advanced Operating Systems, CSC 715 Software Engineering, '
        . 'CSC 718 Internet Systems, and Design and Analysis of Algorithms. '
        . 'Give exam-ready answers: a one-line definition, the key points as a short list, and one example or memory hook. '
        . 'Use plain text only (no markdown tables, no code fences). Keep answers short (under about 200 words) '
        . 'unless the student asks you to expand. If a question is unclear, ask one short clarifying question.';
}

function groq_chat(array $messages, array $env): string {
    $key = trim($env['GROQ_API_KEY'] ?? '');
    if ($key === '') {
        return "ERROR: No GROQ_API_KEY found. Put your key in the .env file.";
    }
    $endpoint = $env['GROQ_ENDPOINT'] ?? 'https://api.groq.com/openai/v1/chat/completions';
    $model    = $env['GROQ_MODEL'] ?? 'llama-3.1-8b-instant';
    $maxTok   = (int)($env['GROQ_MAX_TOKENS'] ?? 700);
    $temp     = (float)($env['GROQ_TEMPERATURE'] ?? 0.4);
    $timeout  = (int)($env['GROQ_TIMEOUT'] ?? 25);

    // Keep only the most recent turns for context.
    $trimmed = array_slice($messages, -16);
    $apiMessages = array_merge(
        [['role' => 'system', 'content' => system_prompt()]],
        $trimmed
    );

    $payload = json_encode([
        'model' => $model,
        'messages' => $apiMessages,
        'max_tokens' => $maxTok,
        'temperature' => $temp,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($endpoint, false, $ctx);
        $err = $body === false ? 'request failed' : '';
        $code = 0;
    }

    if ($body === false || $body === null) {
        return 'ERROR: Could not reach Groq (' . e($err) . '). Check your internet connection.';
    }

    $data = json_decode($body, true);
    if (isset($data['choices'][0]['message']['content'])) {
        return trim($data['choices'][0]['message']['content']);
    }
    if (isset($data['error']['message'])) {
        return 'ERROR from Groq: ' . $data['error']['message'];
    }
    return 'ERROR: Unexpected response from Groq (HTTP ' . $code . ').';
}
