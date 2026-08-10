# Repository Guidelines

## Project Structure & Module Organization
PolyBag is a Laravel 13 application with Filament 5 admin pages for barcode-driven packing, shipping, batch labels, carrier integrations, shipment imports/exports, multi-client/3PL setup, and workstation hardware configuration. Core PHP code lives in `app/`, including `Models/`, `Services/`, `Contracts/`, `Filament/`, `Http/Integrations/`, `Console/Commands/`, `Jobs/`, `Events/`, `Listeners/`, `Policies/`, `Enums/`, and `DataTransferObjects/`. Blade views and frontend entrypoints live under `resources/views`, `resources/js`, and `resources/css`, with QZ Tray, barcode, and scale browser code in `resources/js`. Carrier reference data and FedEx test cases live under `resources/data/`. Database migrations, factories, and seeders live in `database/`. Pest tests are grouped under `tests/Feature` and `tests/Unit`; explicit external/reference carrier runs are covered by command tests and the `composer run test:external` scripts when `tests/External` exists. Browser coverage is in `e2e/`. Deployment, tenant, workstation, and server setup docs/scripts live in `docs/`, `docker/`, `infra/`, and `scripts/`.

## Build, Test, and Development Commands
Use the repo scripts instead of ad hoc commands when possible:

- `composer run setup` installs PHP and Node dependencies, creates `.env`, generates the app key, runs migrations, and builds assets.
- `composer run dev` starts the Laravel server, queue worker, scheduler, and Vite dev server together.
- `composer run test` clears config and runs the application test suite.
- `composer run test:external` runs external integration/reference tests when present, and `composer run test:fedex-reference` narrows that suite to FedEx reference cases.
- `npm run test:e2e` runs Playwright flows from `e2e/`.
- `npm run test:e2e:ui` and `npm run test:e2e:headed` run the same Playwright wrapper in UI or headed mode.
- `composer run format` runs Rector, PHPStan, and Pint in sequence.
- `npm run build` creates production frontend assets.

## Coding Style & Naming Conventions
Follow PSR-12 with 4-space indentation for PHP. Run `vendor/bin/pint` before opening a PR. Keep class names singular and descriptive (`ShipmentImportService`, `PackageShippingResult`), and mirror Laravel conventions for models, migrations, and seeders. Use `PascalCase` for PHP classes, `camelCase` for methods, and kebab-case for Blade view filenames where appropriate. Prefer small service classes over controller-heavy logic. Preserve domain terms from `CONTEXT.md`: use **Shipment**, **Package**, and **Package Draft** consistently.

## Testing Guidelines
This repo uses Pest 4 on top of PHPUnit 12. Add unit tests in `tests/Unit` for isolated services, enums, factories, integrations, and DTOs, and feature tests in `tests/Feature` for Filament pages, jobs, events, middleware, API/HTTP flows, import/export flows, package draft/label/shipping workflows, carrier account routing, client scoping, and service orchestration. Use `tests/External` only for explicit external carrier/reference coverage. Name test files with a `*Test.php` suffix. For browser workflows, add or update Playwright specs in `e2e/*.spec.ts`. Cover new behavior and regressions before merging.

## Commit & Pull Request Guidelines
Recent commits use short, imperative subjects such as `Add per-client export destination override to PackageExportService`. Keep commits focused and descriptive; avoid mixed-purpose changes. PRs should explain the user-visible impact, note schema or config changes, link related issues, and include screenshots for Filament/UI updates. Mention any required follow-up steps such as migrations, seeders, or workstation hardware setup.

## Security & Configuration Tips
Do not commit secrets from `.env`, carrier credentials, import source credentials, OAuth tokens, database connection strings, or private QZ signing keys. Use `.env` for infrastructure/base URLs and the App Settings, Carrier Accounts, and Import Sources UIs for encrypted operational credentials. Carrier credentials now usually live on `CarrierAccount` records and may be scoped by location/client through `CarrierAccountScope`; import/export credentials live on `ImportSource` records and may be overridden per client. If you touch printing, scale, OAuth, pack slips, or carrier account routing, document workstation, callback URL, certificate, or client-scope requirements in the PR.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5.6.2
- laravel/framework (LARAVEL) - v13.13.0
- laravel/prompts (PROMPTS) - v0.3.17
- laravel/scout (SCOUT) - v10.25.0
- laravel/socialite (SOCIALITE) - v5.27.0
- livewire/livewire (LIVEWIRE) - v4.3.0
- larastan/larastan (LARASTAN) - v3.9.6
- laravel/boost (BOOST) - v2.4.6
- laravel/mcp (MCP) - v0.7.0
- laravel/pail (PAIL) - v1.2.6
- laravel/pint (PINT) - v1.29.1
- laravel/sail (SAIL) - v1.58.0
- pestphp/pest (PEST) - v4.7.0
- phpunit/phpunit (PHPUNIT) - v12.5.24
- rector/rector (RECTOR) - v2.4.2
- saloonphp/laravel-plugin (SALOON_LARAVEL) - v4.3.0
- saloonphp/saloon (SALOON) - v4.0.0
- tailwindcss (TAILWINDCSS) - v4.3.0

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.
- Carrier and marketplace API SDK code belongs in `app/Http/Integrations/` using Saloon v4 connectors, requests, responses, OAuth providers, and shared concerns.
- Business workflows belong in small service classes under `app/Services/`; keep Filament resources focused on UI configuration and orchestration.
- Package preparation, label reprint/void, and shipment purchase flows should use the workflow contracts in `app/Contracts/` with implementations under `app/Services/PackageDrafts/`, `app/Services/PackageLabels/`, and `app/Services/PackageShipping/`.
- Import/export flows should use `ImportSourceInterface`, `ExportDestinationInterface`, `ImportSourceFactory`, and source classes under `app/Services/ShipmentImport/Sources/`.
- Multi-client/3PL code must preserve `client_id` scoping for shipments, products, aliases, import sources, shipping rules, pick batches, and carrier account resolution. Use `ClientContext` and existing `HasDefaultClient` patterns instead of ad hoc defaults.
- Use typed DTOs from `app/DataTransferObjects/` for structured data crossing service and integration boundaries.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan & Debugging

- Use Artisan directly for framework introspection: `php artisan route:list`, `php artisan list`, `php artisan config:show app.name`, and `php artisan [command] --help`.
- Prefer tests, Boost tools, and existing Artisan commands over ad hoc tinker snippets. If tinker is needed, wrap code in single quotes to avoid shell expansion.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands for Laravel files, including `php artisan make:class` for generic PHP classes, and pass `--no-interaction`.
- When creating models, create useful factories and seeders too.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- Use model factories and their existing states in tests. Follow nearby Faker style (`fake()` or `$this->faker`).
- Create tests with `php artisan make:test [options] {name}`; most tests should be feature tests unless the behavior is isolated.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v13 rules ===

# Laravel 13

- CRITICAL: ALWAYS use `search-docs` for version-specific Laravel documentation and updated code examples before changing Laravel or Filament code.
- Laravel 13 uses the streamlined application structure introduced in Laravel 11.
- Middleware, exception handling, and routing configuration live in `bootstrap/app.php` via `Application::configure()`.
- Application service providers are listed in `bootstrap/providers.php`.
- Console routes live in `routes/console.php`; console commands in `app/Console/Commands/` are auto-discovered.
- When modifying a column, the migration must include all previously defined attributes for that column so they are not dropped.
- Prefer model `casts()` methods over `$casts` properties when that matches nearby models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== filament/filament rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

- Use static `make()` methods for components. Most configuration methods accept closures.
- Use `Get $get` for conditional form state and `Set $set` inside `->afterStateUpdated()` for reactive updates. Prefer `->live(onBlur: true)` on text inputs.
- Compose forms with `Section` and `Grid`; give children explicit `->columnSpan()` or `->columnSpanFull()` when layout matters.
- Use `Repeater::make(...)->relationship()->schema([...])` for inline `HasMany` management.
- Use `TextColumn::make(...)->state(fn (Model $record): mixed => ...)` for derived table values.
- Use `SelectFilter` for enum or relationship filters and `Filter::make(...)->query(...)` for custom table filters.
- Use `Filament\Actions\Action` for buttons with optional modal forms and behavior.

### Testing

Testing setup:

- Authenticate with `$this->actingAs(User::factory()->create())` before panel tests.
- Use `livewire()` helpers for tables/forms, then assert table records, form errors, notifications, redirects, and database state as appropriate.
- For create pages, call `create`; for edit pages, pass `['record' => $model->id]`, call `save`, and do not assert redirect.
- Use `->callAction(DeleteAction::class)` for page actions and `TestAction::make('name')->table($record)` for table actions.

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` is not a Filament 5 component.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== saloonphp/laravel-plugin rules ===

## SaloonPHP

- This project uses Saloon v4 for carrier and marketplace API integrations under `app/Http/Integrations/`.
- Documentation: https://docs.saloon.dev
- Check `composer.json` for the installed major version before implementing. Current dependencies are `saloonphp/saloon` v4 and `saloonphp/laravel-plugin` v4.
- Use official Saloon v4 documentation before implementing new integration patterns.
- Prefer existing connectors, request classes, response classes, OAuth providers, and concerns in `app/Http/Integrations/` before adding new abstractions.
- Always use Artisan commands to generate SaloonPHP classes when available: `php artisan saloon:connector`, `php artisan saloon:request`, `php artisan saloon:response`, `php artisan saloon:plugin`, `php artisan saloon:auth`.

</laravel-boost-guidelines>

## Agent skills

### Issue tracker

Issues live as committed markdown files under `docs/issues/`. See `docs/agents/issue-tracker.md`. (`.scratch/` is gitignored scratch space, not the tracker.)

### Triage labels

Default five-role vocabulary (needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout - `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
