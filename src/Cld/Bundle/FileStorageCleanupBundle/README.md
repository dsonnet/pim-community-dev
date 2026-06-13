# CldFileStorageCleanupBundle

Safely detects and removes **record-level orphaned media files** from Akeneo CE's
`catalogStorage` (`var/file_storage/catalog`), optionally collapses byte-identical
*referenced* files via hardlinks, and ships a root-cause guard so the bloat does not
regenerate.

## Why orphans exist (root cause)

Akeneo's `file_key` is **not content-derived**. `PathGenerator::generateUuid()` computes
`sha1($fileName . microtime())`, so *every* `FileStorer::store()` call — including a
re-import of a byte-identical image — creates a new key, a new physical file and a new
`akeneo_file_storage_file_info` row. The import then overwrites the product's
`raw_values` to point at the newest key, leaving all previous copies registered but
referenced by nothing. (The `hash` column, by contrast, *is* `sha1_file()` of the
content — this bundle uses it for dedup and for the content-addressed storer.)

## Installation

1. Place the bundle under `src/Cld/Bundle/FileStorageCleanupBundle` (autoloaded via the
   project's `"": "src/"` composer mapping).
2. Register it in `config/bundles.php`:
   ```php
   Cld\Bundle\FileStorageCleanupBundle\CldFileStorageCleanupBundle::class => ['all' => true],
   ```
3. (Optional) Create `config/packages/cld_file_storage_cleanup.yaml` to override
   defaults — see *Configuration* below.
4. `bin/console cache:clear`

## Commands

### 1. `pim:cld:file-storage:purge-orphans` — orphan detection & purge

**Dry-run by default.** Recommended operating procedure:

```bash
# 1. Detect + self-check, report only. Nothing is modified.
bin/console pim:cld:file-storage:purge-orphans

# 2. Snapshot the VM. Then quarantine (reversible move) off-peak, throttled:
bin/console pim:cld:file-storage:purge-orphans --force \
    --quarantine=/var/www/pim/var/file_storage/quarantine_catalog \
    --sleep-ms=50

# 3. Verification window (days/weeks): run the app, exports, the UI...
#    If anything is missing, restore (see below).

# 4. Only then physically remove the quarantine directory yourself (rm -rf),
#    or run future passes with --force --delete.
```

Putting the quarantine directory on the **same filesystem** as the storage makes the
move a cheap atomic `rename()`.

How detection works:

- **Referenced set** — every key extracted from each *reference surface*:
  `pim_catalog_product.raw_values`, `pim_catalog_product_model.raw_values`,
  `pim_catalog_category.value_collection` (if present), plus the file keys of user
  avatars (`oro_user.file_info_id` → FK into `file_info`, which *does* live in
  `catalogStorage`). Keys are normalized to the bare `<40hex>_<filename>` form (shard
  prefix stripped) and matched on the exact hash+filename, never on filename alone.
  Stored in the `cld_fsc_referenced_key` work table — no correlated `LIKE` subqueries.
- **Registered set** — `akeneo_file_storage_file_info` rows with
  `storage='catalogStorage'`, streamed by id keyset.
- **Orphans = registered − referenced**, snapshotted into `cld_fsc_orphan`.
- Files with **non-standard keys** (not `<40hex>_…`) cannot be classified reliably and
  are *never* auto-deleted; they are counted and sampled in the report.

The purge then, per batch: moves/deletes the physical file, then deletes the
`file_info` row (both inside one transaction for the row side). A failed file operation
keeps the row, so the orphan is simply re-flagged next run; a row whose file is already
missing is removed as a *stale row*. Rows are deleted with direct batched SQL — audited
against the codebase: `FileInfo` has **no** removal listeners, is **not** indexed in
Elasticsearch and **not** cached; its only FK (`oro_user.file_info_id`) is excluded at
detection time and additionally enforced by the database.

#### The self-check gate (always runs; any failure aborts before any mutation)

| Check | Method | Catches |
|---|---|---|
| Forward sample | Re-extract keys from N random rows per surface; none may be in the orphan set | index population bugs, skipped surfaces |
| Reverse spot-check | Random orphans searched in every surface with plain SQL `LIKE` (independent of the regex pipeline) | extraction/truncation bugs |
| Extraction completeness | Loose `40hex_` probe vs strict capture, plus string-terminator validation per match | non-standard key formats, truncated captures |
| Surface audit | `information_schema` sweep of every text/JSON column, sampled for key-like tokens | unknown reference surfaces (e.g. third-party modules like `wk_product_asset`) |
| Operator fixtures | `--assert-referenced=<key>` / `--assert-orphan=<key>` | everything — your known-good controls |
| Snapshot age | purge refuses snapshots older than `--max-snapshot-age` (default 24h) | references created between detect and purge |

For the first production run, pass the known fixtures from the investigation, e.g.:

```bash
bin/console pim:cld:file-storage:purge-orphans \
    --assert-referenced=2/b/5/c/2b5c21c082cb3e451e7890e96d2b83318ffa91bd_TCG655EU.jpeg \
    --assert-orphan=<one of the 4 stale TCG655EU keys>
```

If the surface audit flags a column (it will flag any table holding key-like tokens,
e.g. a Webkul module), either scan it as a reference surface
(`--extra-surface=table.column`) or — after verifying it holds no live references —
dismiss it (`--skip-table=table`).

**Versioning:** `pim_versioning_version` snapshots embed historical keys. By default
they are *not* treated as references (purged keys appear there as dangling text, which
is cosmetic — CE has no version rollback). Pass `--protect-versions` to keep every key
in version history alive; expect a long scan on a large history table.

Other flags: `--batch-size`, `--limit` (cap a run), `--sleep-ms` (throttle),
`--reuse-snapshot` (purge from a previous detection), `--forward-sample`,
`--spot-check`, `--audit-sample`.

Concurrency is guarded with a MySQL `GET_LOCK`, and detection is read-only — safe with
the application up. Run purges off-peak anyway.

### 2. `pim:cld:file-storage:restore-quarantine`

Reverses a quarantine pass from its manifest (file moves back + `file_info` row
re-insert, idempotent):

```bash
bin/console pim:cld:file-storage:restore-quarantine \
    /var/www/pim/var/file_storage/quarantine_catalog/manifest-20260611-120000.jsonl \
    [--key-filter=TCG655EU]
```

Rows are re-inserted without their original autoincrement id; nothing references
`file_info` by id except avatars, which are never purged.

### 3. `pim:cld:file-storage:dedup-hardlinks` — content dedup of *live* files

For genuinely shared images (same bytes, several live keys): collapses them to one
inode with hardlinks. Every key/path keeps existing; no DB change; strictly separate
from deletion — a referenced file is never deleted.

```bash
bin/console pim:cld:file-storage:dedup-hardlinks            # dry-run: reports reclaimable bytes
bin/console pim:cld:file-storage:dedup-hardlinks --force    # actually link
```

Groups by the stored content `hash` + `size`, **re-verifies the actual bytes** with
`sha1_file()` before linking (skip with `--trust-db-hash`), asserts both paths are on
the same filesystem, and swaps atomically (`link()` to a temp name + `rename()`).

Run it **after** the orphan purge. Before relying on it, confirm your backup tooling is
hardlink-aware (rsync needs `-H`; many tools silently re-expand hardlinks). Local
filesystem adapter only. Caveat: hardlinked copies share bytes — if some process ever
mutated a stored file in place (nothing in Akeneo does), all linked keys would change
together. Equivalent manual alternative: `rdfind -makehardlinks true <catalog dir>`.

### 4. API media dedup: idempotent `POST /api/rest/v1/media-files` (recommended)

Makes re-uploading an identical image from the API a no-op. With
`enable_api_media_dedup: true`, the media-files endpoint compares the uploaded bytes
(`sha1` + size + original filename) against already-registered files:

- **Bytes match and the product/product-model value already points at that key** →
  complete no-op: no new file, no `file_info` row, no product update, no save, no
  Elasticsearch reindex, no version entry. Returns `201` with the `Location` of the
  existing key, so API clients see identical behavior.
- **Bytes match but the value points elsewhere** → the existing key is reused (no new
  physical copy) and only the product value is updated.
- **New content** → stock behavior, unchanged.

This also fixes a failure-path hazard the stock controller would have with reuse: on a
failed product update/validation it removes the `FileInfo` — fine for a fresh upload,
fatal for a reused one that other products reference. The dedup controller disables
that removal for reused files only.

Implementation: `DedupMediaFileController` replaces the `pim_api.controller.media_file`
service via a compiler pass when the flag is on. The byte-matching lives in the shared
`ReusableFileResolver`, the same component the content-addressed storer uses.

Note for API clients: the `Location` returned for a duplicate upload is the *existing*
key, which may differ from what a previous upload of the same bytes returned. Clients
must treat the returned key as authoritative (they should already).

### 5. Root-cause guard: content-addressed storer (recommended)

Stops the regeneration: decorates `akeneo_file_storage.file_storage.file.file_storer`
so that storing a file whose `sha1` content hash, size *and* original filename match an
already-registered, still-on-disk `catalogStorage` file **reuses the existing key**
instead of writing a new copy. Re-running the `logo_45XXX`-style import then becomes
idempotent: no new file, no new row, no new orphan.

Enable in `config/packages/cld_file_storage_cleanup.yaml`:

```yaml
cld_file_storage_cleanup:
    enable_content_addressed_storer: true
```

Safe because Akeneo treats stored files as immutable (a value change always points to a
new key) and CE never deletes media on product removal or value change. The purge
command never deletes referenced files, so sharing one key across products cannot
dangle. Files are reused only when the original filename also matches, so no user ever
sees another upload's filename.

## Configuration reference

```yaml
# config/packages/cld_file_storage_cleanup.yml  (must be .yml — the Kernel only globs *.yml)
cld_file_storage_cleanup:
    catalog_storage_root: '%kernel.project_dir%/var/file_storage/catalog'
    enable_content_addressed_storer: false
    content_addressed_storages: ['catalogStorage']
    enable_api_media_dedup: false
```

Reusing existing keys (storer or API dedup) interacts with the purge: a purge from a
stale orphan snapshot could remove a key that was re-referenced after detection. The
`--max-snapshot-age` guard plus quarantine-first already covers this; just avoid
running a purge from an old snapshot while imports/API writes are active.

## Work tables

Detection materializes two plain InnoDB tables, safe to drop at any time:

- `cld_fsc_referenced_key` — the referenced bare-key set
- `cld_fsc_orphan` — the orphan snapshot consumed by the purge

## Safety summary

- Dry-run default; `--force` plus an explicit mode required for any mutation.
- Quarantine before delete; quarantine is fully reversible via manifest.
- Self-check gate (independent reverse verification included) aborts the run on any
  failure — nothing is mutated.
- Unknown reference surfaces are *discovered* (information_schema audit), not assumed.
- Non-standard keys and avatar files are never touched.
- Snapshot the VM before the first real purge.
