<?php
declare(strict_types=1);
session_start();

/*
 * Создайте хеш пароля командой:
 * php -r "echo password_hash('ваш_пароль', PASSWORD_DEFAULT), PHP_EOL;"
 */
const ADMIN_LOGIN = 'admin';
const ADMIN_PASSWORD_HASH = 'ВСТАВЬТЕ_ХЕШ_СЮДА';

const POSTS_FILE = __DIR__ . '/posts.json';
const UPLOAD_DIR = __DIR__ . '/uploads';
const ARCHIVE_DIR = __DIR__ . '/archive';
const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5 МБ

if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
    throw new RuntimeException('Не удалось создать папку uploads.');
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectToSelf(): never
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function loadPosts(): array
{
    if (!is_file(POSTS_FILE)) {
        return [];
    }

    $json = file_get_contents(POSTS_FILE);
    $posts = json_decode($json ?: '[]', true);

    return is_array($posts) ? $posts : [];
}

function savePosts(array $posts): void
{
    $json = json_encode(
        array_values($posts),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(POSTS_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException(
            'Не удалось сохранить записи. Проверьте права доступа к папке.'
        );
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function checkCsrfToken(): void
{
    $sentToken = $_POST['csrf_token'] ?? '';
    $savedToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($sentToken) ||
        !is_string($savedToken) ||
        !hash_equals($savedToken, $sentToken)
    ) {
        http_response_code(403);
        exit('Ошибка проверки формы. Обновите страницу и попробуйте снова.');
    }
}

function containsSearchTerm(string $text, string $query): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($text, $query, 0, 'UTF-8') !== false;
    }

    return stripos($text, $query) !== false;
}

/**
 * Ищет записи в HTML-файлах архива.
 * Ожидаемая структура архива: записи внутри элементов <article>.
 */
function searchArchivePosts(string $query): array
{
    $results = [];
    $archiveFiles = glob(ARCHIVE_DIR . '/*.html') ?: [];

    foreach ($archiveFiles as $archiveFile) {
        if (!is_file($archiveFile)) {
            continue;
        }

        $html = file_get_contents($archiveFile);
        if ($html === false) {
            continue;
        }

        preg_match_all(
            '~<article\b[^>]*>(.*?)</article>~si',
            $html,
            $articles
        );

        foreach ($articles[1] ?? [] as $articleHtml) {
            $title = '';

            if (preg_match('~<h2\b[^>]*>(.*?)</h2>~si', $articleHtml, $titleMatch)) {
                $title = html_entity_decode(
                    strip_tags($titleMatch[1]),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
                $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
            }

            $content = html_entity_decode(
                strip_tags($articleHtml),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

            if (
                containsSearchTerm($title, $query) ||
                containsSearchTerm($content, $query)
            ) {
                $results[] = [
                    'title' => $title !== '' ? $title : 'Без названия',
                    'content' => $content,
                    'archive' => basename($archiveFile),
                ];
            }
        }
    }

    return $results;
}

/**
 * Экранирует содержимое, распознаёт маркеры форматирования,
 * Markdown-ссылки и обычные URL.
 *
 * Поддерживаемые маркеры:
 * **текст** — жирный
 * *текст* — курсив
 * __текст__ — подчёркнутый
 * ~~текст~~ — зачёркнутый
 * [название](https://ссылка) — ссылка
 */
function formatContent(string $content): string
{
    $escaped = escape($content);

    $formatted = preg_replace_callback(
        '/\*\*(.+?)\*\*|~~(.+?)~~|\*(.+?)\*|__(.+?)__/us',
        static function (array $matches): string {
            if (($matches[1] ?? '') !== '') {
                return '<strong>' . $matches[1] . '</strong>';
            }

            if (($matches[2] ?? '') !== '') {
                return '<s>' . $matches[2] . '</s>';
            }

            if (($matches[3] ?? '') !== '') {
                return '<em>' . $matches[3] . '</em>';
            }

            return '<u>' . $matches[4] . '</u>';
        },
        $escaped
    ) ?? $escaped;

    // В Markdown-ссылках разрешены только схемы http и https.
    $formatted = preg_replace_callback(
        '~\[([^\]\r\n]+)\]\((https?://[^\s)]+)\)~iu',
        static function (array $matches): string {
            return '<a href="' . $matches[2]
                . '" target="_blank" rel="noopener noreferrer">'
                . $matches[1]
                . '</a>';
        },
        $formatted
    ) ?? $formatted;

    // Не превращаем URL внутри уже созданных ссылок в ещё одну ссылку.
    $parts = preg_split(
        '/(<\/?(?:strong|em|u|s)>|<a\b[^>]*>|<\/a>)/i',
        $formatted,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return nl2br($escaped, false);
    }

    $insideLink = false;

    foreach ($parts as &$part) {
        if (preg_match('/^<a\b/i', $part)) {
            $insideLink = true;
            continue;
        }

        if (strcasecmp($part, '</a>') === 0) {
            $insideLink = false;
            continue;
        }

        if (preg_match('/^<\/?(?:strong|em|u|s)>$/i', $part)) {
            continue;
        }

        if ($insideLink) {
            continue;
        }

        $part = preg_replace_callback(
            '~https?://[^\s<]+~u',
            static function (array $matches): string {
                $url = $matches[0];
                $trailing = '';

                while ($url !== '' && preg_match('/[.,!?;:]$/u', $url)) {
                    $trailing = substr($url, -1) . $trailing;
                    $url = substr($url, 0, -1);
                }

                if ($url === '') {
                    return $matches[0];
                }

                return '<a href="' . $url
                    . '" target="_blank" rel="noopener noreferrer">'
                    . $url
                    . '</a>'
                    . $trailing;
            },
            $part
        ) ?? $part;
    }

    unset($part);

    return nl2br(implode('', $parts), false);
}

function handleImageUpload(): ?string
{
    if (
        !isset($_FILES['image']) ||
        $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить изображение.');
    }

    if ($file['size'] > MAX_IMAGE_SIZE) {
        throw new RuntimeException('Размер изображения не должен превышать 5 МБ.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Некорректный загруженный файл.');
    }

    $imageInfo = getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        throw new RuntimeException('Загруженный файл не является изображением.');
    }

    $allowedTypes = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    $imageType = $imageInfo[2];

    if (!isset($allowedTypes[$imageType])) {
        throw new RuntimeException('Разрешены только JPG, PNG, GIF и WEBP.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$imageType];
    $destination = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Не удалось сохранить изображение.');
    }

    return $filename;
}

/**
 * Создаёт HTML-архив постов и копирует изображения.
 * После успешного создания архива очищает posts.json и файлы в uploads.
 */
function archivePosts(array $posts): void
{
    if (
        !is_dir(ARCHIVE_DIR) &&
        !mkdir(ARCHIVE_DIR, 0755, true) &&
        !is_dir(ARCHIVE_DIR)
    ) {
        throw new RuntimeException('Не удалось создать папку archives.');
    }

    $archiveName = date('Y-m-d_H-i-s');
    $archivePath = ARCHIVE_DIR . '/' . $archiveName . '.html';

    if (is_file($archivePath)) {
        $archiveName .= '_' . bin2hex(random_bytes(3));
        $archivePath = ARCHIVE_DIR . '/' . $archiveName . '.html';
    }

    $archiveUploads = ARCHIVE_DIR . '/uploads';

    if (
        !is_dir($archiveUploads) &&
        !mkdir($archiveUploads, 0755, true) &&
        !is_dir($archiveUploads)
    ) {
        throw new RuntimeException(
            'Не удалось создать папку для архивных изображений.'
        );
    }

    $htmlPosts = '';

    foreach ($posts as $post) {
        $title = escape((string)($post['title'] ?? ''));
        $content = formatContent((string)($post['content'] ?? ''));
        $imageHtml = '';
        $image = $post['image'] ?? null;

        if (
            is_string($image) &&
            preg_match('/\A[a-f0-9]{32}\.(jpg|png|gif|webp)\z/', $image)
        ) {
            $source = UPLOAD_DIR . '/' . $image;
            $destination = $archiveUploads . '/' . $image;

            if (
                is_file($source) &&
                !is_file($destination) &&
                !copy($source, $destination)
            ) {
                throw new RuntimeException(
                    'Не удалось скопировать изображение в архив.'
                );
            }

            if (is_file($destination)) {
                $imageHtml = '<img src="uploads/' . escape($image)
                    . '" alt="' . $title
                    . '" style="max-width:100%;height:auto;">';
            }
        }

        $htmlPosts .= '<article class="post">'
            . '<h2>' . $title . '</h2>'
            . $imageHtml
            . '<p>' . $content . '</p>'
            . '</article>' . PHP_EOL;
    }

    $html = '<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Архив записей ' . escape($archiveName) . '</title>
<link rel="stylesheet" href="styles.css">
<style>
body {
    font-family: Arial, sans-serif;
    max-width: 900px;
    margin: 40px auto;
    padding: 0 16px;
    line-height: 1.6;
}
.post {
    padding: 20px 0;
    border-bottom: 1px solid #ccc;
}
</style>
</head>
<body>
<h1>Архив записей</h1>
<p>Создан: ' . escape(date('Y-m-d H:i:s')) . '</p>
' . $htmlPosts . '
</body>
</html>';

    if (file_put_contents($archivePath, $html, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось создать HTML-файл архива.');
    }

    savePosts([]);

    if (is_dir(UPLOAD_DIR)) {
        foreach (new DirectoryIterator(UPLOAD_DIR) as $file) {
            if ($file->isFile() || $file->isLink()) {
                if (!unlink($file->getPathname())) {
                    throw new RuntimeException(
                        'Архив создан, но не удалось очистить папку uploads.'
                    );
                }
            }
        }
    }
}

$error = '';
$loginError = '';

if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    redirectToSelf();
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'login'
) {
    $login = (string)($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (
        hash_equals(ADMIN_LOGIN, $login) &&
        password_verify($password, ADMIN_PASSWORD_HASH)
    ) {
        session_regenerate_id(true);
        $_SESSION['authorized'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        redirectToSelf();
    }

    $loginError = 'Неверный логин или пароль.';
}

if (empty($_SESSION['authorized'])):
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="page">
    <article class="p">
        <h2 class="pt">Вход в панель управления</h2>

        <?php if ($loginError !== ''): ?>
            <p><?= escape($loginError) ?></p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="login">
            <input
                type="login"
                name="login"
                placeholder="Логин"
                autocomplete="username"
                required
            >
            <br>
            <input
                type="password"
                name="password"
                placeholder="Пароль"
                autocomplete="current-password"
                required
            >
            <br>
            <button type="submit" class="bt">Войти</button>
        </form>
    </article>
</div>
</body>
</html>
<?php
    exit;
endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfToken();

    try {
        $posts = loadPosts();
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $title = trim((string)($_POST['title'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));

            if ($title === '' || $content === '') {
                throw new RuntimeException(
                    'Заполните название и текст записи.'
                );
            }

            $image = handleImageUpload();

            array_unshift($posts, [
                'id' => bin2hex(random_bytes(16)),
                'title' => $title,
                'content' => $content,
                'image' => $image,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            savePosts($posts);
            redirectToSelf();
        }

        if ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $found = false;

            foreach ($posts as $index => $post) {
                if (($post['id'] ?? '') === $id) {
                    $image = $post['image'] ?? null;

                    if (
                        is_string($image) &&
                        preg_match(
                            '/\A[a-f0-9]{32}\.(jpg|png|gif|webp)\z/',
                            $image
                        )
                    ) {
                        $imagePath = UPLOAD_DIR . '/' . $image;

                        if (is_file($imagePath)) {
                            unlink($imagePath);
                        }
                    }

                    unset($posts[$index]);
                    $found = true;
                    break;
                }
            }

            if ($found) {
                savePosts($posts);
            }

            redirectToSelf();
        }

        if ($action === 'archive') {
            archivePosts($posts);
            redirectToSelf();
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$posts = loadPosts();
$query = trim((string)($_GET['q'] ?? ''));

if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 200, 'UTF-8');
} else {
    $query = substr($query, 0, 200);
}

$searching = $query !== '';
$visiblePosts = $posts;
$archiveResults = [];

if ($searching) {
    $visiblePosts = [];

    foreach ($posts as $post) {
        if (!is_array($post)) {
            continue;
        }

        $title = (string)($post['title'] ?? '');
        $content = (string)($post['content'] ?? '');

        if (
            containsSearchTerm($title, $query) ||
            containsSearchTerm($content, $query)
        ) {
            $visiblePosts[] = $post;
        }
    }

    $archiveResults = searchArchivePosts($query);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Standalone blog</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="page">
    <p><a type="submit" class="bts" href="?logout=1">Выйти</a></p>

    <?php if ($error !== ''): ?>
        <p><?= escape($error) ?></p>
    <?php endif; ?>

    <h2 class="pt">Добавить новую запись</h2>

    <form method="post" enctype="multipart/form-data">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape(csrfToken()) ?>"
        >
        <input type="hidden" name="action" value="add">
        <input
            type="text"
            name="title"
            placeholder="Название записи"
            required
        >
        <br>

        <div class="format-buttons">
            <button type="button" onclick="wrapText('**')"><strong>Ж</strong></button>
            <button type="button" onclick="wrapText('*')"><em>К</em></button>
            <button type="button" onclick="wrapText('__')"><u>Ч</u></button>
            <button type="button" onclick="wrapText('~~')"><s>З</s></button>
            <button type="button" onclick="insertLink()">Ссылка</button>
        </div>

        <textarea
            id="post-content"
            name="content"
            placeholder="Текст записи"
            required
        ></textarea>
        <br><br>

        <input
            type="file"
            name="image"
            accept="image/jpeg,image/png,image/gif,image/webp"
        >
        <button type="submit" class="bt">Опубликовать</button>
    </form>

    <script>
    function wrapText(marker) {
        const textarea = document.getElementById('post-content');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.slice(start, end) || 'текст';

        textarea.setRangeText(
            marker + selected + marker,
            start,
            end,
            'select'
        );
        textarea.focus();
    }

    function insertLink() {
        const textarea = document.getElementById('post-content');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.slice(start, end) || 'название';
        const input = prompt(
            'Введите URL (http:// или https://):',
            'https://'
        );

        if (input === null) {
            return;
        }

        const url = input.trim();

        if (!/^https?:\/\/\S+$/i.test(url)) {
            alert('Введите URL, начинающийся с http:// или https://');
            return;
        }

        textarea.setRangeText(
            '[' + selected + '](' + url + ')',
            start,
            end,
            'select'
        );
        textarea.focus();
    }
    </script>

    <br><br>
    <hr>
    <br><br>

    <h2 class="pt">Существующие записи</h2>

    <?php if ($searching): ?>
        <p>
            Найдено в опубликованных записях: <?= count($visiblePosts) ?>.
            Найдено в архиве: <?= count($archiveResults) ?>.
        </p>
    <?php endif; ?>

    <?php if ($searching && !$visiblePosts && !$archiveResults): ?>
        <p>По запросу «<?= escape($query) ?>» ничего не найдено.</p>
    <?php endif; ?>

    <?php foreach ($visiblePosts as $post): ?>
        <div class="p">
            <h2 class="pt">
                <?= escape((string)($post['title'] ?? '')) ?>
            </h2>

            <?php if (!empty($post['created_at'])): ?>
                <p style="margin: -12px 0 12px; text-align: left; font-size: 0.85rem; color: #666;">
                    Опубликовано: <?= escape((string)$post['created_at']) ?>
                </p>
            <?php endif; ?>

            <?php
            $image = $post['image'] ?? null;
            if (
                is_string($image) &&
                preg_match('/\A[a-f0-9]{32}\.(jpg|png|gif|webp)\z/', $image)
            ):
            ?>
                <img
                    src="<?= 'uploads/' . escape($image) ?>"
                    alt="<?= escape((string)($post['title'] ?? '')) ?>"
                    style="max-width: 100%;"
                    class="centered-image"
                >
            <?php endif; ?>

            <p class="pg">
                <?= formatContent((string)($post['content'] ?? '')) ?>
            </p>

            <form
                method="post"
                onsubmit="return confirm('Удалить эту запись?');"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escape(csrfToken()) ?>"
                >
                <input type="hidden" name="action" value="delete">
                <input
                    type="hidden"
                    name="id"
                    value="<?= escape((string)($post['id'] ?? '')) ?>"
                >
                <button type="submit" class="bt">Удалить</button>
            </form>
        </div>
        <hr>
    <?php endforeach; ?>

    <?php if ($searching && $archiveResults): ?>
        <h2 class="pt">Найдено в архиве</h2>

        <?php foreach ($archiveResults as $result): ?>
            <article class="p">
                <h3 class="pt"><?= escape($result['title']) ?></h3>
                <p class="pg">
                    <?= nl2br(escape($result['content']), false) ?>
                </p>
                <p>
                    Архив:
                    <a href="<?= 'archive/' . rawurlencode($result['archive']) ?>">
                        <?= escape($result['archive']) ?>
                    </a>
                </p>
            </article>
            <hr>
        <?php endforeach; ?>
    <?php endif; ?>

    <form
        method="post"
        onsubmit="return confirm('Создать архив и очистить текущие записи и изображения?');"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape(csrfToken()) ?>"
        >
        <input type="hidden" name="action" value="archive">
        <button type="submit" class="bt">Архивировать</button>
    </form>

    <form method="get" action="" style="margin-top: 24px;">
        <label for="admin-post-search">Поиск по записям и архиву</label>
        <br>
        <input
            type="search"
            id="admin-post-search"
            name="q"
            value="<?= escape($query) ?>"
            maxlength="200"
            placeholder="Введите название или текст записи"
        >
        <button type="submit" class="bt">Найти</button>

        <?php if ($searching): ?>
            <a href="<?= escape(strtok($_SERVER['REQUEST_URI'], '?')) ?>">
                Сбросить
            </a>
        <?php endif; ?>
    </form>
</div>
</body>
</html>
