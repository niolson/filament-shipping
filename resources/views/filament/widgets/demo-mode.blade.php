<x-filament-widgets::widget>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950">
        <div class="flex items-start gap-4">
            <div class="mt-0.5 shrink-0 text-amber-500 dark:text-amber-400">
                <x-heroicon-o-beaker class="h-6 w-6" />
            </div>
            <div class="flex-1">
                <p class="font-semibold text-amber-900 dark:text-amber-100">
                    Demo Mode — Sandbox APIs Active
                </p>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                    This instance is running in demo mode. All carrier API calls (USPS, FedEx, UPS) use sandbox test endpoints — no real labels or charges will be generated.
                </p>
                <div class="mt-3 space-y-1 text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-medium">To test label printing:</p>
                    <ol class="ml-4 list-decimal space-y-1">
                        <li>Install <a href="https://qz.io/download/" target="_blank" class="underline hover:no-underline">QZ Tray</a> on this workstation.</li>
                        <li>Go to <strong>Admin → Device Settings</strong> and install the QZ Tray signing certificate.</li>
                        <li>Select your label printer and format, then ship a package to generate a test label.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
