<div class="mb-8">
    <a href="/projects" class="text-gray-400 hover:text-white text-sm flex items-center mb-4">
        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        Back to Projects
    </a>
    <h1 class="text-3xl font-bold">Create Project</h1>
    <p class="text-gray-400">Projects help you organize related applications</p>
</div>

<div class="max-w-2xl">
    <form action="/projects" method="POST" class="bg-gray-800 rounded-lg border border-gray-700 p-6 space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <div>
            <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Project Name</label>
            <input 
                type="text" 
                id="name" 
                name="name" 
                required
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="My Awesome Project"
                value="<?= htmlspecialchars($old['name'] ?? '') ?>"
            >
            <?php if (!empty($errors['name'])): ?>
                <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-gray-300 mb-2">Description <span class="text-gray-500">(optional)</span></label>
            <textarea 
                id="description" 
                name="description" 
                rows="3"
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                placeholder="Brief description of your project"
            ><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center justify-end space-x-4 pt-4 border-t border-gray-700">
            <a href="/projects" class="px-4 py-2 text-gray-400 hover:text-white transition-colors">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                Create Project
            </button>
        </div>
    </form>
</div>
