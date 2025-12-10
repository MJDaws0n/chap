<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Chap' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="flex items-center justify-center space-x-2 mb-2">
                <svg class="w-12 h-12 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                </svg>
                <span class="text-3xl font-bold">Chap</span>
            </div>
            <p class="text-gray-400">Self-hosted deployment platform</p>
        </div>

        <?php if (!empty($flash['error'])): ?>
            <div class="mb-4 bg-red-900/50 border border-red-600 text-red-300 px-4 py-3 rounded-lg">
                <?= htmlspecialchars($flash['error']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash['success'])): ?>
            <div class="mb-4 bg-green-900/50 border border-green-600 text-green-300 px-4 py-3 rounded-lg">
                <?= htmlspecialchars($flash['success']) ?>
            </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>
</body>
</html>
