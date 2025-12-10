<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-bold">Profile</h1>
        <p class="text-gray-400">Manage your account settings</p>
    </div>

    <!-- Profile Information -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">Profile Information</h2>
            <p class="text-sm text-gray-400">Update your account's profile information and email address.</p>
        </div>
        <form action="/profile" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Name</label>
                <input type="text" name="name" id="name" 
                       value="<?= e($user['name'] ?? '') ?>"
                       class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label for="email" class="block text-sm font-medium text-gray-300 mb-2">Email</label>
                <input type="email" name="email" id="email" 
                       value="<?= e($user['email'] ?? '') ?>"
                       class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Change Password -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">Update Password</h2>
            <p class="text-sm text-gray-400">Ensure your account is using a long, random password to stay secure.</p>
        </div>
        <form action="/profile/password" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            
            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-300 mb-2">Current Password</label>
                <input type="password" name="current_password" id="current_password" 
                       class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label for="password" class="block text-sm font-medium text-gray-300 mb-2">New Password</label>
                <input type="password" name="password" id="password" 
                       class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-300 mb-2">Confirm New Password</label>
                <input type="password" name="password_confirmation" id="password_confirmation" 
                       class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                    Update Password
                </button>
            </div>
        </form>
    </div>

    <!-- Delete Account -->
    <div class="bg-gray-800 rounded-lg border border-red-900">
        <div class="px-6 py-4 border-b border-red-900">
            <h2 class="text-lg font-semibold text-red-400">Delete Account</h2>
            <p class="text-sm text-gray-400">Permanently delete your account and all associated data.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-400 mb-4">
                Once your account is deleted, all of its resources and data will be permanently deleted.
                Before deleting your account, please download any data or information that you wish to retain.
            </p>
            <form action="/profile" method="POST" onsubmit="return confirm('Are you sure you want to delete your account? This action cannot be undone.')">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                    Delete Account
                </button>
            </form>
        </div>
    </div>
</div>
