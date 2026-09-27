<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Jobs\ExtractPrescription;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionObjectHold;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use App\Prescriptions\Extraction\ExtractionTimeout;
use App\Prescriptions\Extraction\PrescriptionExtractor;
use App\Prescriptions\PrescriptionFileInspector;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ScriptedObjectStore;
use Tests\TestCase;

class PrescriptionSafetyRemediationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPEG = 'ffd8ffe000104a46494600010101006000600000fffe003b43524541544f523a2067642d6a7065672076312e3020287573696e6720494a47204a50454720763632292c207175616c697479203d2039300affdb0043000302020302020303030304030304050805050404050a070706080c0a0c0c0b0a0b0b0d0e12100d0e110e0b0b1016101113141515150c0f171816141812141514ffdb00430103040405040509050509140d0b0d1414141414141414141414141414141414141414141414141414141414141414141414141414141414141414141414141414ffc00011080001000103012200021101031101ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffc4001f0100030101010101010101010000000000000102030405060708090a0bffc400b51100020102040403040705040400010277000102031104052131061241510761711322328108144291a1b1c109233352f0156272d10a162434e125f11718191a262728292a35363738393a434445464748494a535455565758595a636465666768696a737475767778797a82838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae2e3e4e5e6e7e8e9eaf2f3f4f5f6f7f8f9faffda000c03010002110311003f00fcaaa28a2803ffd9';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'rx-safety-admin@example.test',
            'password' => 'not-a-login-secret',
            'role' => UserRole::Admin,
            'status' => AccountStatus::Active,
        ]);
        Storage::disk('prescriptions_testing')->delete(Storage::disk('prescriptions_testing')->allFiles('rx'));
        Config::set('medifind.prescriptions.testing_hooks.after_object_put', null);
        Config::set('medifind.prescriptions.testing_hooks.after_claim', null);
    }

    public function test_successful_deletion_removes_access_and_the_object(): void
    {
        [$token, $row] = $this->uploaded();
        $disk = Storage::disk('prescriptions_testing');
        $this->assertTrue($disk->exists($row->object_key));

        $this->destroyPrescription($token, $row)
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.file_erasure', 'completed');

        $row->refresh();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('purged', $row->object_state);
        $this->assertFalse($disk->exists($row->object_key));
        $this->withToken($token)->getJson('/api/v1/prescriptions/'.$row->public_id)->assertNotFound();
        $this->assertAuditDoesNotKeepMedicalContent('prescription_object_purged', 'completed');
    }

    public function test_storage_exception_keeps_erasure_pending_and_retryable(): void
    {
        [$token, $row] = $this->uploaded();
        $disk = Storage::disk('prescriptions_testing');
        $store = new ScriptedObjectStore;
        $store->mode = 'throw';
        $this->app->instance(PrescriptionObjectStore::class, $store);

        $this->destroyPrescription($token, $row)
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.file_erasure', 'pending');

        $row->refresh();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('purge_pending', $row->object_state);
        $this->assertTrue($disk->exists($row->object_key));
        $this->withToken($token)->getJson('/api/v1/prescriptions/'.$row->public_id)->assertNotFound();
        $this->assertAuditDoesNotKeepMedicalContent('prescription_deleted', 'pending');

        $this->app->forgetInstance(PrescriptionObjectStore::class);
        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        $row->refresh();
        $this->assertSame('purged', $row->object_state);
        $this->assertFalse($disk->exists($row->object_key));
        $this->withToken($token)->getJson('/api/v1/prescriptions/'.$row->public_id)->assertNotFound();
    }

    public function test_false_storage_result_is_not_reported_as_erased(): void
    {
        [$token, $row] = $this->uploaded();
        $disk = Storage::disk('prescriptions_testing');
        $store = new ScriptedObjectStore;
        $store->mode = 'false';
        $this->app->instance(PrescriptionObjectStore::class, $store);

        $this->destroyPrescription($token, $row)
            ->assertOk()
            ->assertJsonPath('data.file_erasure', 'pending');
        $this->assertTrue($disk->exists($row->object_key));
        $this->withToken($token)->getJson('/api/v1/prescriptions/'.$row->public_id)->assertNotFound();

        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 0 prescription objects.')
            ->expectsOutputToContain('remain pending erasure.')
            ->assertFailed();
        $this->assertTrue($disk->exists($row->refresh()->object_key));
        $this->assertSame('purge_pending', $row->object_state);
        $this->assertNotNull($row->deleted_at);
    }

    public function test_cleanup_keeps_an_in_flight_upload_and_removes_an_abandoned_one(): void
    {
        $disk = Storage::disk('prescriptions_testing');
        $paused = false;
        Config::set('medifind.prescriptions.testing_hooks.after_object_put', function () use (&$paused): void {
            $paused = true;
            $this->artisan('prescriptions:prune-orphans')->assertSuccessful();
        });
        [$token, $row] = $this->uploaded();
        $this->assertTrue($paused);
        $stored = $disk->get($row->object_key);
        $this->assertIsString($stored);
        $this->assertSame(hash('sha256', $this->png()), hash('sha256', $stored));
        $this->withToken($token)->getJson('/api/v1/prescriptions/'.$row->public_id)->assertOk();

        $abandoned = 'rx/'.Str::uuid();
        $disk->put($abandoned, $this->png());
        PrescriptionObjectHold::query()->forceCreate([
            'object_key' => $abandoned,
            'status' => 'open',
            'created_at' => now()->subHours(2),
        ]);
        $this->artisan('prescriptions:prune-orphans')
            ->expectsOutputToContain('Erased 1 prescription objects.')
            ->assertSuccessful();
        $this->assertFalse($disk->exists($abandoned));
        $this->assertTrue($disk->exists($row->object_key));
    }

    public function test_cleanup_rechecks_a_hold_before_deleting_a_referenced_object(): void
    {
        [, $row] = $this->uploaded();
        $hold = PrescriptionObjectHold::query()->where('object_key', $row->object_key)->firstOrFail();
        $hold->forceFill(['status' => 'open', 'created_at' => now()->subHours(2)])->save();

        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();

        $this->assertTrue(Storage::disk('prescriptions_testing')->exists($row->object_key));
        $this->assertSame('attached', $hold->refresh()->status);
    }

    public function test_interrupted_claim_recovers_after_the_lease_and_a_stale_worker_cannot_publish(): void
    {
        Config::set('medifind.prescriptions.fixtures', [
            hash('sha256', $this->png()) => [
                'lines' => [
                    ['text' => 'Cetirizine 10 mg', 'generic_name' => 'Cetirizine', 'strength' => '10 mg', 'dosage_form' => 'Tablet', 'manufacturer' => 'Epsilon Labs'],
                ],
            ],
        ]);
        [, $row] = $this->uploaded();
        $token = (string) Str::uuid();
        $row->forceFill([
            'extraction_state' => 'processing',
            'claim_token' => $token,
            'claimed_at' => now(),
        ])->save();

        (new ExtractPrescription($row->id, $row->extraction_version))->runNow();
        $row->refresh();
        $this->assertSame('processing', $row->extraction_state);
        $this->assertSame($token, $row->claim_token);

        $row->forceFill(['claimed_at' => now()->subHours(2)])->save();
        $recovered = false;
        Config::set('medifind.prescriptions.testing_hooks.after_claim', function () use (&$recovered, $row): void {
            Config::set('medifind.prescriptions.testing_hooks.after_claim', null);
            $recovered = true;
            $row->refresh();
            $row->forceFill(['claimed_at' => now()->subHours(2)])->save();
            (new ExtractPrescription($row->id, $row->extraction_version))->runNow();
            $row->refresh();
            $this->assertSame('completed', $row->extraction_state);
            throw new ExtractionTimeout;
        });

        (new ExtractPrescription($row->id, $row->extraction_version))->runNow();
        $row->refresh();
        $this->assertTrue($recovered);
        $this->assertSame('completed', $row->extraction_state);
        $this->assertNull($row->claim_token);
        $this->assertSame(1, PrescriptionItem::query()->where('prescription_id', $row->id)->count());
    }

    public function test_ordinary_retry_exhausted_attempts_replacement_and_deletion_stay_truthful(): void
    {
        Config::set('medifind.prescriptions.fixtures', [
            hash('sha256', $this->jpeg()) => ['outcome' => 'timeout'],
        ]);
        [$token, $timed] = $this->uploaded($this->jpeg(), 'slow.jpg', 'image/jpeg');
        $retry = new ExtractPrescription($timed->id, $timed->extraction_version);

        try {
            $retry->runNow();
            $this->fail('A timeout before the last attempt must be retried.');
        } catch (ExtractionTimeout) {
            $this->assertSame('queued', $timed->refresh()->extraction_state);
            $this->assertNull($timed->claim_token);
        }

        $retry->tries = 1;
        $retry->runNow();
        $this->assertSame('failed', $timed->refresh()->extraction_state);
        $this->assertSame('extraction_timeout', $timed->failure_code);

        [$second, $row] = $this->uploaded();
        $replaced = $this->withToken($second)->post('/api/v1/prescriptions', [
            'prescription_id' => $row->public_id,
            'file' => UploadedFile::fake()->createWithContent('replacement.png', $this->png()),
        ]);
        $replaced->assertCreated();
        (new ExtractPrescription($row->id, 1))->runNow();
        $this->assertSame(2, $row->refresh()->extraction_version);
        $this->assertSame('queued', $row->extraction_state);
        $this->assertSame(0, PrescriptionItem::query()->where('prescription_id', $row->id)->where('extraction_version', 2)->count());

        $this->destroyPrescription($second, $row)->assertOk();
        (new ExtractPrescription($row->id, $row->extraction_version))->runNow();
        $this->assertNotNull($row->refresh()->deleted_at);
        $this->assertNotSame('processing', $row->extraction_state);
        $this->withToken($second)->getJson('/api/v1/prescriptions/'.$row->public_id)->assertNotFound();

        Queue::fake();
        $stuck = Prescription::query()->whereKey($timed->id)->firstOrFail();
        $stuck->forceFill([
            'extraction_state' => 'processing',
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subHours(2),
            'deleted_at' => null,
            'object_state' => 'stored',
        ])->save();
        $this->artisan('prescriptions:recover-claims')->assertSuccessful();
        Queue::assertPushed(ExtractPrescription::class);
    }

    public function test_share_rejects_a_selection_that_became_inactive_controlled_or_different(): void
    {
        $inactive = $this->shareSetup('Inactive Share');
        app(MedicineCatalog::class)->deactivate($this->admin, $inactive['medicine']);
        $this->assertShareRejected($inactive);

        $controlled = $this->shareSetup('Controlled Share');
        app(MedicineCatalog::class)->update($this->admin, $controlled['medicine'], $this->identity($controlled['medicine'], '10 mg', true));
        $this->assertShareRejected($controlled);

        $changed = $this->shareSetup('Changed Share');
        app(MedicineCatalog::class)->update($this->admin, $changed['medicine'], $this->identity($changed['medicine'], '20 mg', false));
        $this->assertShareRejected($changed);

        $image = $this->shareSetup('Image Share');
        app(MedicineCatalog::class)->deactivate($this->admin, $image['medicine']);
        $this->withToken($image['token'])->postJson('/api/v1/prescriptions/'.$image['row']->public_id.'/share', [
            'mode' => 'full_image',
            'pharmacy_id' => $image['pharmacy'],
        ])->assertOk()->assertJsonPath('data.share_state', 'pending')->assertJsonPath('data.share_mode', 'full_image');
    }

    public function test_files_are_size_bounded_and_structurally_validated(): void
    {
        $token = $this->token($this->customer('rx-files@example.test', '+923001110201'));
        $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('note.pdf', $this->pdf(false)),
        ])->assertCreated();
        $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', $this->jpeg()),
        ])->assertCreated();
        $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('spoof.pdf', $this->jpeg()),
        ])->assertCreated();
        $spoofed = Prescription::query()->where('display_name', 'spoof.pdf')->firstOrFail();
        $this->assertSame('image/jpeg', $spoofed->mime_type);
        $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('dangling.pdf', $this->pdf(true)),
        ])->assertCreated();
        foreach ([
            substr($this->pdf(false), 0, (int) (strlen($this->pdf(false)) / 2)),
            "%PDF-1.4\nnot a pdf\n",
            substr($this->jpeg(), 0, -8),
            substr($this->png(), 0, -8),
        ] as $bytes) {
            $this->withToken($token)->post('/api/v1/prescriptions', [
                'file' => UploadedFile::fake()->createWithContent('bad.bin', $bytes),
            ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_prescription_file');
        }

        $probe = new ReportedOversizedUpload(tempnam(sys_get_temp_dir(), 'rx'), 'big.bin', 'application/octet-stream', null, true);

        try {
            app(PrescriptionWorkflow::class)->upload($this->customer('rx-size@example.test', '+923001110202'), $probe);
            $this->fail('An oversized upload was accepted.');
        } catch (ApiProblem $problem) {
            $this->assertSame(422, $problem->status);
            $this->assertSame('invalid_prescription_file', $problem->errorCode);
        }

        stream_wrapper_register('rxcap', RemediationByteStream::class);
        RemediationByteStream::$read = 0;
        $capped = new UnsizedUpload(tempnam(sys_get_temp_dir(), 'rx'), 'stream.bin', 'application/octet-stream', null, true);
        $inspector = app(PrescriptionFileInspector::class);

        try {
            $inspector->read($capped);
            $this->fail('An unbounded stream was accepted.');
        } catch (ApiProblem $problem) {
            $this->assertSame(422, $problem->status);
            $this->assertLessThanOrEqual(PrescriptionFileInspector::MAX_BYTES + 8192, RemediationByteStream::$read);
        } finally {
            stream_wrapper_unregister('rxcap');
        }
    }

    public function test_synthetic_extraction_fails_closed_without_a_fixture_or_approved_provider(): void
    {
        $token = $this->token($this->customer('rx-read@example.test', '+923001110203'));
        $bytes = $this->png();
        Config::set('medifind.prescriptions.fixtures', [
            hash('sha256', $bytes) => [
                'lines' => [
                    ['text' => 'Cetirizine 10 mg', 'generic_name' => 'Cetirizine', 'strength' => '10 mg', 'dosage_form' => 'Tablet', 'manufacturer' => 'Epsilon Labs'],
                ],
            ],
        ]);
        $fixture = Prescription::query()->where('public_id', $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('known.png', $bytes),
        ])->assertCreated()->json('data.id'))->firstOrFail();
        (new ExtractPrescription($fixture->id, $fixture->extraction_version))->runNow();
        $this->assertSame('completed', $fixture->refresh()->extraction_state);
        $this->assertSame(1, PrescriptionItem::query()->where('prescription_id', $fixture->id)->count());

        Config::set('medifind.prescriptions.fixtures', [
            hash('sha256', $this->jpeg()) => ['outcome' => 'empty'],
        ]);
        $empty = Prescription::query()->where('public_id', $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('empty.jpg', $this->jpeg()),
        ])->json('data.id'))->firstOrFail();
        (new ExtractPrescription($empty->id, $empty->extraction_version))->runNow();
        $emptyShown = $this->withToken($token)->getJson('/api/v1/prescriptions/'.$empty->public_id)->assertOk();
        $emptyShown->assertJsonPath('data.extraction_state', 'completed')
            ->assertJsonPath('data.failure_code', null)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.status_text', 'Reading finished. These lines are candidates, not a pharmacist verification.');

        Config::set('medifind.prescriptions.fixtures', []);
        $unknown = Prescription::query()->where('public_id', $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('unknown.jpg', $this->jpeg()),
        ])->json('data.id'))->firstOrFail();
        (new ExtractPrescription($unknown->id, $unknown->extraction_version))->runNow();
        $unknownShown = $this->withToken($token)->getJson('/api/v1/prescriptions/'.$unknown->public_id)->assertOk();
        $unknownShown->assertJsonPath('data.extraction_state', 'failed')
            ->assertJsonPath('data.failure_code', 'not_extracted')
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.status_text', 'This file was stored for pharmacist review and was not read as a prescription.');

        $pdf = Prescription::query()->where('public_id', $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', $this->pdf(false)),
        ])->json('data.id'))->firstOrFail();
        (new ExtractPrescription($pdf->id, $pdf->extraction_version))->runNow();
        $this->withToken($token)->getJson('/api/v1/prescriptions/'.$pdf->public_id)
            ->assertJsonPath('data.failure_code', 'pdf_not_extracted')
            ->assertJsonPath('data.status_text', 'PDF text was not extracted. The original file can be shared for pharmacist review.');

        $workflow = app(PharmacyWorkflow::class);
        $pharmacy = $workflow->create($this->admin, [
            'name' => 'Unread Pharmacy',
            'address_text' => 'Unread Susan Road',
            'latitude' => '31.450400',
            'longitude' => '73.135000',
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
        ]);
        $workflow->verifyLicense($this->admin, $pharmacy, 'Reviewed license file');
        $workflow->approveLocation($this->admin, $pharmacy, 'Reviewed map pin');
        $workflow->approve($this->admin, $pharmacy->refresh());
        $this->withToken($token)->postJson('/api/v1/prescriptions/'.$unknown->public_id.'/share', [
            'mode' => 'full_image',
            'pharmacy_id' => $pharmacy->id,
        ])->assertOk()->assertJsonPath('data.share_state', 'pending');

        $original = $this->app['env'];

        try {
            $this->app['env'] = 'production';
            Config::set('medifind.prescriptions.extractor', 'synthetic');
            $result = app(PrescriptionExtractor::class)->extract(hash('sha256', $bytes), 'image/png');
            $this->assertTrue($result->failed);
            $this->assertSame('provider_not_approved', $result->failureCode);
        } finally {
            $this->app['env'] = $original;
            Config::set('medifind.prescriptions.extractor', 'synthetic');
        }
    }

    public function test_object_store_reports_false_and_thrown_deletes_as_unsuccessful(): void
    {
        $logged = '';
        Log::listen(function ($event) use (&$logged): void {
            $logged .= $event->message;
        });
        $disk = \Mockery::mock();
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->andReturn($disk);

        $this->assertFalse(app(PrescriptionObjectStore::class)->delete('rx/not-logged'));
        $this->assertStringNotContainsString('rx/', $logged);
        $this->assertStringContainsString('prescription_object_delete_failed', $logged);
    }

    /**
     * @return array{0: string, 1: Prescription}
     */
    private function uploaded(?string $bytes = null, string $name = 'rx.png', string $mime = 'image/png'): array
    {
        $token = $this->token($this->customer('rx-'.Str::lower(Str::random(8)).'@example.test', '+92300'.random_int(1000000, 9999999)));
        $created = $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes ?? $this->png()),
        ])->assertCreated();
        $row = Prescription::query()->where('public_id', $created->json('data.id'))->firstOrFail();

        return [$token, $row];
    }

    private function destroyPrescription(string $token, Prescription $row): TestResponse
    {
        return $this->withToken($token)->deleteJson('/api/v1/prescriptions/'.$row->public_id);
    }

    private function assertAuditDoesNotKeepMedicalContent(string $action, string $erasure): void
    {
        $metadata = DB::table('audit_events')->where('action', $action)->orderByDesc('created_at')->value('metadata');
        $this->assertIsString($metadata);
        $decoded = json_decode($metadata, true);
        $this->assertSame(['file_erasure' => $erasure], $decoded);
    }

    /**
     * @return array{token: string, row: Prescription, medicine: Medicine, pharmacy: string}
     */
    private function shareSetup(string $name): array
    {
        $token = $this->token($this->customer(Str::lower(Str::random(6)).'@example.test', '+92300'.random_int(1000000, 9999999)));
        $medicine = app(MedicineCatalog::class)->create($this->admin, [
            'generic_name' => 'Cetirizine '.$name,
            'dosage_form' => 'Tablet',
            'strength' => '10 mg',
            'manufacturer' => 'Epsilon Labs',
        ]);
        app(MedicineCatalog::class)->activate($this->admin, $medicine);
        $medicine->refresh();
        $workflow = app(PharmacyWorkflow::class);
        $pharmacy = $workflow->create($this->admin, [
            'name' => $name,
            'address_text' => $name.' Susan Road',
            'latitude' => '31.450400',
            'longitude' => '73.135000',
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
        ]);
        $workflow->verifyLicense($this->admin, $pharmacy, 'Reviewed license file');
        $workflow->approveLocation($this->admin, $pharmacy, 'Reviewed map pin');
        $workflow->approve($this->admin, $pharmacy->refresh());
        $bytes = $this->png();
        Config::set('medifind.prescriptions.fixtures', [
            hash('sha256', $bytes) => [
                'lines' => [
                    ['text' => 'Cetirizine', 'generic_name' => $medicine->generic_name, 'strength' => '10 mg', 'dosage_form' => 'Tablet', 'manufacturer' => 'Epsilon Labs'],
                ],
            ],
        ]);
        $row = Prescription::query()->where('public_id', $this->withToken($token)->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->createWithContent('share.png', $bytes),
        ])->assertCreated()->json('data.id'))->firstOrFail();
        (new ExtractPrescription($row->id, $row->extraction_version))->runNow();
        $item = PrescriptionItem::query()->where('prescription_id', $row->id)->firstOrFail();
        $this->withToken($token)->patchJson('/api/v1/prescriptions/'.$row->public_id.'/items/'.$item->public_id, [
            'text' => $medicine->generic_name.', 10 mg, Tablet, Epsilon Labs',
            'selected' => true,
            'selected_medicine_id' => (string) $medicine->id,
        ])->assertOk();

        return [
            'token' => $token,
            'row' => $row,
            'medicine' => $medicine->refresh(),
            'pharmacy' => (string) $pharmacy->id,
        ];
    }

    /**
     * @param  array{token: string, row: Prescription, medicine: Medicine, pharmacy: string}  $setup
     */
    private function assertShareRejected(array $setup): void
    {
        $this->withToken($setup['token'])->postJson('/api/v1/prescriptions/'.$setup['row']->public_id.'/share', [
            'mode' => 'selected_items',
            'pharmacy_id' => $setup['pharmacy'],
        ])->assertStatus(422);
        $setup['row']->refresh();
        $this->assertSame('none', $setup['row']->share_state);
        $this->assertNull($setup['row']->pharmacy_id);
        $this->assertTrue((bool) PrescriptionItem::query()->where('prescription_id', $setup['row']->id)->value('customer_selected'));
    }

    /**
     * @return array{generic_name: string, dosage_form: string, strength: string, manufacturer: string, controlled_drug: bool}
     */
    private function identity(Medicine $medicine, string $strength, bool $controlled): array
    {
        return [
            'generic_name' => (string) $medicine->generic_name,
            'dosage_form' => (string) $medicine->dosage_form,
            'strength' => $strength,
            'manufacturer' => (string) $medicine->manufacturer,
            'controlled_drug' => $controlled,
        ];
    }

    private function customer(string $email, string $phone): User
    {
        $user = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'phone_e164' => $phone,
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
        ]);

        foreach (['upload', 'edit', 'share', 'read'] as $action) {
            RateLimiter::clear('prescription:'.$action.':'.$user->id);
        }

        return $user;
    }

    private function token(User $user): string
    {
        return $user->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
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
}

final class ReportedOversizedUpload extends UploadedFile
{
    public function getSize(): int
    {
        return PrescriptionFileInspector::MAX_BYTES + 1;
    }

    public function getContent(): string
    {
        throw new \RuntimeException('unbounded read');
    }

    public function getRealPath(): string
    {
        throw new \RuntimeException('unbounded read');
    }
}

final class UnsizedUpload extends UploadedFile
{
    public function getSize(): false
    {
        return false;
    }

    public function getRealPath(): string
    {
        return 'rxcap://blob';
    }

    public function getContent(): string
    {
        throw new \RuntimeException('unbounded read');
    }
}

final class RemediationByteStream
{
    public static int $read = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $count = max(0, $count);
        self::$read += $count;

        return str_repeat('A', $count);
    }

    public function stream_eof(): bool
    {
        return self::$read >= 20 * 1024 * 1024;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
