# Implementation Assessment & Plan (Phase 0)

> Companion to [PROJECT_SPECIFICATION.md](PROJECT_SPECIFICATION.md). Written 2026-11-20 after inspecting the repository.

---

## 1. Existing architecture

The repository is an **untouched Laravel Vue starter kit**. There is no domain code yet.

| Area                    | What exists                                                                                                                                                                                                   |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Framework               | Laravel **13.43.0**. Composer allows `php ^8.3`                                                                                                                                                               |
| PHP (local)             | **8.3.33** (Laragon, ZTS, Windows). The spec targets 8.4                                                                                                                                                      |
| Auth                    | Laravel **Fortify**: login, registration and password reset are on. Email verification and 2FA are **off** in `config/fortify.php`, although the Security settings page and its tests exist                   |
| Frontend                | Vue 3.5 + Inertia **v3** + TypeScript, Tailwind **v4**, shadcn-vue (`new-york`, neutral) built on `reka-ui`, `@lucide/vue`, `@vueuse/core`, `vue-sonner`                                                      |
| Routing to frontend     | **Wayfinder**: typed route functions in `@/routes` and `@/actions`                                                                                                                                            |
| Build                   | `vite-plus` (`vp build` / `vp dev`), with lint and format through `vp check`                                                                                                                                  |
| Layouts                 | `AppLayout` with a sidebar shell (`AppSidebar`, `NavMain`, `NavUser`), plus Auth and Settings layouts                                                                                                         |
| UI components           | alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, input, input-otp, label, navigation-menu, select, separator, sheet, sidebar, skeleton, sonner, spinner, tooltip |
| Database                | **SQLite** (`database/database.sqlite`). Only the default users, cache and jobs migrations                                                                                                                    |
| Queue / cache / session | `database` drivers                                                                                                                                                                                            |
| Storage                 | `local` disk                                                                                                                                                                                                  |
| Tests                   | Pest 4: **30 tests, 27 passed, 3 skipped**, all green. Larastan, Pint and a GitHub Actions `tests.yml` workflow                                                                                               |
| AI tooling              | Laravel Boost MCP and the project skills in `.claude/skills` and `.cursor/skills`                                                                                                                             |

### Conventions to follow (they override the spec where they differ)

- Frontend folders are **lowercase**: `resources/js/pages`, `components`, `layouts`, `composables`, `types`, `lib`. The spec's PascalCase folders (`Pages/`, `Components/`) map onto these. Page components stay PascalCase, e.g. `pages/properties/Index.vue`.
- Models use PHP attributes (`#[Fillable]`, `#[Hidden]`) and `@property` docblocks.
- Validation goes in Form Requests under `app/Http/Requests/<Area>/`.
- Use Wayfinder route functions in Vue, never hardcoded URLs.
- `AGENTS.md`: **no dependency changes without approval**, and no new top-level base folders without approval. The `app/` subfolders from spec Part 20 (`Services/`, `Contracts/`, `Enums/`, `Jobs/`, `Policies/`) are ordinary Laravel locations and count as fine.
- Run Pint after PHP changes, and add Pest feature tests per module.

---

## 2. Local environment findings

| Tool                                                         | Status                                                                         | Impact                                                                                                                       |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| MySQL                                                        | Laragon ships **MySQL 8.4.3**, not currently running. `.env` still uses SQLite | Switch `.env` to MySQL in Phase 1 and create a `virtualtour` database                                                        |
| Redis                                                        | Laragon ships **Redis 5.0.14** (old Windows port), not running                 | Fine for local queues and cache. Production should run Redis 7 on Forge                                                      |
| PHP `redis` extension                                        | **Not loaded**                                                                 | Either enable `php_redis.dll` in Laragon or use `predis/predis` (see decisions)                                              |
| Laravel Horizon                                              | Needs `pcntl`/`posix`, which **don't exist on Windows**                        | Install Horizon for Forge. Locally, run `php artisan queue:work redis --queue=...`. Horizon config must not break local boot |
| FFmpeg / FFprobe                                             | **Not installed** locally                                                      | Not needed until Phase 5. Install a Windows build then and set `FFMPEG_PATH` / `FFPROBE_PATH`                                |
| `gd`, `exif`, `fileinfo`, `intl`, `curl`, `zip`, `pdo_mysql` | Loaded                                                                         | Enough for image dimension checks (panorama validation) and EXIF reading. `imagick` isn't loaded and isn't required          |
| Node / npm                                                   | v26 / 11                                                                       | OK                                                                                                                           |

---

## 3. Missing packages (each needs approval before install)

### Phase 1

| Package                     | Why                                                                                                                                                                                  |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `laravel/horizon`           | Queue dashboard and supervision on Forge (spec Part 12)                                                                                                                              |
| `predis/predis`             | Pure-PHP Redis client, so local Windows works without a native extension. Not needed if you'd rather enable `php_redis.dll`                                                          |
| `cloudinary/cloudinary_php` | Official Cloudinary SDK: signed uploads, Admin API deletion and metadata. Chosen over the Laravel wrapper because we wrap it in our own `CloudinaryService` behind a contract anyway |

### Phase 2

| Package                                                                                                        | Why                                                       |
| -------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| `@photo-sphere-viewer/core`, `@photo-sphere-viewer/virtual-tour-plugin`, `@photo-sphere-viewer/markers-plugin` | 360° viewer, scene graph and hotspots (spec Part 7)       |
| `vue-draggable-plus`                                                                                           | Drag-and-drop scene ordering (Vue 3, built on SortableJS) |
| `three`                                                                                                        | Peer dependency of Photo Sphere Viewer                    |

### Phase 3+

- **No vendor SDKs for OpenAI, Skybox, Veo, Lyria or fal.ai.** Each adapter uses Laravel's HTTP client (`Http::`) behind our own contracts. That keeps dependencies small, makes `Http::fake()` testing trivial, and avoids SDK version lock-in.
- `symfony/process` is already present through the framework (FFmpeg execution in Phase 5).

---

## 4. Required environment variables

Add to `.env.example` (empty values) and `config/services.php` or dedicated config files:

```env
DB_CONNECTION=mysql
DB_DATABASE=virtualtour

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis            # or phpredis if the extension is enabled

CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
CLOUDINARY_UPLOAD_FOLDER=virtualtour

OPENAI_API_KEY=                # Phase 3
OPENAI_VISION_MODEL=
OPENAI_TEXT_MODEL=
SKYBOX_API_KEY=                # Phase 3
SKYBOX_WEBHOOK_SECRET=
GOOGLE_AI_API_KEY=             # Phase 5
GOOGLE_VEO_MODEL=
GOOGLE_LYRIA_MODEL=
FAL_KEY=                       # optional

FFMPEG_PATH=
FFPROBE_PATH=
VIDEO_TEMP_PATH=
VIDEO_MAX_CONCURRENT_RENDERS=1
```

A `ProviderConfiguration` check (one class) reports which features are enabled. Missing keys disable the related UI with a clear admin message rather than throwing at runtime.

---

## 5. Key technical decisions

1. **Workspaces.** Each user gets a personal workspace on registration (hooked into `CreateNewUser`). `users.current_workspace_id` selects the active one. Every domain table carries `workspace_id`, and policies check membership and role. The roles are an enum: Owner, Admin, Editor, Viewer.
2. **Identifiers.** Auto-increment `id` internally, plus a `ulid` column (route key) on models exposed in URLs: properties, media, tours, video projects. Public tour and video URLs use a random `public_id` (non-guessable) plus a cosmetic slug: `/t/{public_id}/{slug?}`.
3. **Media storage contract.** `App\Contracts\Media\MediaStorage` has a `CloudinaryMediaStorage` implementation. Tests bind a fake. Uploads use **direct signed uploads** from the browser to Cloudinary. The backend signs the request, then verifies the returned `public_id` with the Admin API before creating the `media_assets` record, so large panoramas never pass through PHP memory.
4. **Panorama safety.** Originals are never transformed. Previews and thumbnails are Cloudinary delivery transformations computed on the fly, so the original asset is untouched. 2:1 validation reads the dimensions Cloudinary returns and warns (doesn't reject) outside a ±2% tolerance.
5. **Hotspot coordinates.** These are stored as **degrees** (`decimal(8,4)`): yaw from -180 to 180, pitch from -90 to 90. One TypeScript module (`lib/sphere.ts`) converts degrees to and from radians at the Photo Sphere Viewer boundary. That module and a PHP enum or helper get unit tests.
6. **Frontend data access.** Inertia pages with Wayfinder. Generation progress is polled with Inertia v3 polling (no websockets yet). Reverb can come later.
7. **Provider adapters.** Each adapter sits behind a contract in `app/Contracts/{AI,Panorama,Video,Music}` and is bound in a dedicated `AiProvidersServiceProvider`. Business logic only ever type-hints the contract.
8. **Credits.** `credit_wallets` uses row locking (`lockForUpdate`) inside short transactions. `credit_transactions` has a unique `(reference_type, reference_id, transaction_type)` index so retries can't double-charge. This is built in Phase 3, before any paid call is exposed.
9. **Prices** live in `config/ai_pricing.php`, which seeds an admin-editable table later. They are never hardcoded in controllers or Vue.

---

## 6. Potential conflicts & risks

| Risk                                                                        | Mitigation                                                                                                                                     |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP 8.3 locally vs 8.4 in the spec                                          | Code stays 8.3-compatible (no 8.4-only syntax such as property hooks) until Laragon and Forge both run 8.4. Bump `composer.json` then          |
| Horizon not runnable on Windows                                             | Use `queue:work` locally and Horizon on Forge. Horizon's service provider is safe to load on Windows; only the `horizon` command needs `pcntl` |
| SQLite → MySQL switch                                                       | Write migrations MySQL-first. Keep tests on in-memory SQLite (fast), avoid MySQL-only SQL in queries, and add a CI MySQL job later if needed   |
| Fortify email verification is off, but routes use the `verified` middleware | Keep as is for now. Enabling verification is a one-line config change before launch                                                            |
| Cloudinary keys needed to upload anything                                   | Needed from Phase 1 (free tier is enough). Without keys the media library shows a configuration notice. Tests use the fake storage             |
| Skybox Image2Pano requires a Model 4-eligible **paid** plan                 | Confirm the plan before Phase 3 acceptance                                                                                                     |
| Veo and Lyria model IDs and pricing change often                            | Model IDs come from env. Prices come from config. Capabilities are declared per adapter                                                        |
| URL import legal and SSRF risk                                              | Deferred to Phase 6, with the SSRF guard service written and tested in isolation                                                               |

---

## 7. Phase plan

Each phase ends with passing tests, Pint, Larastan, `vp check`, `vue-tsc`, and a manual run in the browser.

### Phase 1: Application foundation

1. **Infrastructure:** MySQL `.env`, Redis queue/cache, Horizon install and config (queues: default, ai-analysis, panorama, video-generation, media, video-rendering, analytics), provider config files, `.env.example`.
2. **Enums:** `WorkspaceRole`, `PropertyType`, `PropertyStatus`, `AssetType`, `AssetSourceType`.
3. **Workspaces:** `workspaces`, `workspace_members` and `users.current_workspace_id` migrations. Models, factories, personal workspace on registration, `CurrentWorkspace` resolver, and workspace data shared to Inertia.
4. **Properties:** migration (all Part 3 fields, soft deletes), model, factory, `PropertyPolicy`, Form Requests, CRUD controller, duplicate action, search and filter. Pages: `properties/Index`, `Create`, `Edit`, `Show` (tabs for media, tours, videos, analytics; the later tabs are stubs until their phase, with honest empty states).
5. **Media library:** `media_assets` migration, `MediaStorage` contract, `CloudinaryMediaStorage`, signed upload endpoint, verify-and-register endpoint, delete, thumbnail URLs. `MediaAssetPolicy`. Pages: `media/Index` with a grid, type and property filters, an upload dialog (multi-file, progress) and a detail sheet.
6. **Dashboard:** real counts (properties, media, panoramas). Tour and video counts show 0 until those modules exist. Recent properties and uploads, quick actions (only those that work are enabled).
7. **Navigation:** sidebar entries for Dashboard, Properties, Media Library and Settings. Later modules are added when they're built, so there are no dead links (spec rule 1).
8. **Activity log:** `activity_logs` table and a small `ActivityLogger` for create, update and delete events.
9. **Tests:** property CRUD, duplicate, policies, workspace isolation (user A can't see B's data), media signature and registration with the fake storage, panorama ratio detection.

**Acceptance:** a user registers, gets a workspace, creates a property, uploads photos and a 360° panorama to Cloudinary, and sees them organized by property.

### Phase 2: Interactive virtual tour

`virtual_tours`, `tour_scenes`, `tour_hotspots` migrations and policies. Photo Sphere Viewer composable (`usePanoramaViewer`). Three-pane editor (scene list with drag ordering, viewer, context panel). Initial view capture, navigation, info and link hotspots with click-to-place, autosave, publish and unpublish, public viewer `/t/{public_id}`, embed code generator, preload of the next scene.
**Acceptance:** four uploaded panoramas, connected with hotspots, published, and explorable at a public link and in an iframe, with no AI provider involved.

### Phase 3: AI panorama studio

Credit wallet and transactions, `ai_generations` and outputs, provider contracts, `OpenAIImageAnalysisProvider`, `ScenePlanningService`, `SkyboxPanoramaProvider` (text-to-360 and Image2Pano), webhook endpoint with idempotency table, polling fallback, jobs on the `panorama` / `ai-analysis` queues, AI Studio pages (text mode with plan review, photo mode with synthetic notice), generation history, single-scene regeneration.

### Phase 4: Advanced tour features

Branding, tour and scene audio (user-activated playback), floor plans and markers, map, lead form with spam protection, `tour_visits` / `tour_events` analytics, scene gallery, team invitations and role management UI.

### Phase 5: AI video studio

Video projects, shots, clips, audio tracks and exports. Six-step wizard, OpenAI storyboard, `GoogleVeoProvider`, `GoogleLyriaProvider`, user audio upload, FFmpeg `VideoAssemblyService` on the `video-rendering` queue (concurrency 1), per-clip regeneration, public `/v/{public_id}`, MP4 download.

### Phase 6: Advanced features

URL import with SSRF guard, photo enhancement provider, Street View, advanced analytics, custom domains, white-label, API (Sanctum), subscription management, admin console.

---

## 8. Decisions needed from you before Phase 1

1. **Approve the Phase 1 dependencies:** `laravel/horizon`, `cloudinary/cloudinary_php`, and `predis/predis` (or tell me to enable `php_redis.dll` instead).
2. **Cloudinary credentials.** Do you have a Cloudinary account? Real uploads need `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY` and `CLOUDINARY_API_SECRET` in `.env`. You add these yourself; I won't handle the secret.
3. **MySQL.** Is it OK to switch local `.env` to Laragon MySQL with a new `virtualtour` database? Start MySQL and Redis from the Laragon panel first.
