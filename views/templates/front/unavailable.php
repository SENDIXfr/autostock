<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
$escape = static function ($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<!doctype html>
<html lang="<?= $escape($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?></title>
    <style>
        body { margin: 0; padding: 3rem 1.5rem; color: #222; background: #fff; font: 1rem/1.6 system-ui, sans-serif; }
        main { max-width: 42rem; margin: 8vh auto; }
        h1 { font-size: 1.75rem; line-height: 1.3; }
        a { color: #164fa3; text-underline-offset: .2em; }
        a:focus-visible { outline: 2px solid currentColor; outline-offset: 4px; }
    </style>
</head>
<body>
    <main>
        <h1><?= $escape($title) ?></h1>
        <p><?= $escape($message) ?></p>
        <p><a href="<?= $escape($homeUrl) ?>"><?= $escape($homeLabel) ?></a></p>
    </main>
</body>
</html>
