<?php

namespace Tests\Feature;

use App\Console\Commands\PrunePrescriptionObjects;
use App\Console\Commands\RecoverPrescriptionClaims;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Jobs\ExtractPrescription;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionObjectHold;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use App\Prescriptions\ImageStructureValidator;
use App\Prescriptions\PdfStructureValidator;
use App\Prescriptions\PrescriptionFileInspector;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Support\ScriptedObjectStore;
use Tests\TestCase;
use Throwable;

class PrescriptionClosureRemediationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPEG = 'ffd8ffe000104a46494600010101006000600000fffe003b43524541544f523a2067642d6a7065672076312e3020287573696e6720494a47204a50454720763632292c207175616c697479203d2039300affdb0043000302020302020303030304030304050805050404050a070706080c0a0c0c0b0a0b0b0d0e12100d0e110e0b0b1016101113141515150c0f171816141812141514ffdb00430103040405040509050509140d0b0d1414141414141414141414141414141414141414141414141414141414141414141414141414141414141414141414141414ffc00011080001000103012200021101031101ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffc4001f0100030101010101010101010000000000000102030405060708090a0bffc400b51100020102040403040705040400010277000102031104052131061241510761711322328108144291a1b1c109233352f0156272d10a162434e125f11718191a262728292a35363738393a434445464748494a535455565758595a636465666768696a737475767778797a82838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae2e3e4e5e6e7e8e9eaf2f3f4f5f6f7f8f9faffda000c03010002110311003f00fcaaa28a2803ffd9';

    private const BOGUS_JPEG = 'ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffda00080001000100003f00fbffd9';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'rx-closure-admin@example.test',
            'password' => 'not-a-login-secret',
            'role' => UserRole::Admin,
            'status' => AccountStatus::Active,
        ]);
        Storage::disk('prescriptions_testing')->delete(Storage::disk('prescriptions_testing')->allFiles('rx'));
        Config::set('medifind.prescriptions.testing_hooks.after_object_put', null);
        Config::set('medifind.prescriptions.testing_hooks.after_claim', null);
        Config::set('medifind.prescriptions.testing_hooks.before_replace_save', null);
        Config::set('medifind.prescriptions.testing_hooks.before_hold_claim', null);
        Config::set('medifind.prescriptions.testing_hooks.after_hold_claim', null);
        Config::set('medifind.prescriptions.testing_hooks.after_hold_delete', null);
        Config::set('medifind.prescriptions.prune_attempt_limit', 2000);
        Config::set('medifind.prescriptions.claim_recover_limit', 200);
        Cache::forget(PrunePrescriptionObjects::PRESCRIPTION_CURSOR);
        Cache::forget(PrunePrescriptionObjects::HOLD_CURSOR);
        Cache::forget(RecoverPrescriptionClaims::CURSOR_KEY);
    }

    public function test_overlapping_replacements_erase_only_the_object_each_one_displaced(): void
    {
        $customer = $this->customer();
        $workflow = app(PrescriptionWorkflow::class);
        $original = $this->png();
        $middle = $this->jpeg();
        $final = $this->pngWithNote();
        $row = $workflow->upload($customer, $this->upload($original, 'original.png'));
        $before = $this->snapshot($row);

        Config::set('medifind.prescriptions.testing_hooks.before_replace_save', function () use ($workflow, $customer, $row, $middle): void {
            Config::set('medifind.prescriptions.testing_hooks.before_replace_save', null);
            $workflow->replaceFile($customer, $row, $this->upload($middle, 'middle.jpg'));
        });

        $updated = $workflow->replaceFile($customer, $row, $this->upload($final, 'final.png'));
        $updated->refresh();
        $stored = Storage::disk('prescriptions_testing')->get($updated->object_key);

        $this->assertIsString($stored);
        $this->assertSame(hash('sha256', $final), hash('sha256', $stored));
        $this->assertSame(hash('sha256', $final), $updated->sha256);
        $this->assertSame($before['version'] + 2, $updated->extraction_version);
        $this->assertAttachedOnlyToCurrent($updated);
        $this->assertEarlierObjectsAreNotAttached($updated);
        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $updated->refresh();
        $this->assertSame(0, $this->lingeringObjects($updated));
        $this->assertSame(hash('sha256', $final), hash('sha256', (string) Storage::disk('prescriptions_testing')->get($updated->object_key)));

        (new ExtractPrescription($updated->id, $before['version']))->runNow();
        $updated->refresh();
        $this->assertSame($before['version'] + 2, $updated->extraction_version);
        $this->assertSame('queued', $updated->extraction_state);
        $this->assertSame(0, PrescriptionItem::query()->where('prescription_id', $updated->id)->count());
    }

    public function test_a_failed_displaced_erasure_stays_pending_and_is_retried(): void
    {
        $customer = $this->customer();
        $workflow = app(PrescriptionWorkflow::class);
        $row = $workflow->upload($customer, $this->upload($this->png(), 'original.png'));
        $originalHash = $row->sha256;
        $store = new ScriptedObjectStore;
        $store->mode = 'false';
        $this->app->instance(PrescriptionObjectStore::class, $store);
        $workflow = app(PrescriptionWorkflow::class);
        $updated = $workflow->replaceFile($customer, $row, $this->upload($this->jpeg(), 'next.jpg'));
        $displaced = PrescriptionObjectHold::query()->where('status', 'purge_pending')->get();

        $this->assertCount(1, $displaced);
        $this->assertTrue(Storage::disk('prescriptions_testing')->exists($displaced[0]->object_key));
        $this->assertFalse(hash_equals($updated->object_key, $displaced[0]->object_key));
        $this->assertTrue(Storage::disk('prescriptions_testing')->exists($updated->object_key));

        $this->app->forgetInstance(PrescriptionObjectStore::class);
        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $this->assertFalse(Storage::disk('prescriptions_testing')->exists($displaced[0]->object_key));
        $this->assertSame('purged', $displaced[0]->refresh()->status);
        $kept = Storage::disk('prescriptions_testing')->get($updated->object_key);
        $this->assertIsString($kept);
        $this->assertSame(hash('sha256', $this->jpeg()), hash('sha256', $kept));
        $this->assertNotSame($originalHash, $updated->refresh()->sha256);
    }

    public function test_an_unsuccessful_replacement_does_not_change_the_saved_file(): void
    {
        $customer = $this->customer();
        $workflow = app(PrescriptionWorkflow::class);
        $pharmacy = app(PharmacyWorkflow::class);
        $created = $pharmacy->create($this->admin, [
            'name' => 'Closure Pharmacy',
            'address_text' => 'Closure Susan Road',
            'latitude' => '31.450400',
            'longitude' => '73.135000',
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
        ]);
        $pharmacy->verifyLicense($this->admin, $created, 'Reviewed license file');
        $pharmacy->approveLocation($this->admin, $created, 'Reviewed map pin');
        $pharmacy->approve($this->admin, $created->refresh());
        $row = $workflow->upload($customer, $this->upload($this->png(), 'shared.png'));
        $shared = $workflow->share($customer, $row, 'full_image', (string) $created->id);
        $before = $this->snapshot($shared);
        Config::set('medifind.prescriptions.testing_hooks.before_replace_save', function (): void {
            throw new RuntimeException('storage');
        });

        try {
            $workflow->replaceFile($customer, $shared, $this->upload($this->jpeg(), 'replacement.jpg'));
            $this->fail('The replacement was stored.');
        } catch (RuntimeException) {
            $shared->refresh();
            $this->assertUnchanged($before, $shared);
            $stored = Storage::disk('prescriptions_testing')->get($shared->object_key);
            $this->assertIsString($stored);
            $this->assertSame($before['sha256'], hash('sha256', $stored));
            $this->assertSame('pending', $shared->share_state);
            $this->assertSame('full_image', $shared->share_mode);
        }
    }

    public function test_upload_put_that_writes_then_throws_is_cleaned_up_later(): void
    {
        $this->assertUncertainUpload('write_then_throw', true, RuntimeException::class);
    }

    public function test_upload_put_that_writes_then_returns_false_is_cleaned_up_later(): void
    {
        $this->assertUncertainUpload('write_then_false', true, ApiProblem::class);
    }

    public function test_upload_put_that_fails_before_writing_is_idempotent_to_clean_up(): void
    {
        $this->assertUncertainUpload('throw', false, RuntimeException::class);
        $this->assertUncertainUpload('false', false, ApiProblem::class);
    }

    public function test_replacement_put_that_writes_then_throws_does_not_replace_the_row(): void
    {
        $this->assertUncertainReplace('write_then_throw', true, RuntimeException::class);
    }

    public function test_replacement_put_that_writes_then_returns_false_does_not_replace_the_row(): void
    {
        $this->assertUncertainReplace('write_then_false', true, ApiProblem::class);
    }

    public function test_replacement_put_that_fails_before_writing_leaves_the_saved_file(): void
    {
        $this->assertUncertainReplace('throw', false, RuntimeException::class);
        $this->assertUncertainReplace('false', false, ApiProblem::class);
    }

    public function test_prune_reaches_prescriptions_beyond_a_failing_first_page(): void
    {
        $customer = $this->customer();
        $keys = $this->seedPurgeRows($customer, 60);
        $ordered = Prescription::query()->orderBy('id')->pluck('object_key')->all();
        $store = new ScriptedObjectStore;
        foreach (array_slice($ordered, 0, 50) as $key) {
            $store->denyDelete[$key] = true;
        }
        $this->app->instance(PrescriptionObjectStore::class, $store);

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 10 prescription objects.')
            ->expectsOutputToContain('50 prescription objects remain pending erasure.')
            ->assertFailed();

        $this->assertSame(50, $this->present(array_slice($ordered, 0, 50)));
        $this->assertSame(0, $this->present(array_slice($ordered, 50)));
        $this->assertSame(10, Prescription::query()->where('object_state', 'purged')->count());
        $this->assertCount(60, $keys);
    }

    public function test_prune_reaches_holds_beyond_a_failing_first_page(): void
    {
        $keys = [];

        for ($index = 0; $index < 60; $index++) {
            $key = 'rx/'.Str::uuid()->toString();
            $keys[] = $key;
            Storage::disk('prescriptions_testing')->put($key, 'x');
            PrescriptionObjectHold::query()->forceCreate([
                'object_key' => $key,
                'status' => 'purge_pending',
                'created_at' => now()->subHour(),
            ]);
        }

        $ordered = PrescriptionObjectHold::query()->orderBy('id')->pluck('object_key')->all();
        $store = new ScriptedObjectStore;
        foreach (array_slice($ordered, 0, 50) as $key) {
            $store->denyDelete[$key] = true;
        }
        $this->app->instance(PrescriptionObjectStore::class, $store);

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 10 prescription objects.')
            ->expectsOutputToContain('50 prescription objects remain pending erasure.')
            ->assertFailed();

        $this->assertSame(50, $this->present(array_slice($ordered, 0, 50)));
        $this->assertSame(0, $this->present(array_slice($ordered, 50)));

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 0 prescription objects.')
            ->expectsOutputToContain('50 prescription objects remain pending erasure.')
            ->assertFailed();
        $this->assertSame(0, $this->present(array_slice($ordered, 50)));
        $this->assertCount(60, $keys);
    }

    public function test_prune_cursor_survives_cache_loss_and_more_than_a_day(): void
    {
        Config::set('medifind.prescriptions.prune_attempt_limit', 4);
        $keys = [];

        for ($index = 0; $index < 12; $index++) {
            $key = 'rx/'.Str::uuid()->toString();
            $keys[] = $key;
            Storage::disk('prescriptions_testing')->put($key, 'x');
            PrescriptionObjectHold::query()->forceCreate([
                'object_key' => $key,
                'status' => 'purge_pending',
                'created_at' => now()->subHour(),
            ]);
        }

        $ordered = PrescriptionObjectHold::query()->orderBy('id')->pluck('object_key')->all();
        $failing = array_slice($ordered, 0, 4);
        $store = new ScriptedObjectStore;

        foreach ($failing as $key) {
            $store->denyDelete[$key] = true;
        }

        $this->app->instance(PrescriptionObjectStore::class, $store);

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 0 prescription objects.')
            ->expectsOutputToContain('12 prescription objects remain pending erasure.')
            ->assertFailed();
        $this->assertSame(12, $this->present($ordered));

        Cache::flush();
        Carbon::setTestNow(now()->addHours(30));

        try {
            $this->artisan('prescriptions:prune-orphans')
                ->expectsOutputToContain('Erased 4 prescription objects.')
                ->expectsOutputToContain('8 prescription objects remain pending erasure.')
                ->assertFailed();
            $this->assertSame(4, $this->present($failing));
            $this->assertSame(0, $this->present(array_slice($ordered, 4, 4)));
            $this->assertSame(4, $this->present(array_slice($ordered, 8)));

            $this->artisan('prescriptions:prune-orphans')
                ->expectsOutputToContain('Erased 4 prescription objects.')
                ->expectsOutputToContain('4 prescription objects remain pending erasure.')
                ->assertFailed();
            $this->assertSame(0, $this->present(array_slice($ordered, 4)));
            $this->assertSame(4, $this->present($failing));

            Cache::flush();
            $this->artisan('prescriptions:prune-orphans')
                ->expectsOutputToContain('Erased 0 prescription objects.')
                ->expectsOutputToContain('4 prescription objects remain pending erasure.')
                ->assertFailed();
            $this->assertSame(4, $this->present($failing));

            $extra = 'rx/'.Str::uuid()->toString();
            Storage::disk('prescriptions_testing')->put($extra, 'x');
            PrescriptionObjectHold::query()->forceCreate([
                'object_key' => $extra,
                'status' => 'purge_pending',
                'created_at' => now()->subHour(),
            ]);
            Cache::flush();

            $this->artisan('prescriptions:prune-orphans')->assertFailed();
            $this->artisan('prescriptions:prune-orphans')->assertFailed();
            $this->assertSame(0, $this->present([$extra]));
            $this->assertSame(4, $this->present($failing));
            $this->assertSame('purged', PrescriptionObjectHold::query()->where('object_key', $extra)->value('status'));
            $this->assertSame(4, PrescriptionObjectHold::query()->where('status', 'purge_pending')->count());
        } finally {
            Carbon::setTestNow();
            $this->app->forgetInstance(PrescriptionObjectStore::class);
        }

        $this->assertCount(12, $keys);
    }

    public function test_upload_that_attaches_before_cleanup_keeps_its_object(): void
    {
        $customer = $this->customer();
        $hold = $this->agedHold(true);
        $bytes = $this->png();

        Config::set('medifind.prescriptions.testing_hooks.before_hold_claim', function () use ($customer, $hold, $bytes): void {
            Storage::disk('prescriptions_testing')->put($hold->object_key, $bytes);
            $attached = $this->attachHold($hold->object_key);
            $this->assertTrue($attached);
            Prescription::query()->forceCreate([
                'public_id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'object_key' => $hold->object_key,
                'display_name' => 'race.png',
                'mime_type' => 'image/png',
                'byte_size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'extraction_state' => 'queued',
                'share_state' => 'none',
                'object_state' => 'stored',
            ]);
        });

        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $this->assertSame('attached', $hold->refresh()->status);
        $this->assertSame(1, Prescription::query()->whereNull('deleted_at')->count());
        $this->assertSame(1, $this->present([$hold->object_key]));
        $this->assertNoUntrackedObject();
    }

    public function test_cleanup_claim_prevents_a_later_upload_from_attaching(): void
    {
        $hold = $this->agedHold(true);

        Config::set('medifind.prescriptions.testing_hooks.after_hold_claim', function () use ($hold): void {
            $this->assertFalse($this->attachHold($hold->object_key));
            $this->assertSame(0, Prescription::query()->count());
        });

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 1 prescription objects.')
            ->assertSuccessful();
        $this->assertSame('purged', $hold->refresh()->status);
        $this->assertSame(0, Prescription::query()->count());
        $this->assertSame(0, $this->present([$hold->object_key]));
        $this->assertNoUntrackedObject();
    }

    public function test_put_that_lands_after_cleanup_deletion_stays_retryable(): void
    {
        $hold = $this->agedHold(false);
        $bytes = $this->png();

        Config::set('medifind.prescriptions.testing_hooks.after_hold_delete', function () use ($hold, $bytes): void {
            Storage::disk('prescriptions_testing')->put($hold->object_key, $bytes);
        });

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('1 prescription objects remain pending erasure.')
            ->assertFailed();
        $this->assertSame('purge_pending', $hold->refresh()->status);
        $this->assertSame(0, Prescription::query()->count());
        $this->assertSame(1, $this->present([$hold->object_key]));

        Config::set('medifind.prescriptions.testing_hooks.after_hold_delete', null);
        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $this->assertSame('purged', $hold->refresh()->status);
        $this->assertSame(0, $this->present([$hold->object_key]));
        $this->assertNoUntrackedObject();
    }

    public function test_failed_hold_deletion_stays_pending_until_a_later_attempt_confirms_absence(): void
    {
        foreach (['false', 'throw'] as $mode) {
            $hold = $this->agedHold(true);
            $store = new ScriptedObjectStore;
            $store->mode = $mode;
            $this->app->instance(PrescriptionObjectStore::class, $store);

            $this->artisan('prescriptions:prune-orphans')
                ->expectsOutputToContain('1 prescription objects remain pending erasure.')
                ->assertFailed();
            $this->assertSame('purge_pending', $hold->refresh()->status);
            $this->assertSame(0, Prescription::query()->count());
            $this->assertSame(1, $this->present([$hold->object_key]));

            $this->app->forgetInstance(PrescriptionObjectStore::class);
            $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
            $this->assertSame('purged', $hold->refresh()->status);
            $this->assertSame(0, $this->present([$hold->object_key]));
            $hold->delete();
        }

        $this->assertNoUntrackedObject();
    }

    public function test_claim_recovery_reaches_claims_beyond_the_first_page_once_each(): void
    {
        Config::set('medifind.prescriptions.claim_recover_limit', 50);
        $customer = $this->customer();

        for ($index = 0; $index < 60; $index++) {
            Prescription::query()->forceCreate([
                'public_id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'object_key' => 'rx/'.Str::uuid()->toString(),
                'display_name' => 'claim.png',
                'mime_type' => 'image/png',
                'byte_size' => 1,
                'sha256' => hash('sha256', 'claim-'.$index),
                'extraction_state' => 'processing',
                'extraction_version' => 1,
                'content_version' => 1,
                'share_state' => 'none',
                'object_state' => 'stored',
                'claimed_at' => now()->subHour(),
            ]);
        }

        $ordered = Prescription::query()->orderBy('id')->pluck('id')->all();
        Queue::fake();
        $this->artisan('prescriptions:recover-claims')->assertSuccessful();
        $first = $this->queuedClaimIds();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(now()->addHours(30));

        try {
            $this->artisan('prescriptions:recover-claims')->assertSuccessful();
            $second = $this->queuedClaimIds();
        } finally {
            Carbon::setTestNow();
        }
        $missed = array_values(array_diff($ordered, $first));

        $this->assertCount(50, $first);
        $this->assertCount(50, array_unique($first));
        $this->assertCount(50, $second);
        $this->assertCount(50, array_unique($second));
        $this->assertCount(10, $missed);
        $this->assertSame(10, count(array_intersect($missed, $second)));
    }

    public function test_image_and_pdf_bytes_must_decode_not_only_end_cleanly(): void
    {
        $inspector = app(PrescriptionFileInspector::class);
        $this->assertSame('image/png', $inspector->inspect($this->png())['mime']);
        $this->assertSame('image/jpeg', $inspector->inspect($this->jpeg())['mime']);
        $this->assertSame('application/pdf', $inspector->inspect($this->pdf(false))['mime']);

        $images = app(ImageStructureValidator::class);
        $pdfs = app(PdfStructureValidator::class);
        $this->assertRejected(fn () => $images->assertPng($this->pngWithoutImage()));
        $this->assertRejected(fn () => $images->assertPng($this->pngWithBadImage()));
        $this->assertRejected(fn () => $images->assertJpeg($this->jpegWithoutScan()));
        $this->assertRejected(fn () => $images->assertJpeg($this->bogusJpeg()));
        $this->assertRejected(fn () => $images->assertJpeg(substr($this->jpeg(), 0, -2)));
        $this->assertRejected(fn () => $pdfs->assertSupported($this->pdfWithTrailerOnly()));
        $this->assertRejected(fn () => $pdfs->assertSupported($this->pdfWithBadXref()));
        $pdfs->assertSupported($this->pdf(true));
        $this->assertRejected(fn () => $pdfs->assertSupported(substr($this->pdf(false), 0, 40)));
        $this->assertRejected(fn () => $inspector->inspect($this->pngWithBadImage()));

        $customer = $this->customer();
        $token = $customer->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
        $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => $this->upload($this->pngWithoutImage(), 'empty.png'),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_prescription_file');

        try {
            $inspector->read(new ReportedOversizedClosureUpload(tempnam(sys_get_temp_dir(), 'rx'), 'big.bin', 'application/octet-stream', null, true));
            $this->fail('The oversized file was read.');
        } catch (ApiProblem $problem) {
            $this->assertSame('invalid_prescription_file', $problem->errorCode);
        }
    }

    private function assertUncertainUpload(string $mode, bool $wrote, string $exception): void
    {
        $store = new ScriptedObjectStore;
        $store->putMode = $mode;
        $this->app->instance(PrescriptionObjectStore::class, $store);
        $logged = '';
        Log::listen(function ($event) use (&$logged): void {
            $logged .= ' '.$event->message;
        });

        try {
            app(PrescriptionWorkflow::class)->upload($this->customer(), $this->upload($this->png(), 'upload.png'));
            $this->fail('The upload was stored.');
        } catch (AssertionFailedError $failed) {
            throw $failed;
        } catch (Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);
        }

        $this->assertSame(0, Prescription::query()->count());
        $hold = PrescriptionObjectHold::query()->firstOrFail();
        $this->assertSame('purge_pending', $hold->status);
        $this->assertSame($wrote, Storage::disk('prescriptions_testing')->exists($hold->object_key));
        $this->assertStringNotContainsString('rx/', $logged);

        $this->app->forgetInstance(PrescriptionObjectStore::class);
        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $this->assertFalse(Storage::disk('prescriptions_testing')->exists($hold->object_key));
        $this->assertSame('purged', $hold->refresh()->status);
        PrescriptionObjectHold::query()->delete();
    }

    private function assertUncertainReplace(string $mode, bool $wrote, string $exception): void
    {
        PrescriptionObjectHold::query()->delete();
        $customer = $this->customer();
        $row = app(PrescriptionWorkflow::class)->upload($customer, $this->upload($this->png(), 'kept.png'));
        $before = $this->snapshot($row);
        $store = new ScriptedObjectStore;
        $store->putMode = $mode;
        $this->app->instance(PrescriptionObjectStore::class, $store);

        try {
            app(PrescriptionWorkflow::class)->replaceFile($customer, $row, $this->upload($this->jpeg(), 'next.jpg'));
            $this->fail('The replacement was stored.');
        } catch (AssertionFailedError $failed) {
            throw $failed;
        } catch (Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);
        }

        $row->refresh();
        $this->assertUnchanged($before, $row);
        $extra = PrescriptionObjectHold::query()->where('object_key', '!=', $row->object_key)->get();
        $this->assertCount(1, $extra);
        $this->assertSame('purge_pending', $extra[0]->status);
        $this->assertSame($wrote, Storage::disk('prescriptions_testing')->exists($extra[0]->object_key));
        $kept = Storage::disk('prescriptions_testing')->get($row->object_key);
        $this->assertIsString($kept);
        $this->assertSame($before['sha256'], hash('sha256', $kept));

        $this->app->forgetInstance(PrescriptionObjectStore::class);
        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $this->assertFalse(Storage::disk('prescriptions_testing')->exists($extra[0]->object_key));
        $this->assertSame('purged', $extra[0]->refresh()->status);
        $this->assertTrue(Storage::disk('prescriptions_testing')->exists($row->object_key));
        Storage::disk('prescriptions_testing')->delete($row->object_key);
        $row->delete();
        PrescriptionObjectHold::query()->delete();
    }

    /**
     * @param  list<string>  $keys
     */
    private function present(array $keys): int
    {
        $present = 0;

        foreach ($keys as $key) {
            if (Storage::disk('prescriptions_testing')->exists($key)) {
                $present++;
            }
        }

        return $present;
    }

    private function agedHold(bool $withObject): PrescriptionObjectHold
    {
        $key = 'rx/'.Str::uuid()->toString();

        if ($withObject) {
            Storage::disk('prescriptions_testing')->put($key, $this->png());
        }

        return PrescriptionObjectHold::query()->forceCreate([
            'object_key' => $key,
            'status' => 'open',
            'created_at' => now()->subHour(),
        ]);
    }

    /**
     * RefreshDatabase keeps the fixture uncommitted, so another connection cannot
     * see it. This calls the production attachment statement on the test connection
     * at the hook point instead.
     */
    private function attachHold(string $key): bool
    {
        try {
            $method = new \ReflectionMethod(PrescriptionWorkflow::class, 'attachHold');
            $method->invoke(app(PrescriptionWorkflow::class), $key);

            return true;
        } catch (ApiProblem $problem) {
            $this->assertSame('storage_unavailable', $problem->errorCode);

            return false;
        }
    }

    private function assertNoUntrackedObject(): void
    {
        $live = Prescription::query()->whereNull('deleted_at')->pluck('object_key')->all();
        $this->assertSame(count($live), $this->present($live));

        $retryable = PrescriptionObjectHold::query()
            ->where('status', '!=', 'purged')
            ->pluck('object_key')
            ->all();
        $files = Storage::disk('prescriptions_testing')->allFiles('rx');
        $this->assertSame(0, count(array_diff($files, array_values(array_unique(array_merge($live, $retryable))))));
        $this->assertSame(0, $this->present(
            PrescriptionObjectHold::query()->where('status', 'purged')->pluck('object_key')->all(),
        ));
    }

    /**
     * @return list<string>
     */
    private function seedPurgeRows(User $customer, int $count): array
    {
        $keys = [];

        for ($index = 0; $index < $count; $index++) {
            $key = 'rx/'.Str::uuid()->toString();
            $keys[] = $key;
            Storage::disk('prescriptions_testing')->put($key, 'x');
            Prescription::query()->forceCreate([
                'public_id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'object_key' => $key,
                'display_name' => 'old.png',
                'mime_type' => 'image/png',
                'byte_size' => 1,
                'sha256' => hash('sha256', 'purge-'.$index),
                'extraction_state' => 'failed',
                'extraction_version' => 1,
                'content_version' => 1,
                'share_state' => 'none',
                'object_state' => 'purge_pending',
                'deleted_at' => now(),
            ]);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function queuedClaimIds(): array
    {
        $ids = [];

        foreach (Queue::pushed(ExtractPrescription::class) as $job) {
            $ids[] = $job->prescriptionId;
        }

        return $ids;
    }

    /**
     * @param  array{version: int, content: int, sha256: string, share: string, mode: ?string, key: string}  $before
     */
    private function assertUnchanged(array $before, Prescription $row): void
    {
        $this->assertSame($before['version'], $row->extraction_version);
        $this->assertSame($before['content'], $row->content_version);
        $this->assertSame($before['sha256'], $row->sha256);
        $this->assertSame($before['share'], $row->share_state);
        $this->assertSame($before['mode'], $row->share_mode);
        $this->assertTrue(hash_equals($before['key'], $row->object_key));
    }

    private function assertAttachedOnlyToCurrent(Prescription $row): void
    {
        $attached = PrescriptionObjectHold::query()->where('status', 'attached')->pluck('object_key');
        $this->assertTrue($attached->count() === 1 && hash_equals($row->object_key, (string) $attached->first()));
    }

    private function assertEarlierObjectsAreNotAttached(Prescription $row): void
    {
        $this->assertSame(0, PrescriptionObjectHold::query()
            ->where('object_key', '!=', $row->object_key)
            ->where('status', 'attached')
            ->count());
    }

    private function lingeringObjects(Prescription $row): int
    {
        $lingering = 0;

        foreach (PrescriptionObjectHold::query()->where('object_key', '!=', $row->object_key)->get() as $hold) {
            if ($hold->status !== 'purged' || Storage::disk('prescriptions_testing')->exists($hold->object_key)) {
                $lingering++;
            }
        }

        return $lingering;
    }

    /**
     * @return array{version: int, content: int, sha256: string, share: string, mode: ?string, key: string}
     */
    private function snapshot(Prescription $row): array
    {
        return [
            'version' => $row->extraction_version,
            'content' => $row->content_version,
            'sha256' => $row->sha256,
            'share' => $row->share_state,
            'mode' => $row->share_mode,
            'key' => $row->object_key,
        ];
    }

    private function assertRejected(callable $check): void
    {
        try {
            $check();
            $this->fail('A malformed file was accepted.');
        } catch (ApiProblem $problem) {
            $this->assertSame('invalid_prescription_file', $problem->errorCode);
        }
    }

    private function customer(): User
    {
        $user = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'rx-'.Str::lower(Str::random(8)).'@example.test',
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
        ]);

        foreach (['upload', 'edit', 'share', 'read'] as $action) {
            RateLimiter::clear('prescription:'.$action.':'.$user->id);
        }

        return $user;
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function png(): string
    {
        $bytes = base64_decode(self::PNG, true);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function jpeg(): string
    {
        $bytes = hex2bin(self::JPEG);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function pngWithNote(): string
    {
        $png = $this->png();
        $iend = strrpos($png, 'IEND');
        $this->assertIsInt($iend);

        return substr($png, 0, $iend - 4).$this->chunk('tEXt', "Comment\x00note").substr($png, $iend - 4);
    }

    private function pngWithoutImage(): string
    {
        return "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', pack('NN', 1, 1).chr(8).chr(2).chr(0).chr(0).chr(0)).$this->chunk('IEND', '');
    }

    private function pngWithBadImage(): string
    {
        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('NN', 1, 1).chr(8).chr(2).chr(0).chr(0).chr(0))
            .$this->chunk('IDAT', "\x78\x9c\x01")
            .$this->chunk('IEND', '');
    }

    private function bogusJpeg(): string
    {
        $bytes = hex2bin(self::BOGUS_JPEG);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function jpegWithoutScan(): string
    {
        $bytes = hex2bin('ffd8ffe000104a46494600010100000100010000ffc0000b080001000101011100ffd9');
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private function pdf(bool $encrypted): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>\nendobj\n",
        ];
        $header = "%PDF-1.4\n";
        $body = '';
        $offsets = [];
        $cursor = strlen($header);

        foreach ($objects as $object) {
            $offsets[] = $cursor;
            $body .= $object;
            $cursor += strlen($object);
        }

        $xref = "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $xref .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $encrypt = $encrypted ? ' /Encrypt 9 0 R' : '';

        return $header.$body.$xref.'trailer
<< /Size '.(count($objects) + 1).' /Root 1 0 R'.$encrypt.' >>
startxref
'.$cursor.'
%%EOF
';
    }

    private function pdfWithTrailerOnly(): string
    {
        $header = "%PDF-1.4\n";

        return $header."xref\n0 1\n0000000000 65535 f \ntrailer\n<< /Size 1 /Root 1 0 R >>\nstartxref\n".strlen($header)."\n%%EOF\n";
    }

    private function pdfWithBadXref(): string
    {
        $header = "%PDF-1.4\n";
        $body = "trailer text is not an object\n";
        $cursor = strlen($header.$body);
        $xref = "xref\n0 2\n0000000000 65535 f \n".str_pad('9', 10, '0', STR_PAD_LEFT)." 00000 n \n";

        return $header.$body.$xref.'trailer
<< /Size 2 /Root 1 0 R >>
startxref
'.$cursor.'
%%EOF
';
    }
}

final class ReportedOversizedClosureUpload extends UploadedFile
{
    public function getSize(): int
    {
        return PrescriptionFileInspector::MAX_BYTES + 1;
    }

    public function getContent(): string
    {
        throw new RuntimeException('unbounded read');
    }
}
