<?php
$result = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['confirmation'] ?? null;

    if (!is_string($password) || !is_string($confirmation)) {
        $error = 'Проверьте введённые данные.';
    } elseif ($password !== $confirmation) {
        $error = 'Пароли не совпадают.';
    } else {
        $result = password_hash($password, PASSWORD_DEFAULT);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Генератор хеша пароля</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            display: grid;
            place-items: center;
            background: #101112;
            color: #e8e8e8;
            font: 16px/1.5 system-ui, sans-serif;
        }

        main {
            width: min(100%, 440px);
            padding: 32px;
            border: 1px solid #292b2e;
            border-radius: 14px;
            background: #171819;
        }

        h1 {
            margin: 0 0 24px;
            font-size: 1.4rem;
            font-weight: 600;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            color: #b8b8b8;
            font-size: 0.9rem;
        }

        input,
        textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #34373a;
            border-radius: 8px;
            outline: none;
            background: #101112;
            color: #e8e8e8;
            font: inherit;
        }

        input:focus,
        textarea:focus {
            border-color: #858585;
        }

        textarea {
            min-height: 82px;
            resize: vertical;
            overflow-wrap: anywhere;
        }

        button {
            width: 100%;
            margin: 22px 0 4px;
            padding: 12px;
            border: 0;
            border-radius: 8px;
            background: #e8e8e8;
            color: #111;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #d0d0d0;
        }

        .error {
            margin: 16px 0 0;
            color: #ff7777;
        }
    </style>
</head>
<body>
<main>
    <h1>Хеш пароля</h1>

    <form method="post">
        <label for="password">Пароль</label>
        <input
            id="password"
            name="password"
            type="password"
            required
            autocomplete="new-password"
        >

        <label for="confirmation">Подтверждение пароля</label>
        <input
            id="confirmation"
            name="confirmation"
            type="password"
            required
            autocomplete="new-password"
        >

        <label for="result">Результат</label>
        <textarea id="result" readonly><?= htmlspecialchars($result, ENT_QUOTES, 'UTF-8') ?></textarea>

        <button type="submit">Сгенерировать хеш</button>

        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </form>
</main>
</body>
</html>
