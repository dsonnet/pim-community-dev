# TODO — Track down the spurious "file_collection changed" entries in product history

**Status:** FIX IMPLEMENTED & VERIFIED ON STAGING (2026-06-26) · **Severity:** LOW (cosmetic) · **Data safety:** CONFIRMED SAFE · **Prod:** not yet deployed

## 0. Resolution (2026-06-26)

Implemented as `Cld\Bundle\FileStorageCleanupBundle\Normalizer\Versioning\FileCollectionVersioningNormalizer`
(tag `pim_versioning.serializer.normalizer`, priority 200, arg `@pim_catalog.repository.cached_attribute`).
Emits two deterministic, sort_order-sorted columns per file-collection value — `<attr>` (file keys)
and `<attr>-ignored` (0/1) — pre-empting Akeneo's buggy generic `CollectionNormalizer`.

Two implementation gotchas found while building it:
- **`Smartoys\…\Model\FileCollectionValue` is dead code** — it `extends Pim\Component\Catalog\Model\AbstractValue`,
  a v2.x namespace removed in v7, so it *fatals on autoload*. The runtime value is a generic
  `Akeneo\…\Product\Value\ScalarValue`. Detection is therefore by **attribute type**
  (`smartoys_catalog_file_collection`) via the attribute repo, NOT by value class — and the normalizer
  is deliberately NOT `CacheableSupportsMethod` (selection depends on the attribute, not the value class;
  being first + non-cacheable, the serializer always consults it first and falls straight through).
- **One-time transition:** the first save of each product after deploy logs a single
  `graphic_collection`/`-ignored` (and the old buggy `file_path`/`ignored`/… columns disappearing)
  changeset entry, then it is clean forever. Products not edited get no new version.

Verified on staging `.137` (DL4670): post-fix snapshot carries `graphic_collection`=13 clean items,
`file_path`/`ignored` ABSENT; a second save with no collection change logged only `product_image_large`
(collection-noise: NONE). Unrelated: the ES `datetime_immutable` 500 on save still fires (separate bug).
**Owner:** _unassigned_ · **Related:** post-migration (v7.0.42 → v2026, PHP 8.3) artifact in a 3rd‑party bundle

> Cross-reference: add a one-line pointer to this file from `docs/POST-MIGRATION-TODO.md`
> in the `dsonnet/akeneo-pim-project` repo (the repo prod tracks). This file lives in
> `dsonnet/pim-community-dev` because that is where the investigation was started.

---

## 1. Symptom

In the product **version history** (UI → product → History, backed by `pim_versioning_version`),
saving an **unrelated** attribute (e.g. uploading `product_image_large` via the media API)
records a changeset that *also* lists changes to **`file_path`** and **`ignored`** — which are
**not** attributes, but the leaf sub-fields of the Smartoys **FileCollection** attribute
(`smartoys_catalog_file_collection`, e.g. `graphic_collection`).

Observed on **DL4670** (uuid `09c72a6a-3af5-4b91-8054-7cced54d4262`):

```
file_path  old: <13 media keys>            new: <same 13 + header_47275 duplicated at pos 0>  (14)
ignored    old: ,,,,,,,,,,,,, (14 slots)   new: ,,,,,,,,,,,, (13 slots)
Grande image (product_image_large)  old: …portrait_47273.jpg  new: …image_large.jpg   ← the only REAL change
```

Tell‑tale: within a single snapshot the two parallel columns **disagree on item count**
(`file_path`=13 vs `ignored`=14, then 14 vs 13) — impossible for clean data unless the
flattening/diff is itself inconsistent.

## 2. What is already CONFIRMED (2026-06-25)

- ✅ **The stored data is clean — NOT corrupted.** `raw_values.graphic_collection` for DL4670 has
  exactly **13 items**, `sort_order` 0–12, **no duplicate** `header_47275`, every `ignored:false`.
  → The history diff is **cosmetic changeset noise**, not data loss/mutation.
- ✅ **Unrelated to the dedup fix** (`DedupMediaFileController` / `ReusableFileResolver`) shipped
  the same day, and unrelated to the media upload itself (which only touches `product_image_large`).
- ✅ The attribute type is `smartoys_catalog_file_collection`; affected attributes in the catalog:
  `graphic_collection`, `user_manual`, `graphic_collection_bonus`.
- ✅ Bundle lives at `/var/www/pim/src/Smartoys/FileCollectionBundle` (3rd‑party, "Amit Kumar 2023").
  **It is NOT in the `pim-community-dev` repo** — only on the servers / in `akeneo-pim-project`.
- ✅ A concrete off‑by‑one already spotted (same family, but EXPORT path, not proven to be the
  versioning path): `ArrayConverter/StandardToFlat/Product/ValueConverter/FileCollectionConverter.php::getFlatValues()`
  appends the separator **before** a `continue` that skips `ignored` items → separator/value counts drift.

## 3. ROOT CAUSE — CONFIRMED (2026-06-25)

It is a **stock Akeneo bug**, triggered only because the Smartoys FileCollection ships
**no versioning normalizer**. The snapshot is built by `VersionBuilder::buildVersion()`
→ `pim_versioning.serializer->normalize($product, 'flat')`. For the FileCollection value:

1. `…/Normalizer/Versioning/Product/ValueNormalizer` takes `value->getData()` (the array of
   items), wraps it in an `ArrayCollection`, and delegates to
2. `…/Normalizer/Versioning/Product/CollectionNormalizer`, which is **buggy**:

```php
foreach ($object as $item) {
    $normalizedItem = $this->normalizer->normalize($item, $format, $context); // e.g. ['file_path'=>'A','ignored'=>'']
    foreach ($normalizedItem as $key => $value) {
        if (array_key_exists($key, $result)) {
            $result[$key] = $result[$key] . ',' . $value;        // accumulate (ok)
        } else {
            $result = array_replace($result, $normalizedItem);   // BUG: merges the WHOLE item, not just $key
        }
    }
}
```

On the **first** item, its first key hits the `else` branch and `array_replace` injects
**all** of that item's keys into `$result` at once. So every *other* key of the same item
is now "already present" and gets an extra `,$value` appended → that column gains a
**phantom leading element**. Which column is hit depends purely on the **item array's key
order**:

- keys `[file_path, ignored]` → `ignored` gets the phantom → **file_path=13 / ignored=14** (State A).
- keys `[ignored, file_path]` → `file_path` gets the phantom, and since the phantom duplicates
  the *first* value, `file_path` shows **header_47275 twice** → **file_path=14 / ignored=13** (State B).

The item key order flips between "freshly hydrated from `raw_values`" (JSON order
`ignored,file_key,file_path,…`) and "rebuilt by the FileCollection setter" (`file_path,ignored,…`),
so consecutive saves alternate State A ↔ State B → a spurious `file_path`/`ignored` changeset
on (almost) every save. **A hand-trace reproduces the exact observed counts and the duplicated
header.** This is a versioning-only normalization, so persisted `raw_values` never sees it →
data stays clean (verified: DL4670 = 13 clean items).

> Stock Akeneo rarely hits this because its own multi-key collections (prices, metric) have
> dedicated normalizers; the custom FileCollection has none, so it falls into this generic path.
> The correct one-liner would be `else { $result[$key] = $value; }`, but patching `vendor/akeneo`
> is fragile — prefer the bundle-level fix (§6.C).

## 4. Hypotheses (ranked)

1. **Non-deterministic / off-by-one flattening** (most likely): the FileCollection value is
   flattened for versioning with an `ignored`-skip + separator bug (cf. `getFlatValues`), so the
   `file_path` and `ignored` columns desync and differ from the stored baseline on every save.
2. **Stale post-migration baseline:** the previous version snapshot was written by pre-migration
   code with a different flat format, so the *first* save after migration shows a one-time diff.
   (Disprove by checking it recurs — see Step 3.)
3. **Item ordering not stabilised** (e.g. hydrated in DB/array order, not by `sort_order`), so the
   joined column ordering flips between saves.
4. **Missing/broken versioning comparator** for the FileCollection type, so "no real change" is
   not detected and a changeset is always emitted.

## 5. Investigation plan (do in order)

Access: test VM `pim7` = `ssh dsonnet@192.168.81.137`; run console/cache as
`sudo -u akeneo php bin/console …` (cache is `akeneo:akeneo`); DB creds in
`/var/www/pim/.env.local` (`APP_DATABASE_*`). **Do NOT experiment on prod (.147).**
Use a throwaway product on the test VM (it is a disposable clone).

- [ ] **Step 0 — Pick a test product** with a non-empty `graphic_collection` on the test VM and
      snapshot its clean state: `pim:cld:file-storage:media-snapshot <IDENTIFIER> --out /tmp/before.json`.

- [ ] **Step 1 — Reproduce deterministically.** Run `pim:versioning:refresh` to flush pending
      versions, then save the product **twice** changing **nothing** in `graphic_collection`
      (e.g. set an unrelated text attr, or re-set `product_image_large` to its current value).
      After each save, read the newest row:
      ```sql
      SELECT id, version, pending, changeset
      FROM pim_versioning_version
      WHERE resource_uuid = UNHEX(REPLACE('<uuid>','-',''))
      ORDER BY id DESC LIMIT 3;
      ```
      - If **both** saves log a `file_path`/`ignored` change → **non-deterministic** (H1/H3).
      - If only the **first** does → **stale baseline** (H2): a catalog-wide `pim:versioning:refresh`
        likely makes it stop.

- [ ] **Step 2 — Decode a real snapshot + changeset.** The `snapshot`/`changeset` columns are
      PHP-serialized flat arrays. Dump and decode (small script, run `sudo -u akeneo php`):
      ```php
      // /tmp/decode.php
      require '/var/www/pim/vendor/autoload.php'; require '/var/www/pim/config/bootstrap.php';
      $k = new \Kernel($_SERVER['APP_ENV'] ?? 'prod', false); $k->boot();
      $c = $k->getContainer()->get('database_connection');
      $row = $c->fetchAssociative("SELECT snapshot, changeset FROM pim_versioning_version
          WHERE resource_uuid = UNHEX(REPLACE(?, '-','')) ORDER BY id DESC LIMIT 1", ['<uuid>']);
      var_export(unserialize($row['snapshot'])); echo "\n---- CHANGESET ----\n";
      var_export(unserialize($row['changeset']));
      ```
      Confirm the exact column keys (`graphic_collection-file_path`? bare `file_path`?) and the
      old/new strings — this is the ground truth the UI renders.

- [ ] **Step 3 — Pin the emit-site.** Find which normalizer flattens `FileCollectionValue` for
      versioning:
      ```bash
      P=/var/www/pim
      sudo -u akeneo php $P/bin/console debug:container --tag=pim_versioning.normalizer 2>&1 | head -40
      grep -rn 'file_path\|ignored\|getFlatValues\|implode' \
        $P/src/Smartoys/FileCollectionBundle/{Normalizer,Model,ArrayConverter,Updater} 2>/dev/null
      # Akeneo side (how the product flat snapshot/changeset is built):
      grep -rln 'ChangesetBuilder\|VersionBuilder\|class .*Flat.*Normalizer' \
        $P/vendor/akeneo/pim-community-dev/src/Akeneo/Tool/Bundle/VersioningBundle 2>/dev/null
      ```
      Read `Model/FileCollectionValue.php` (string repr ~lines 74–76),
      `Normalizer/Indexing/Value/FileCollectionNormalizer.php` (returns `getData()`),
      and `Updater/Comparator/FileCollectionComparator.php`.

- [ ] **Step 4 — Prove the off-by-one.** Once the emit-site is known, build the column the way
      that code does for the *clean* 13-item value and show it yields 14 / mismatched counts.
      Prime suspect: an `ignored`-skip that still advances the separator/index (cf. `getFlatValues`).

- [ ] **Step 5 — Scope the blast radius.** Confirm it is purely cosmetic at scale (no product has
      *real* duplicate/desynced collection data). Sample N products and verify per collection:
      distinct `file_key` count == item count, and `sort_order` is a 0..n-1 run. (Console script over
      `pim_catalog_product.raw_values` JSON, or export + check.) Record how many history rows are
      affected so the noise cost is quantified.

## 6. Remediation options

- **C. RECOMMENDED — add a versioning normalizer for `FileCollectionValue`** (own bundle, no
  vendor patch). Register a normalizer tagged `pim_versioning.serializer.normalizer` that
  `supportsNormalization($data, $format)` for `$data instanceof FileCollectionValue &&
  in_array($format, ['flat','csv'])` and returns a **deterministic, self-consistent** single
  column, e.g. `['graphic_collection' => implode(',', file_paths_sorted_by_sort_order)]`
  (mirror `Model/FileCollectionValue` string form). Because it `supports` the value directly,
  it pre-empts Akeneo's buggy `ValueNormalizer`→`CollectionNormalizer` path. Result: unchanged
  collections normalize identically every save → **no spurious changeset**, history accurate.
  Add to `Smartoys/FileCollectionBundle` (or a small `Cld` bundle that decorates/overrides it).
  Same deploy discipline as the dedup hotfix (`.bak` + PHP 8.3 lint + opcache note).
- **A. Patch stock `CollectionNormalizer`** — change the `else` branch from
  `array_replace($result, $normalizedItem)` to `$result[$key] = $value;`. Correct and tiny, but
  it is **`vendor/akeneo`** (lost on `composer update`, and changes behaviour for *all* collection
  values) → avoid unless upstreamed.
- **E. Accept as cosmetic** — data is safe; leave it and revisit if the Smartoys bundle is replaced.
- **D. One-time `pim:versioning:refresh`** does NOT fix this (it recurs every save) — skip.

> Prefer **C**: minimal, well-commented, own-bundle (like the Cld dedup fix), survives composer
> updates, scoped to this one attribute type. Keep a `.bak` on deploy (no prod pipeline yet).

## 7. Definition of done

- [ ] Root cause identified and written up (which line, which hypothesis).
- [ ] Two consecutive saves of a product with an **unchanged** FileCollection produce **no**
      `file_path`/`ignored` entry in the new version's changeset (verified on test VM).
- [ ] Confirmed **no real data corruption** across the catalog (Step 5 sample + spot SQL).
- [ ] Decision recorded: fix (A/B/C) **or** accept-as-cosmetic (E) **or** one-time refresh (D).
- [ ] If patched: deployed to prod over SSH with `.bak` backup + PHP 8.3 lint + opcache note,
      same procedure as the dedup hotfix.

## 8. References

- Affected attr types: `smartoys_catalog_file_collection` → `graphic_collection`, `user_manual`,
  `graphic_collection_bonus`.
- Bundle (servers only): `/var/www/pim/src/Smartoys/FileCollectionBundle`
  - `Model/FileCollectionValue.php` — value + string repr (`ITEMS_DELIMITER ', '`, `PROPERTY_DELIMITER ':::'`)
  - `ArrayConverter/StandardToFlat/Product/ValueConverter/FileCollectionConverter.php` — `getFlatValues()` off-by-one candidate
  - `Normalizer/Indexing/Value/FileCollectionNormalizer.php`, `Updater/Comparator/FileCollectionComparator.php`
- Versioning: table `pim_versioning_version` (`resource_uuid`, `snapshot`, `changeset`, `version`,
  `pending`, `logged_at`); command `pim:versioning:refresh`.
- Read-only media verifier: `pim:cld:file-storage:media-snapshot <IDENTIFIER> [--out FILE]`.
- Test VM: `ssh dsonnet@192.168.81.137` (`pim7`, disposable clone) — safe to experiment.
- Prod: `ssh dsonnet@192.168.81.147` (`pim7`) — **observe only**, do not reproduce here.

## 9. Caveats / notes

- The `snapshot`/`changeset` columns are PHP-`serialize()`d — decode, don't eyeball.
- A separate, **more urgent** prod-adjacent issue exists: stock Akeneo ES projection throws
  `ConversionException … datetime_immutable … "…000000"` on product save (seen on test VM .137,
  not in prod .147 logs) — track that independently; it 500s API writes while still persisting.
- Versions may be `pending=1` until `pim:versioning:refresh` runs; account for that when reading
  the table right after a save.
