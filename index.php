<?php
declare(strict_types=1);

$postsFile = __DIR__ . '/posts.json';
$posts = [];

if (is_file($postsFile)) {
    $json = file_get_contents($postsFile);
    $data = json_decode($json ?: '[]', true);

    if (is_array($data)) {
        $posts = $data;
    }
}

function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatPostContent(string $text): string
{
    $text = escapeHtml($text);

    // Форматирование: **жирный**, ~~зачёркнутый~~,
    // *курсив* и __подчёркнутый__ текст.
    $text = preg_replace_callback(
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
        $text
    ) ?? $text;

    // Markdown-ссылки: [название](https://example.com).
    // Разрешены только URL с протоколом http или https.
    $text = preg_replace_callback(
        '~\[([^\]\r\n]+)\]\((https?://[^\s)]+)\)~iu',
        static function (array $matches): string {
            return '<a href="' . $matches[2]
                . '" target="_blank" rel="noopener noreferrer">'
                . $matches[1]
                . '</a>';
        },
        $text
    ) ?? $text;

    // Автоматически превращаем обычные URL в ссылки,
    // не создавая вложенных ссылок внутри Markdown-ссылок.
    $parts = preg_split(
        '/(<\/?(?:strong|em|u|s)>|<a\b[^>]*>|<\/a>)/i',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return nl2br(escapeHtml($text), false);
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

function containsSearchTerm(string $text, string $query): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($text, $query, 0, 'UTF-8') !== false;
    }

    return stripos($text, $query) !== false;
}

function makeExcerpt(string $text, string $query, int $length = 240): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    if (function_exists('mb_stripos') && function_exists('mb_substr')) {
        $position = mb_stripos($text, $query, 0, 'UTF-8');

        if ($position !== false) {
            $start = max(0, $position - 80);
            $excerpt = mb_substr($text, $start, $length, 'UTF-8');

            return ($start > 0 ? '…' : '')
                . $excerpt
                . (mb_strlen($text, 'UTF-8') > $start + $length ? '…' : '');
        }

        return mb_substr($text, 0, $length, 'UTF-8')
            . (mb_strlen($text, 'UTF-8') > $length ? '…' : '');
    }

    $position = stripos($text, $query);

    if ($position !== false) {
        $start = max(0, $position - 80);
        $excerpt = substr($text, $start, $length);

        return ($start > 0 ? '…' : '')
            . $excerpt
            . (strlen($text) > $start + $length ? '…' : '');
    }

    return substr($text, 0, $length)
        . (strlen($text) > $length ? '…' : '');
}

function searchArchives(string $query): array
{
    $results = [];
    $archiveFiles = glob(__DIR__ . '/archive/*.html') ?: [];

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

$query = trim((string)($_GET['q'] ?? ''));

if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 200, 'UTF-8');
} else {
    $query = substr($query, 0, 200);
}

$searching = $query !== '';
$visiblePosts = [];
$archiveResults = [];

if ($searching) {
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

    $archiveResults = searchArchives($query);
} else {
    $visiblePosts = $posts;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Записи</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="page">
    <div class="container">
        <article class="at">
            <h1>Standalone blog</h1>
        </article>

        <article class="pi">
            <h4>Description</h4>
            <hr>
            <!-- Здесь вы можете добавить шапку блога (Опционально)
            Для этого удалите комментарий
            <article class="p">
            Шапка блога
            </article>
            -->
        </article>

        <?php if ($searching): ?>
            <p>
                Найдено в опубликованных записях: <?= count($visiblePosts) ?>.
                Найдено в архиве: <?= count($archiveResults) ?>.
            </p>

            <?php if (!$visiblePosts && !$archiveResults): ?>
                <p>По запросу «<?= escapeHtml($query) ?>» ничего не найдено.</p>
            <?php endif; ?>
        <?php elseif (!$posts): ?>
            <p>Пока нет опубликованных записей.</p>
        <?php endif; ?>

        <?php foreach ($visiblePosts as $post): ?>
            <?php
            if (!is_array($post)) {
                continue;
            }
            ?>

            <article class="p">
                <h2 class="pt">
                    <?= escapeHtml((string)($post['title'] ?? 'Без названия')) ?>
                </h2>

                <?php if (!empty($post['created_at'])): ?>
                    <?php $createdAt = strtotime((string)$post['created_at']); ?>
                    <p style="margin: -12px 0 12px; text-align: left; font-size: 0.85rem; color: #666;">
                        Опубликовано:
                        <?= $createdAt !== false
                            ? date('H:i d.m.Y', $createdAt)
                            : escapeHtml((string)$post['created_at']) ?>
                    </p>
                <?php endif; ?>

                <?php
                $image = $post['image'] ?? null;

                if (
                    is_string($image) &&
                    preg_match('/\A[a-f0-9]{32}\.(jpg|png|gif|webp)\z/', $image) &&
                    is_file(__DIR__ . '/uploads/' . $image)
                ):
                ?>
                    <img
                        src="<?= 'uploads/' . escapeHtml($image) ?>"
                        alt="<?= escapeHtml((string)($post['title'] ?? '')) ?>"
                        class="centered-image"
                        style="max-width: 100%;"
                    >
                <?php endif; ?>

                <p class="pg">
                    <?= formatPostContent((string)($post['content'] ?? '')) ?>
                </p>
            </article>
            <hr>
        <?php endforeach; ?>

        <?php if ($searching && $archiveResults): ?>
            <h2 class="pt">Результаты поиска в архиве</h2>

            <?php foreach ($archiveResults as $result): ?>
                <article class="p">
                    <h3 class="pt">
                        <?= escapeHtml($result['title']) ?>
                    </h3>

                    <p class="pg">
                        <?= escapeHtml(makeExcerpt($result['content'], $query)) ?>
                    </p>

                    <p>
                        Архив:
                        <a href="<?= 'archive/' . rawurlencode($result['archive']) ?>">
                            <?= escapeHtml($result['archive']) ?>
                        </a>
                    </p>
                </article>
                <hr>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="get" action="">
            <label for="search-query">Поиск по записям и архиву</label><br>
            <input
                type="search"
                id="search-query"
                name="q"
                value="<?= escapeHtml($query) ?>"
                maxlength="200"
                placeholder="Введите запрос"
            >
            <button type="submit">Найти</button>

            <?php if ($searching): ?>
                <a href="index.php">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
</div>
</body>
</html>
