<?php
/**
 * Create Environment View
 */
?>
<div class="max-w-xl mx-auto bg-gray-800 rounded-lg p-8 mt-8">
    <h1 class="text-2xl font-bold mb-6">Create Environment</h1>
    <form method="POST" action="/projects/<?= e($project->uuid) ?>/environments">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <div class="mb-4">
            <label class="block text-gray-300 mb-2">Name</label>
            <input type="text" name="name" class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white" required>
        </div>
        <div class="mb-4">
            <label class="block text-gray-300 mb-2">Description</label>
            <textarea name="description" class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white" rows="3"></textarea>
        </div>
        <div class="flex justify-end">
            <a href="/projects/<?= e($project->uuid) ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded mr-2">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Create</button>
        </div>
    </form>
</div>
