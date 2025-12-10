<div class="mb-8">
    <h1 class="text-3xl font-bold">Service Templates</h1>
    <p class="text-gray-400">Deploy popular services with one click</p>
</div>

<!-- Categories -->
<div class="flex space-x-4 mb-8 overflow-x-auto pb-2">
    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg whitespace-nowrap">All</button>
    <button class="px-4 py-2 bg-gray-800 text-gray-400 hover:bg-gray-700 rounded-lg whitespace-nowrap">Databases</button>
    <button class="px-4 py-2 bg-gray-800 text-gray-400 hover:bg-gray-700 rounded-lg whitespace-nowrap">Caches</button>
    <button class="px-4 py-2 bg-gray-800 text-gray-400 hover:bg-gray-700 rounded-lg whitespace-nowrap">Development</button>
    <button class="px-4 py-2 bg-gray-800 text-gray-400 hover:bg-gray-700 rounded-lg whitespace-nowrap">Monitoring</button>
    <button class="px-4 py-2 bg-gray-800 text-gray-400 hover:bg-gray-700 rounded-lg whitespace-nowrap">Storage</button>
</div>

<!-- Templates Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
    <!-- PostgreSQL -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-blue-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-blue-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">PostgreSQL</h3>
                <span class="text-xs px-2 py-0.5 bg-blue-600/20 text-blue-400 rounded">Database</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">Powerful, open source object-relational database system.</p>
        <a href="/templates/postgresql" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- MySQL -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-orange-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">MySQL</h3>
                <span class="text-xs px-2 py-0.5 bg-blue-600/20 text-blue-400 rounded">Database</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">World's most popular open source relational database.</p>
        <a href="/templates/mysql" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- Redis -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-red-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-red-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">Redis</h3>
                <span class="text-xs px-2 py-0.5 bg-green-600/20 text-green-400 rounded">Cache</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">In-memory data structure store, used as database, cache, and message broker.</p>
        <a href="/templates/redis" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- MongoDB -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-green-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-green-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">MongoDB</h3>
                <span class="text-xs px-2 py-0.5 bg-blue-600/20 text-blue-400 rounded">Database</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">Source-available cross-platform document-oriented database.</p>
        <a href="/templates/mongodb" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- MinIO -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-pink-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-pink-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">MinIO</h3>
                <span class="text-xs px-2 py-0.5 bg-purple-600/20 text-purple-400 rounded">Storage</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">High-performance, S3-compatible object storage.</p>
        <a href="/templates/minio" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- Grafana -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-orange-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">Grafana</h3>
                <span class="text-xs px-2 py-0.5 bg-yellow-600/20 text-yellow-400 rounded">Monitoring</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">Open source analytics and interactive visualization web application.</p>
        <a href="/templates/grafana" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- Prometheus -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-orange-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">Prometheus</h3>
                <span class="text-xs px-2 py-0.5 bg-yellow-600/20 text-yellow-400 rounded">Monitoring</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">Open-source monitoring and alerting toolkit.</p>
        <a href="/templates/prometheus" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>

    <!-- n8n -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-12 h-12 bg-pink-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-8 h-8 text-pink-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold">n8n</h3>
                <span class="text-xs px-2 py-0.5 bg-gray-600/20 text-gray-400 rounded">Automation</span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4">Free and open fair-code licensed workflow automation tool.</p>
        <a href="/templates/n8n" class="block w-full text-center py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors text-sm">
            Deploy
        </a>
    </div>
</div>
