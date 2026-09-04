# Tech Debt Remediation Plan

Source: `/engineering:tech-debt` audit run 2026-07-21. Priority = (Impact + Risk) × (6 − Effort), each 1–5.

**Context that is NOT debt (don't re-audit):** CI is strong — Pint, PHPStan (level 5), `composer audit`, `npm audit`, Grype image scan with `fail-build`, SHA-pinned actions, Docker build verification, browser tests. `composer audit` is clean. Most dependency/infrastructure debt is already handled.

## Status

- [x] **#1 — USPS `resolveDeliveredAt` defects** — DONE 2026-07-21. Turned out to be 4 defects (false-positive delivery dates on out-for-delivery/delivered-to-agent scans, timestamp-less delivered event, dead code, and TrackingService wiping good dates), plus a follow-up code-43 "Picked Up" status-mapping fix from review. Fixed in `UspsAdapter.php` + `TrackingService.php`; removed one PHPStan baseline entry (327→326). The USPS PTR event-code semantics behind it: codes 01/43/60/59, and predicted dates must never be read as `delivered_at`.

Remaining items below, highest priority first.

- [x] **#6 — Dependency drift** — DONE 2026-07-21 on branch `chore/dependency-updates`. composer→filament 5.7.1, laravel 13.21.1, scout 11.4, socialite 5.29, sail 1.64, phpstan 2.2.5, rector 2.5.7, pest 4.7.5, sentry 4.27, collision 8.9.5, libphonenumber 9.0.35, boost 2.4.13. npm→vite 8.1.5, tailwind + @tailwindcss/vite 4.3.3, laravel-vite-plugin 3.1.3; `concurrently` held at 9.2.4 by its `^9` caret (10.x not crossed). Constraints already permitted every target — only lock files + Filament's republished public assets moved, no `composer.json`/`package.json` edits. All 1179 tests pass, `npm run build` clean, both audits clean. Bonus: the bumps resolved 3 stale `CarrierAccount.php` PHPStan baseline entries (326→323).

---

## Phase 1 (remaining) — this sprint

- [x] **#4 — Coverage measurement in CI** — DONE 2026-07-21 on branch `chore/dependency-updates` (commit 166f717). Switched setup-php `coverage: none`→`pcov`; main feature/unit run now uses `--coverage --coverage-clover=coverage.xml`, with the clover file uploaded as a `coverage-clover` artifact (upload-artifact v4.6.2, SHA-pinned). No `--min` floor — report-only, as planned. Browser-test run left uncovered (Playwright/HTTP, pcov won't capture it). **Phase 2 gate:** read the coverage % off the first green CI run's log and set the floor from that baseline.

**Phase 1 complete.** Both remaining items (#6, #4) done. PR #82.

### Coverage baseline (from PR #82 CI, 2026-07-21) — **total 70.9%**
⚠️ **This contradicts the #2/#3 premise.** The plan said the import + workflow classes have "zero test references" — true for *dedicated* tests, but they're exercised transitively by feature tests and are already well-covered:
- EloquentPackageDraftWorkflow **95.2%**, ShipmentBatchWriter **98.2%**, ShipmentItemImporter **94.6%**, ImportReferenceResolver **87.3%**, ShipmentRowPreparer **85.4%**.
- Real gaps in those packages: **EloquentPackageLabelWorkflow 64.0%**, EloquentPackageShippingWorkflow 70.7%, DatabaseSource **59.4%**, AmazonSource 80.6%.
- Worst in repo (unrelated to #2/#3): **SshTunnel 19.6%**, ShippingRateService 76.1%, ShipDateService 78.3%.

**Rethink Phase 2:** the "no safety net" framing is wrong — the net exists (indirect). Dedicated tests still add regression clarity and pin behavior, but ROI is lower than P=27 implied, and effort should target the actual low-coverage lines (label/shipping workflows, DatabaseSource) rather than the already-covered writers. Suggested floor if/when gated: **70%** (just under baseline, avoids flakiness).

---

## Phase 2 — targeted coverage of low-coverage, high-risk classes (~3–4 days)

**Reframed 2026-07-21 after the #4 coverage baseline (70.9%).** The original #2/#3 assumed the import + workflow classes were untested; the coverage report proved most are already 85–98% via feature tests. So the work is no longer "cover whole packages" — it's **add dedicated tests for the specific low-coverage lines in the high-risk paths**, worst-first. Skip the already-covered writers (`ShipmentBatchWriter` 98.2%, `ShipmentItemImporter` 94.6%, `EloquentPackageDraftWorkflow` 95.2%, `ImportReferenceResolver` 87.3%, `ShipmentRowPreparer` 85.4%, `ShopifySource` 93.0%) — pinning them adds little.

Priority = coverage gap × business risk. Read the uncovered line ranges straight off the CI coverage table (the numbers after each class name in the log).

**Progress 2026-07-21:** dedicated tests added for #2, #3, #11 (below). All green locally (55 tests across the 5 files). No local coverage driver — read the new % off the next CI run.

### #2 — `DatabaseSource` 59.4% · highest priority · ~1d · Test — DONE
Added to `tests/Unit/Services/ShipmentImport/DatabaseSourceTest.php`: fetchShipments (equality + whereIn filters, client-column carry-over, custom query + audit), fetchShipmentItems (default table + custom parameterized query), validateConfiguration (healthy / no-connection / unreachable), validateExportConfiguration (disabled / no-query / healthy), exportPackage (no-query throw + parameter filtering). Covers the previously-uncovered 45..52, 76..79, 95..96, 109..116, 197..225.

### #2 (original note) — `DatabaseSource` 59.4%
`app/Services/ShipmentImport/Sources/DatabaseSource.php` — biggest gap among the data-writing classes, and it runs custom SQL against external DBs to pull customer orders. Uncovered ranges from CI: 45..52, 76..79, 95..96, 109..116, 197..225, 320..382, 394..400. Target the query-building / row-mapping / error paths. `RawSqlGuard` (93.8%) already guards the injection boundary; this covers the surrounding logic.

### #3 — Package label + shipping workflows · high priority · ~1–1.5d · Test — DONE (error paths)
The two low-coverage nodes on the money path:
- `EloquentPackageLabelWorkflow` **64.0%** (uncovered 25, 29, 43..48, 55) — **covered**: not-shipped guard, missing-tracking guard, void RuntimeException/RequestException/generic-Exception catches, reprint not-available guard, manager-reprint-for-others path.
- `EloquentPackageShippingWorkflow` **70.7%** (uncovered 46..48, 62, 72, 78..82, 116..208, 233..265) — **covered the ship() error handling** (carrier-reject failure, RequestTimeOutException → Carrier Timeout, RequestException → Carrier Error, RuntimeException → stateConflict, generic Exception → Shipping Error). **Left uncovered:** the prepareRates special-service / late-rate branches (62, 72, 78..82) and the customs-weight-override path (116..121) — both need heavy fixture setup (SpecialService records + resolver, or an international shipment) for lower ROI; pick up opportunistically under #12.
- Leave `EloquentPackageDraftWorkflow` (95.2%) alone.

### #11 — `SshTunnel` 19.6% · medium priority · ~0.5d · Test — DONE (testable seams)
`app/Services/SshTunnel.php` — lowest coverage in the repo (uncovered 38..91, 105..153, 157, 166). **Testability assessment:** `open()` → `proc_open('ssh …')` → `waitForTunnel()` (socket poll) is not unit-testable without a live bastion — leaving 53..92 and 177..208 uncovered by design. Covered the process-free seams via reflection: `fromConfig` (38..48), `findAvailablePort` (122..130), `prepareKnownHostsFile` all three branches — temp file, configured path, existing file, missing-key throw (132..161), `cleanupKnownHostsFile` (163..170), and `close()`-when-never-opened (99..101). Capped effort here per the plan note; the socket/proc path stays integration-only.

### #12 — opportunistic mid-coverage · low priority · ongoing · Test
Pick up while adjacent: `AmazonSource` 80.6%, `ShippingRateService` 76.1%, `ShipDateService` 78.3`%`. Not worth a dedicated slot — cover when a change touches them.

**Gate for Phase 2:** hold the line at the **70%** floor (just under the 70.9% baseline). Ratchet up only after #2/#3 land. Not a hard CI gate yet — still report-only per #4.

---

## Phase 3 — following sprint (~4 days)

### #5 — Carrier adapter duplication · P=21 · ~4d · Code — DONE 2026-07-22 (branch `refactor/carrier-adapter-dedup`)
Extracted three `Concerns/` **traits** instead of the planned `AbstractCarrierAdapter` base class — traits match house style (10 traits in `app/`, 1 base class, that one framework-provided), keep the single-inheritance slot free (adapters already mix in 2 traits), let `FakeCarrierAdapter` opt out cleanly, and support à-la-carte overlap (the duplication isn't uniform: `decodeJsonSafely` is USPS+UPS, `resolveAccountNumber` is UPS+FedEx, `resolveAccount` is all three).

- **`ResolvesCarrierAccount`** (all 3) — `resolveAccount()` + `isConfigured()`, keyed off the existing `getCarrierName()` interface contract (no new abstract hook).
- **`DecodesJsonResponses`** (USPS+UPS) — `decodeJsonSafely()`.
- **`ResolvesDeliveredAt`** (all 3) — template method with abstract `isDeliveredEvent()` + `deliveredAtFallback()` hooks. The abstract fallback is the guardrail: a carrier can't compile without declaring its summary-status stance (USPS returns null on purpose), so the exact bug-#1 omission is now structurally impossible. **Deliberate behavior change:** unifying on USPS's timestamp-guard propagates the #1 fix to UPS/FedEx — a delivered scan event with a null timestamp now falls through to the summary fallback instead of short-circuiting to null. Pinned by 2 new alignment tests.
- **Left carrier-specific:** `mapTrackingStatus` (legitimate per-carrier keyword tuning, not accidental omission — a shared shape would be a leaky abstraction) and `resolveAccountNumber` (one-line UPS+FedEx dup, below the trait-worth threshold).
- **#8 folded in:** the shared `decodeJsonSafely` now decodes via `json_decode` (mixed) so its `is_array` guard is a genuine check, not always-true — eliminating the error at the source and clearing **both** stale `is_array` baseline entries (UPS + USPS). Baseline 323→321.
- Net ~250 LOC of duplication removed; +3 tests; all 1213 tests green, Pint + PHPStan clean.

**Original audit notes (for reference):** 3,592 LOC across `FedexAdapter` (1432), `UspsAdapter` (1131), `UpsAdapter` (1029). 14 identical public method signatures + 5 identical private ones. Some byte-identical:
- `resolveAccount()` — same body in all three, only the carrier-name string differs.
- `decodeJsonSafely()` — identical in USPS and UPS.
- `resolveDeliveredAt`/`mapTrackingStatus` are legitimately carrier-specific — **and that divergence is exactly how the #1 USPS bug happened** (FedEx fallback written, USPS left half-finished, no shared structure made the gap visible).
- **Sequencing:** originally gated behind "#2/#3 give a safety net" — but the coverage baseline shows the tracking/validation paths already have a net (`TrackingService` 91.7%, `UspsAddressValidator` 87.4%, `GoogleAddressValidator` 88.1%). **Before starting, run the coverage table for the three adapter classes** (`FedexAdapter`/`UspsAdapter`/`UpsAdapter` weren't in the Phase 1 log tail) — if any are low, add characterization tests for the shared methods *first*, then extract. If they're already well-covered, this can start in parallel with Phase 2 rather than after it.
- Extract `AbstractCarrierAdapter`: `resolveAccount()` (carrier name as abstract property), `decodeJsonSafely()`, and a template-method `resolveDeliveredAt()` forcing each subclass to declare its summary-status fallback.
- Fold in #8 opportunistically: clear each adapter's PHPStan baseline entries (USPS 6, FedEx 6, UPS 4) as you touch them rather than re-baselining. Baseline is now **323** (down from 326 — 3 `CarrierAccount.php` entries cleared in Phase 1).

### #8 — PHPStan level 5 + baseline (326 entries) · P=12 · ongoing · Code
Top identifiers: `property.notFound` (55, mostly Filament magic-property noise — ignore), `method.nonObject` (51), `return.unusedType` (37), `nullsafe.neverNull` (34). The nullability ones are real signal and mostly mechanical. `ClientBillingReport.php` alone holds 23 entries — worth a dedicated pass since it touches billing. Not a standalone project; clear entries opportunistically when touching a file.

---

## Phase 4 — opportunistic, no dedicated sprint

### #7 — One browser test for whole ship flow · P=15 · ~2d · Test
Only `tests/Browser/ShippingFlowTest.php` exists. Add coverage for pack + manual-ship + batch-ship happy paths.

### #9 — ~1,200 lines of untestable inline JS in Blade · P=12 · ~5d · Arch
Largest, least urgent. `device-settings.blade.php` (659), `qz-tray.blade.php` (283), `scale-script.blade.php` (231), etc. Hardware integration code is stable and rarely changes — only extract to testable modules when you next need to modify QZ Tray or scale behavior.

### #10 — `SetupWizard` god-class · P=10 · ~3d · Code
`app/Filament/Pages/SetupWizard.php` — 931 LOC, 31 methods. Runs once per install so churn is low. Split by wizard step when convenient.

---

## Standing rule worth adopting
Any PR touching `app/Services/ShipmentImport/` or `app/Services/PackageDrafts/` must add or update a test. That's what keeps #2 and #3 from reappearing.
