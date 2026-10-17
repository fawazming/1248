<?php
// history.php - browse and navigate saved chatbot conversations (SQLite).
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');

$env = load_env(__DIR__ . '/.env');
$dbPath = $env['CHAT_DB'] ?? __DIR__ . '/data/chat.sqlite';
try {
    $pdo = db_init($dbPath);
} catch (Throwable $ex) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>History error</title></head><body>';
    echo '<h1>History unavailable</h1><p>' . e($ex->getMessage()) . '</p>';
    echo '<p><a href=index.html>Home</a></p></body></html>';
    exit;
}

$sessions = list_sessions($pdo);
?>
<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html;charset=utf-8"><title>Chat History</title></head><body><a name=top></a>
<p><a href=index.html>Home</a>|<a href=chat.php>New chat</a>|<a href=history.php>History</a><hr>
<h1>Chat History</h1>
<p><?= count($sessions) ?> saved conversation(s), newest first.
<?php if (!$sessions): ?>
<p><i>No saved chats yet.</i> <a href=chat.php>Start one.</a>
<?php else: ?>
<hr>
<ol>
<?php foreach ($sessions as $s): ?>
<li><p><b><a href="chat.php?id=<?= urlencode($s['id']) ?>"><?= e($s['title']) ?></a></b><br>
<?= (int)$s['n'] ?> messages | <?= date('d M Y H:i', (int)$s['updated']) ?><br>
<a href="chat.php?id=<?= urlencode($s['id']) ?>">Open / continue</a> |
<a href="chat.php?delete=<?= urlencode($s['id']) ?>">Delete</a>
<?php endforeach; ?>
</ol>
<?php endif; ?>
<hr>
<p><a href=index.html>Home</a>|<a href=chat.php>New chat</a>|<a href=#top>Top</a>
</body></html>
