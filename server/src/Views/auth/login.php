<div class="bg-gray-800 rounded-lg p-8">
    <h2 class="text-2xl font-bold mb-6">Sign In</h2>

    <form action="/login" method="POST" class="space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <div>
            <label for="email" class="block text-sm font-medium text-gray-400 mb-1">Email</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
                required
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                value="<?= htmlspecialchars($old['email'] ?? '') ?>"
            >
            <?php if (!empty($errors['email'])): ?>
                <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['email']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-400 mb-1">Password</label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                required
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
            <?php if (!empty($errors['password'])): ?>
                <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center">
                <input type="checkbox" name="remember" class="w-4 h-4 text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring-blue-500">
                <span class="ml-2 text-sm text-gray-400">Remember me</span>
            </label>
            <a href="/forgot-password" class="text-sm text-blue-500 hover:text-blue-400">Forgot password?</a>
        </div>

        <button type="submit" class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
            Sign In
        </button>
    </form>

    <div class="mt-6">
        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-gray-700"></div>
            </div>
            <div class="relative flex justify-center text-sm">
                <span class="px-2 bg-gray-800 text-gray-400">Or continue with</span>
            </div>
        </div>

        <div class="mt-4">
            <a href="/auth/github" class="flex items-center justify-center w-full py-2 px-4 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                </svg>
                GitHub
            </a>
        </div>
    </div>

    <p class="mt-6 text-center text-sm text-gray-400">
        Don't have an account? <a href="/register" class="text-blue-500 hover:text-blue-400">Sign up</a>
    </p>
</div>
