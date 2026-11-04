# Finance persistence foundations

## Storage and mappings

[ADR 0023](adr/0023-per-user-finance-databases.md) replaces shared financial storage with authoritative owner databases. `App\Entity\User` remains in the central database, with authentication, roles, reporting currency, money locale and ChatGPT connection identity. Finance entities live in `App\Finance\Entity` and the separate `finance` entity-manager mapping.

Source, Statement, Transaction, Category and CurrencyRate reside in `<central-database-directory>/tenants/<user-id>.db`. Finance rows have no User association or `user_id`. Their associations and foreign keys stay within the tenant file. `FinanceRecord` is only a common PHP type for sources, statements and transactions, not an ownership mapping. Categories are independently seeded and owner-editable. Rates are ECB-maintained; manual rate CRUD is removed.

Transaction amounts use CLOB/TEXT storage, retaining incomplete draft input. Approval validates the `approved` group before clearing `isDraft`. Currency rates use the `exact_decimal` CLOB mapping and a unique day/currency pair. BCMath remains the application arithmetic contract; SQLite numeric casts and ordinary `SUM` are not exact-money facilities.

## Trusted tenant selection

`TenantManagers::current()` creates the HTTP finance entity manager from the authenticated ordinary user. Administrators are excluded, including accounts also carrying ROLE_USER. The central entity manager is never switched to a tenant file. Native EasyAdmin manager selection follows the disjoint entity namespaces.

Background handlers look up the trusted queued owner in the central database and explicitly open/release a local tenant entity manager. They do not replace a global current tenant. Central User objects are not attached to that manager. Import messages retain owner identity; private Mercure publishing receives it separately from Statement. Private statement bytes remain opaque Flysystem keys resolved through authorized tenant records.

Finance repositories query only their selected database, without owner predicates or association ownership joins. The finance voter enforces ordinary-user entry, current-manager membership for persisted records and the existing completed-import rule for draft editing. Controller authorization remains necessary even though IDs are tenant-local.

The model SQL tool is not exposed by this foundation. It still needs a separate read-only tenant connection, enforced restrictions on external database/file access, exact monetary operations and bounded execution/output. Drafts may be read; approved-only ordinary analysis is prompt guidance, not a security boundary.

## Provisioning and migrations

Central migrations remain in `src/Migrations`; finance migrations use `src/Finance/Migrations` and `config/migrations/finance.yaml`. Migration services are disabled so separate namespace histories do not leak into one another through automatic service discovery. Both histories are generated through Doctrine and Castor.

`TenantSchema` applies the finance history through Doctrine Migrations' native dependency factory. New ordinary users receive the canonical category definitions and expanded historical rates. User CRUD provisions after central persistence and removes the selected tenant database after committed account deletion. Failure does not pretend both files committed atomically. Private uploaded bytes are not automatically deleted by account deletion.

`app:tenant:migrate` provisions missing ordinary-user databases and applies pending finance migrations across existing tenants. `app:currency:refresh` fetches ECB inputs once and writes each owner's rates through its own manager and short writes. HTTP/parsing is outside write transactions.

The owner selected a clean fixture-backed development cutover. The generated central migration drops the obsolete finance tables; there is no legacy converter, runtime fallback, reporting replica or synchronization mechanism. Reset only verified disposable project dev/test storage, after main checks paths and active stack ownership. Committed historical migrations are not rewritten. Do not infer permission to delete uploads, credentials or unrelated volumes.

## Canonical fixtures and browser isolation

Central account fixtures live in `fixtures/central/`; tenant definitions remain in `fixtures/*.yaml`. `TenantFixtureLoader` decorates native Hautelook Alice loading, provisions the corresponding finance schemas and uses native Alice persisters/purgers. Development loading remains append-only. Canonical reference rates are expanded without network access.

`FinanceBrowserTest` retains native Alice `RefreshDatabaseTrait` through the owner-approved project composition trait and DAMA's class-level rollback opt-out. Its test-only tenant-manager extension retains and rolls back tenant connections, including those first opened during a scenario. `FixtureStore` remains the fixture access API. The browser runner and canonical dataset are unchanged; no service/unit testing layer is introduced. See [Testing](../TESTING.md).

The explicitly enabled dev/test Settings reset checks active imports across tenant databases, reloads canonical central/tenant fixtures and cleans private statement bytes as before. It is not a general migration or recovery mechanism. Database rollback does not isolate workers or restore uploaded files. Browser scenarios and the full static gate must establish runtime behavior; schema generation and source inspection alone do not.
