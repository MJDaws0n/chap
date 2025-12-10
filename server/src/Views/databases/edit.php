<?php
/**
 * Edit Database View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Edit Database</h1>
            <p class="text-gray-400 mt-1"><?= e($database->name) ?></p>
        </div>
        <a href="/databases/<?= $database->uuid ?>" class="text-gray-400 hover:text-white">← Back</a>
    </div>

    <form method="POST" action="/databases/<?= $database->uuid ?>" class="bg-gray-800 rounded-lg p-6 space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="_method" value="PUT">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                <input type="text" name="name" id="name" required
                    value="<?= e($database->name) ?>"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            </div>

            <div>
                <label for="type" class="block text-sm font-medium text-gray-300 mb-2">Database Type</label>
                <input type="text" disabled
                    value="<?= ucfirst($database->type) ?>"
                    class="w-full bg-gray-600 border border-gray-600 rounded-lg px-4 py-2 text-gray-400 cursor-not-allowed">
                <p class="text-xs text-gray-500 mt-1">Database type cannot be changed after creation</p>
            </div>

            <div>
                <label for="version" class="block text-sm font-medium text-gray-300 mb-2">Version</label>
                <input type="text" name="version" id="version"
                    value="<?= e($database->version ?? '') ?>"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="latest">
            </div>

            <div>
                <label for="port" class="block text-sm font-medium text-gray-300 mb-2">External Port</label>
                <input type="number" name="port" id="port"
                    value="<?= e($database->external_port ?? '') ?>"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="Auto-assigned">
            </div>
        </div>

        <div class="border-t border-gray-700 pt-6">
            <h3 class="text-lg font-medium mb-4">Update Credentials</h3>
            <p class="text-sm text-gray-400 mb-4">Leave blank to keep existing values</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="db_name" class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                    <input type="text" name="db_name" id="db_name"
                        value="<?= e($database->db_name ?? '') ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div>
                    <label for="db_user" class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                    <input type="text" name="db_user" id="db_user"
                        value="<?= e($database->db_user ?? '') ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div class="md:col-span-2">
                    <label for="db_password" class="block text-sm font-medium text-gray-300 mb-2">New Password</label>
                    <input type="password" name="db_password" id="db_password"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="Leave empty to keep current password">
                </div>
            </div>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="/databases/<?= $database->uuid ?>" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                Update Database
            </button>
        </div>
    </form>
</div>
