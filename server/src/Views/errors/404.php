<div class="error-page">
    <div class="w-full max-w-md mx-auto">
        <div class="card">
            <div class="card-body text-center">
                <div class="error-code">404</div>
                <h1 class="text-2xl font-bold mt-4">Page Not Found</h1>
                <p class="text-secondary mt-2">The page you're looking for doesn't exist or has been moved.</p>
                <a href="/dashboard" class="btn btn-primary mt-6 inline-flex">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 22V12h6v10" />
                    </svg>
                    Go to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.error-page {
    min-height: calc(100vh - var(--header-height));
    display: flex;
    align-items: center;
    justify-content: center;
}

.error-code {
    font-size: 6rem;
    font-weight: var(--font-bold);
    line-height: 1;
    color: var(--text-tertiary);
    opacity: 0.6;
}

@media (min-width: 768px) {
    .error-code {
        font-size: 8rem;
    }
}
</style>
