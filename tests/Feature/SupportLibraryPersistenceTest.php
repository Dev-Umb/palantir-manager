<?php

namespace Tests\Feature;

use App\Actions\SaveSupportLibrary;
use App\Models\SupportLibraryVersion;
use App\Support\SupportLibraryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\SupportLibraryFixtures;
use Tests\TestCase;

class SupportLibraryPersistenceTest extends TestCase
{
    use RefreshDatabase, SupportLibraryFixtures;

    /**
     * A basic feature test example.
     */
    public function test_versions_reject_stale_writes_and_idempotent_retry_returns_the_same_snapshot(): void
    {
        $this->initializeLibrary();
        $writer = app(SaveSupportLibrary::class);
        $user = $this->supportUser();
        $payload = $writer->current()->payload_json;
        $old = $payload;
        $payload['parts'][0]['unit_weight_kg'] = 500;
        $id = (string) Str::uuid();
        $new = $writer->save($payload, 1, $id, $user);
        $this->assertSame(2, $new->revision);
        $this->assertSame($new->id, $writer->save($payload, 1, $id, $user)->id);
        $this->assertSame(430.3, SupportLibraryVersion::where('revision', 1)->first()->payload_json['parts'][0]['unit_weight_kg']);
        $this->assertDatabaseCount('support_library_versions', 2);
        $this->expectException(SupportLibraryException::class);
        $writer->save($old, 1, (string) Str::uuid(), $user);
    }

    public function test_invalid_save_or_failed_pointer_update_cannot_leave_a_partial_version(): void
    {
        $this->initializeLibrary();
        $writer = app(SaveSupportLibrary::class);
        $user = $this->supportUser();
        $db = $writer->current()->payload_json;
        $db['composition'][0]['part_id'] = 'missing';
        try {
            $writer->save($db, 1, (string) Str::uuid(), $user);
            $this->fail('Invalid association accepted');
        } catch (SupportLibraryException) {
            $this->assertDatabaseCount('support_library_versions', 1);
        }
        SupportLibraryVersion::created(function (): void {
            throw new \RuntimeException('injected write failure');
        });
        try {
            $writer->save($writer->current()->payload_json, 1, (string) Str::uuid(), $user);
            $this->fail('Failure expected');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected write failure', $error->getMessage());
            $this->assertDatabaseCount('support_library_versions', 1);
            $this->assertSame(1, $writer->current()->revision);
        } finally {
            SupportLibraryVersion::flushEventListeners();
            SupportLibraryVersion::clearBootedModels();
        }
    }

    public function test_initialization_refuses_to_replace_existing_work(): void
    {
        $this->artisan('support-library:initialize')->assertSuccessful();
        $this->artisan('support-library:initialize')->assertFailed();
        $this->assertDatabaseCount('support_library_versions', 1);
        $this->assertCount(52, app(SaveSupportLibrary::class)->current()->payload_json['parts']);
    }

    public function test_historical_versions_cannot_be_updated(): void
    {
        $this->initializeLibrary();
        $this->expectException(\LogicException::class);
        app(SaveSupportLibrary::class)->current()->update(['payload_sha256' => 'changed']);
    }
}
