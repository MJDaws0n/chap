<?php
/**
 * Create Database View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Create Database</h1>
            <p class="text-gray-400 mt-1">Deploy a managed database on your node</p>
        </div>
        <a href="/databases" class="text-gray-400 hover:text-white">← Back</a>
    </div>

    <form method="POST" action="/databases" class="bg-gray-800 rounded-lg p-6 space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                <input type="text" name="name" id="name" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="my-database">
            </div>

            <div>
                <label for="type" class="block text-sm font-medium text-gray-300 mb-2">Database Type</label>
                <select name="type" id="type" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="mysql">MySQL 8.0</option>
                    <option value="postgresql">PostgreSQL 16</option>
                    <option value="mariadb">MariaDB 11</option>
                    <option value="redis">Redis 7</option>
                    <option value="mongodb">MongoDB 7</option>
                </select>
            </div>

            <div>
                <label for="node_id" class="block text-sm font-medium text-gray-300 mb-2">Node</label>
                <select name="node_id" id="node_id" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <?php foreach ($nodes as $node): ?>
                        <option value="<?= $node->id ?>"><?= e($node->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="environment_id" class="block text-sm font-medium text-gray-300 mb-2">Environment</label>
                <select name="environment_id" id="environment_id" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <?php foreach ($environments as $env): ?>
                        <option value="<?= $env->id ?>"><?= e($env->name) ?> (<?= e($env->project()->name ?? 'No Project') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="version" class="block text-sm font-medium text-gray-300 mb-2">Version</label>
                <input type="text" name="version" id="version"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="latest">
            </div>

            <div>
                <label for="port" class="block text-sm font-medium text-gray-300 mb-2">External Port (optional)</label>
                <input type="number" name="port" id="port"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="Auto-assigned">
            </div>
        </div>

        <div class="border-t border-gray-700 pt-6">
            <h3 class="text-lg font-medium mb-4">Credentials</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="db_name" class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                    <input type="text" name="db_name" id="db_name"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="app">
                </div>

                <div>
                    <label for="db_user" class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                    <input type="text" name="db_user" id="db_user"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="admin">
                </div>

                <div class="md:col-span-2">
                    <label for="db_password" class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                    <input type="password" name="db_password" id="db_password"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="Leave empty to auto-generate">
                </div>
            </div>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="/databases" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                Create Database
            </button>
        </div>
    </form>
</div>
