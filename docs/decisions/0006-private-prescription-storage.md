# ADR 0006: Private prescription storage

## Status

Accepted for Phase 4. The earlier MinIO local-development choice is withdrawn.

## Context

Prescription files need a private object store. The application reports erasure only after a delete is confirmed. A versioned or Object Locked bucket can keep historical bytes after the current object is gone, so that report would be false. Tests must not call AWS. A live vision provider and a production retention period are still unapproved. Amazon S3 resources for this project have not been provisioned by this change.

## Decision

- The non-test prescription disk is Amazon S3 through Laravel's Flysystem adapter. The client uses the AWS SDK default credential chain for the configured region. Static access keys are optional and are not required for an IAM role. A custom endpoint or URL is rejected. `use_path_style_endpoint` is accepted only when it is absent or boolean false. Any enabled or unexpected value fails closed before an object-store call.
- `PRESCRIPTION_BUCKET` and `PRESCRIPTION_REGION` are required before any non-test prescription storage operation. There is no fallback disk and no default bucket.
- PHPUnit uses the local `prescriptions_testing` disk only while the application environment is `testing`. Selecting that disk in any other environment fails closed. Tests do not open an AWS connection.
- Objects are private. The API and the pharmacist panel stream authorized bytes as attachments with `no-store` and `nosniff`. They do not publish object URLs or presigned links.
- Erasure requires an unversioned bucket with Object Lock disabled. Before every prescription PUT, including a replacement, and before every delete, the gate reads that bucket's versioning and Object Lock status. It does not cache a successful result. Only a GetBucketVersioning response with no Status key means the bucket is unversioned. An empty, non-string, or otherwise malformed Status fails closed. Any other lock status than a confirmed disabled configuration fails closed, and no bytes are written. A missing current object is not treated as erasure of older versions. Deployment IAM must deny `s3:PutBucketVersioning`, `s3:PutObjectLockConfiguration`, and bucket-policy changes for the application role and every other principal that is not the controlled administrator, so those settings cannot change between the read and the write.
- Object keys are `rx/{uuid}`. Names, phone numbers, medicine names, and original filenames are not part of the key.
- PDF bytes are checked by a separate qpdf 12.4.1 worker with a memory cap, a CPU cap, a process cap, a wall-clock timeout, and no credentials. The PHP request process does not parse the PDF. Encryption is qpdf's structural encryption object. qpdf 12.4.1 reports a dangling trailer `/Encrypt` reference as unencrypted, without a warning, and that file is accepted. A legitimate null object is also accepted. qpdf exit 3 is accepted only for a missing page Resources repair. The worker validates the effective MediaBox of each page, including a box inherited from an ancestor.
- `PRESCRIPTION_EXTRACTOR=synthetic` remains a deterministic local stand-in for `local` and `testing` only. It is not a vision model and it does not invent medicines.
- `PRESCRIPTION_RETENTION_DAYS` has no production default.

## Consequences

Local prescription upload fails closed until a bucket, region, and credentials are supplied outside Git. Image scanning covers the PDF worker and no longer references MinIO. A blocking-scan pass is a policy result, not a claim of zero vulnerabilities. Provisioning the bucket, Block Public Access, TLS-only access, encryption, a prefix-scoped IAM policy, logging, and credential rotation is still an operational task and is not done here.
