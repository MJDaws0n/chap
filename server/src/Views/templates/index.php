<?php
/**
 * Templates Index View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-header-title">Service Templates</h1>
            <p class="page-header-description">Deploy popular services with one click</p>
        </div>
    </div>
</div>

<!-- Categories -->
<div class="mb-6 overflow-x-auto">
    <div class="tabs flex-wrap">
        <button type="button" class="tab active">All</button>
        <button type="button" class="tab">Databases</button>
        <button type="button" class="tab">Caches</button>
        <button type="button" class="tab">Development</button>
        <button type="button" class="tab">Monitoring</button>
        <button type="button" class="tab">Storage</button>
    </div>
</div>

<!-- Templates Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <!-- PostgreSQL -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-blue icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">PostgreSQL</p>
                        <p class="text-sm text-secondary">Powerful, open source object-relational database system.</p>
                    </div>
                </div>
                <span class="badge badge-info">Database</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/postgresql" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- MySQL -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-blue icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">MySQL</p>
                        <p class="text-sm text-secondary">World's most popular open source relational database.</p>
                    </div>
                </div>
                <span class="badge badge-info">Database</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/mysql" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- Redis -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-green icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">Redis</p>
                        <p class="text-sm text-secondary">In-memory data structure store, used as database, cache, and message broker.</p>
                    </div>
                </div>
                <span class="badge badge-success">Cache</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/redis" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- MongoDB -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-blue icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">MongoDB</p>
                        <p class="text-sm text-secondary">Source-available cross-platform document-oriented database.</p>
                    </div>
                </div>
                <span class="badge badge-info">Database</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/mongodb" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- MinIO -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-purple icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">MinIO</p>
                        <p class="text-sm text-secondary">High-performance, S3-compatible object storage.</p>
                    </div>
                </div>
                <span class="badge badge-purple">Storage</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/minio" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- Grafana -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-orange icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">Grafana</p>
                        <p class="text-sm text-secondary">Open source analytics and interactive visualization web application.</p>
                    </div>
                </div>
                <span class="badge badge-warning">Monitoring</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/grafana" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- Prometheus -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-orange icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">Prometheus</p>
                        <p class="text-sm text-secondary">Open-source monitoring and alerting toolkit.</p>
                    </div>
                </div>
                <span class="badge badge-warning">Monitoring</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/prometheus" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>

    <!-- n8n -->
    <div class="card card-glass card-hover h-full flex flex-col">
        <div class="card-body flex-1">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="icon-box icon-box-purple icon-box-sm flex-shrink-0">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium">n8n</p>
                        <p class="text-sm text-secondary">Free and open fair-code licensed workflow automation tool.</p>
                    </div>
                </div>
                <span class="badge badge-neutral">Automation</span>
            </div>
        </div>
        <div class="card-footer" style="background-color: transparent;">
            <a href="/templates/n8n" class="btn btn-secondary w-full">Deploy</a>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        // In real implementation, filter templates based on category
    });
});
</script>
