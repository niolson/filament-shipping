<x-filament-widgets::widget>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950">
        <div class="flex items-start gap-4">
            <div class="mt-0.5 shrink-0 text-amber-500 dark:text-amber-400">
                <x-heroicon-o-shield-exclamation class="h-6 w-6" />
            </div>
            <div class="flex-1">
                <p class="font-semibold text-amber-900 dark:text-amber-100">
                    Multi-factor authentication is off
                </p>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                    Admin accounts can configure data sources, carrier credentials, and see every client's data. Requiring MFA protects these accounts if a password is ever phished or reused.
                </p>
                <p class="mt-3 text-sm">
                    <a href="{{ \App\Filament\Pages\Settings::getUrl() }}"
                       class="font-medium text-amber-900 underline hover:no-underline dark:text-amber-100">
                        Enable it in Settings → Authentication
                    </a>
                </p>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
