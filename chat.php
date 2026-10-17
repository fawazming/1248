<?php
// chat.php - plain HTML + PHP chatbot form, backed by Groq and SQLite.
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');

$env = load_env(__DIR__ . '/.env');
$dbPath = $env['CHAT_DB'] ?? __DIR__ . '/data/chat.sqlite';
try {
    $pdo = db_init($dbPath);
} catch (Throwable $ex) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Chat error</title></head><body>';
    echo '<h1>Chatbot unavailable</h1><p>Could not open the database: ' . e($ex->getMessage()) . '</p>';
    echo '<p><a href=index.html>Home</a></p></body></html>';
    exit;
}

// ---- Actions -------------------------------------------------------------
if (isset($_GET['delete'])) {
    $id = preg_replace('/[^A-Za-z0-9\-]/', '', $_GET['delete']);
    if ($id !== '') {
        delete_session($pdo, $id);
    }
    header('Location: history.php');
    exit;
}

$id = isset($_REQUEST['id']) ? preg_replace('/[^A-Za-z0-9\-]/', '', $_REQUEST['id']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim((string)($_POST['message'] ?? ''));
    if ($message !== '') {
        if ($id === '' || !get_session($pdo, $id)) {
            $id = create_session($pdo, $message);
        }
        add_message($pdo, $id, 'user', $message);

        $history = array_map(
            fn($m) => ['role' => $m['role'], 'content' => $m['content']],
            get_messages($pdo, $id)
        );
        $reply = groq_chat($history, $env);
        add_message($pdo, $id, 'assistant', $reply);
    }
    header('Location: chat.php?id=' . urlencode($id) . '#latest');
    exit;
}

$session = $id !== '' ? get_session($pdo, $id) : null;
$messages = $session ? get_messages($pdo, $id) : [];
$prevId = $session ? neighbour_session($pdo, $id, 'prev') : null;
$nextId = $session ? neighbour_session($pdo, $id, 'next') : null;

function nav_links(?array $session, ?string $prevId, ?string $nextId): string {
    $out = '<a href=index.html>Home</a>|<a href=chat.php>New chat</a>|<a href=history.php>History</a>';
    if ($session) {
        $out .= '|<a href=chat.php?id=' . urlencode($session['id']) . '>Refresh</a>';
    }
    if ($prevId) {
        $out .= '|<a href=chat.php?id=' . urlencode($prevId) . '>Prev chat</a>';
    }
    if ($nextId) {
        $out .= '|<a href=chat.php?id=' . urlencode($nextId) . '>Next chat</a>';
    }
    return $out;
}
?>
<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html;charset=utf-8"><title>MSc Study Chatbot</title></head><body><a name=top></a>
<p><?= nav_links($session, $prevId, $nextId) ?><hr>
<h1>Chatbot</h1>
<?php if ($messages): ?>
<p><b>Chat:</b> <?= e($session['title']) ?> (<?= count($messages) ?>)
<p><b>Index:</b>
<?php
$n = 0;
foreach ($messages as $m) {
    $n++;
    echo '<a href=#m' . $n . '>' . $n . '</a> ';
}
?>
<a href=#latest>Last</a>
<hr>
<?php
$n = 0;
foreach ($messages as $m) {
    $n++;
    $who = $m['role'] === 'user' ? 'You' : 'Tutor';
    echo '<a name=m' . $n . '></a><p><b>' . $who . ':</b><br>' . nl2br(e($m['content'])) . "</p>\n";
}
?>
<a name=latest></a>
<hr>
<?php endif; ?>
<form method="post" action="chat.php">
<input type="hidden" name="id" value="<?= e($id) ?>">
<textarea name="message" rows="3" cols="30" autofocus accesskey="m"></textarea>
<p><input type="submit" value="Send">
</form>
<?php if ($session): ?>
<p><a href="chat.php?delete=<?= urlencode($session['id']) ?>">Delete chat</a>
<?php endif; ?>
<hr>
<p><?= nav_links($session, $prevId, $nextId) ?>|<a href=#top>Top</a>
</body></html>
