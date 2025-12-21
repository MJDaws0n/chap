<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
    <div class="guest-layout">
        <?= $content ?? '' ?>
    </div>

    <?php include __DIR__ . '/../partials/scripts.php'; ?>
</body>
</html>
