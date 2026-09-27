# Phase 4: Prescription understanding

Local backend and pharmacist review only. This phase does not add a Flutter client, a live vision provider, pickup orders, instruction notes, payment, notification, or delivery.

Phase 3 `GET /api/v1/availability` is unchanged. Prescription responses point at that route. They do not replace it and they do not reserve stock.

## States

Extraction and review are separate.

Extraction, on one prescription version:

`queued` → `processing` → `completed` or `failed`

A worker claims `queued`, or a `processing` row whose claim lease has expired, for a specific `extraction_version`. The claim stores a token. Publication, retry release, and final failure all require that same token and version. An older worker cannot publish, reset, or fail a newer claim. `prescriptions:recover-claims` queues another attempt after the lease expires, so a dead worker does not leave a live row in `processing` forever. Failure before the last attempt releases only the current claim back to `queued`. The last attempt, a timeout that exhausts the attempts, or an explicit provider failure sets `failed` and a failure code. `not_extracted` means the file was stored and was not read. `pdf_not_extracted` means the original PDF is stored and can be shared, and that no lines were produced. `provider_not_approved` means no approved reader is configured. `completed` with no lines is a finished read, and the synthetic stand-in produces that only for an intentional empty fixture.

Review, for the current content version:

`none` → `pending` → `verified` or `rejected`

`pending`, `verified`, or `rejected` can move to `withdrawn`. A content change (corrected line or replaced file) sets the active share back to `none` and increments `content_version`. Historical `prescription_reviews` rows stay attached to the version they reviewed. Only `share_state=verified` together with `shared_content_version=content_version` is a current verification. A later order confirmation must compare those versions again. This phase does not create that order.

`deleted_at` is terminal for customer and pharmacist access. A pending, verified, or rejected share must be withdrawn before deletion.

## Who can read the original file

| State | Customer owner | Credential-verified pharmacist at the shared pharmacy | Everyone else |
| --- | --- | --- | --- |
| Not deleted, share `none` or `withdrawn` | Yes, on each request | No | No |
| Not deleted, share `pending` and versions match | Yes | Yes, on each request | No |
| Verified or rejected, until withdrawn or edited | Yes | No. The decision is already recorded. | No |
| Deleted, or content version no longer matches the share | No | No | No |

The customer list returns every non-deleted prescription, 50 at a time, ordered by `created_at` and `id` descending. `has_more` and `next_cursor` say whether another page exists. The last page has no next cursor. Only an omitted cursor starts the first page. An empty or malformed cursor is rejected instead of restarting there. The page boundary keeps all six fractional digits of `created_at`.

A suspended customer is rejected by the customer middleware. A suspended pharmacy, suspended membership, removed staff member, or unverified/expired/rejected credential fails the pharmacist check inside the read and inside the review transaction. Another customer or another pharmacy receives the same not-found response as a missing prescription.

## Selected items and the full image

`selected_items` requires at least one line the customer explicitly selected, and that line must be an exact catalog variant. At the share boundary every selected UUID is locked and checked again: it must still be that same catalog row, the stored identity fingerprint must still match, and the medicine must still be active and not controlled. A stale or ineligible selection is rejected. It is not dropped or replaced with another variant. Sharing is not a stock reservation or an order. Any future order-confirmation path must check eligibility again. Availability stays a separate Phase 3 request for those UUIDs. The discovery limit remains 1–10 distinct medicines, and every requested medicine must match. If the selection is larger than the limit, the prescription response sets `availability.blocked` and does not drop IDs.

`full_image` shares the original file with one eligible approved Faisalabad pharmacy even when extraction failed or no line matched. `full_image_contents_identified` is always false. The response does not say the pharmacy has every medicine in the image.

## Storage and extraction

The object key is `rx/{uuid}`. The client filename is sanitized into `display_name` and is never a path or part of the object key. The API does not return the object key. Outside tests the disk is Amazon S3 in the configured region, using the AWS SDK credential chain. A missing bucket or region, or a custom endpoint, fails closed. The local testing disk is available only when the application environment is testing. Tests do not call AWS. The upload size is rejected from the reported length, or after a capped read, before the rest of the file is loaded. JPEG and PNG must decode with GD inside 8 MB, 8000 pixels on a side, and 4,000,000 pixels. The header is checked before the bitmap is allocated, and the bitmap is discarded. A PNG without image data, a PNG whose image data does not decode, a JPEG without a decodable scan, and a truncated scan are rejected. A PDF is sent to a separate worker running the official qpdf 12.4.1 linux-x86_64 build. That worker allows 8 MB, at most 20 pages, and a MediaBox of at most 14400 points on a side. It decodes every stream and rejects encrypted, truncated, malformed, and oversized files. Encryption is qpdf's structural encryption object, so the characters `/Encrypt` in page text are not encryption. qpdf 12.4.1 does not warn on a dangling trailer `/Encrypt` reference, omits that key from the JSON trailer, and reports the file as unencrypted. The missing indirect reference is represented as null, the same shape as a legitimate null object, so that file is accepted. Encryption is decided only from qpdf's structural encrypt object. qpdf exit 3 is accepted only when every warning is a missing page Resources repair. A damaged cross-reference table or an undefined MediaBox is rejected. Each page is checked through its effective MediaBox, using that page's box or the nearest inherited box. A MediaBox found elsewhere in the file does not stand in for a page. The worker process is limited to 256 MB, half a CPU, 64 processes, and 8 seconds. It is attached only to an internal Docker network with no route beyond the application containers that call it. It does not execute JavaScript, launch actions, or external references, and it has no cloud credentials. There is no in-app PDF preview; the original file is an attachment. Filename, browser MIME, magic bytes, an end marker, a checksum, and a trailer substring are not enough. The file response is an attachment with `no-store` and `nosniff`.

A hold row is written before the object. If `put` throws or returns a false result, the hold stays `purge_pending` even when a following existence check does not see the object. Only a completed delete marks it `purged`. The prescription row is written after the object and marks the hold attached. A replacement reads the displaced key from the locked row after any concurrent update in that transaction, and it erases only that key, after the transaction commits and only when no live row still names it. `prescriptions:prune-orphans` does not list every stored key. It walks pending rows and holds by id, attempts each eligible id at most once per run, and stores the cursor in `prescription_work_cursors`. The next scheduled run continues past a failing head before wrapping, including after a cache flush or a gap longer than a day. Before it calls storage, cleanup claims an aged open hold by setting `purge_pending` while the hold row is locked. Attachment only succeeds for an open hold, so that upload cannot commit afterward. Storage is called only after the lock is released. The hold is marked `purged` only after deletion when a later check confirms the object is absent and no live prescription names it. If the attempt that first claims an open hold finds no object, it leaves `purge_pending`, so a PUT that finishes after that deletion stays retryable. An object that was already present is removed in that attempt once absence is confirmed. An open hold is eligible only after the upload grace period. A recent in-progress upload is left alone. The same command then lists the `rx/` prefix in pages of 50, up to the work limit, and continues from a database cursor. An object with no live prescription and no non-purged hold is deleted only when its storage timestamp is older than the 900-second grace period. A PUT that lands after an earlier absence check therefore stays until a later run, and a live or in-flight object is not removed. The grace period is not a retention period. Erasure is claimed only when GetBucketVersioning has no Status key and Object Lock is disabled. A malformed versioning response is not treated as unversioned. That read happens before every PUT, including replacement, and before every delete, and a successful result is not cached. Unknown versioning or lock values fail closed, so no bytes are written. Deployment IAM must deny unauthorized bucket versioning, Object Lock, and bucket-policy changes between the read and the write. `prescriptions:recover-claims` uses the same database cursor, so a full page of expired claims cannot hide later ones or be queued twice in one run, and a cache flush does not restart at the same head. Extraction is dispatched with `afterCommit`, so a rolled-back upload does not enqueue work. PHP is configured with `zend.exception_ignore_args`, so exception traces do not include file bytes, object keys, or tokens.

The synthetic extractor is available only when the environment is `local` or `testing` and the setting is explicitly `synthetic`. It reads only the SHA-256 and MIME type plus fixtures configured in the process. It does not read a network and it does not invent a confidence score. Without a fixture it returns `not_extracted`. Any other environment or setting uses the unconfigured extractor and returns `provider_not_approved`. Provider text is stored as data, including text that looks like an instruction to a model. Matching never substitutes a different brand, strength, form, or manufacturer. Unmatched lines are kept. Inactive and controlled medicines are `ineligible` and cannot be selected for pickup.

General-use text comes only from `medicine_use_publications`. Draft `medicine_uses` rows stay drafts. The Phase 2 check that rejects `review_status=approved` on `medicine_uses` is unchanged.

## Edits, review, deletion, and retries

A correction increments `content_version`, clears the active share, and rematches the corrected line. A replaced file increments both versions, clears the share, and queues only the new version. An older job cannot claim the new version, and a replay cannot insert a second copy of the current lines or write after `deleted_at` is set.

Pharmacist review locks the membership and the prescription. It records an immutable review with the actor, membership, pharmacy, both versions, the decision, and the time. Rejection requires a reason. A concurrent review, a resubmission, or a stale form returns `review_stale` when the pending version no longer matches.

Withdrawal keeps the share columns so the database check stays valid, and sets `withdrawn`, which removes pharmacist access. Deletion removes customer and pharmacist access immediately, deletes extracted lines, and keeps only a minimal audit record. The response `deleted: true` means access is gone. `file_erasure` is `completed` only after storage confirms the object is gone, and `pending` when storage throws or returns false. A pending object stays `purge_pending` and the daily command retries it. A failed attempt does not restore access. The command counts only confirmed removals; a failed removal is a non-zero exit and a pending warning. Audit rows remain, without image bytes, raw text, object keys, or the original filename.

## Unresolved product decisions

- The Amazon S3 bucket, Block Public Access, TLS-only policy, encryption at rest, prefix-scoped IAM permissions, access logging, and credential rotation are documented requirements and are not provisioned here. Those IAM controls must also prevent unauthorized changes to bucket versioning, Object Lock, and the bucket policy between the application's erasure check and the write. Live AWS verification is pending those resources.
- Live vision provider: vendor, region, credentials, cost, timeout, retention of provider payloads, and the customer disclosure shown before any external transfer. Until that decision, the extractor stays local or unconfigured.
- Production retention period under Pakistani legal review. No numeric production default is set.
- The Flutter customer client is not part of this phase.
