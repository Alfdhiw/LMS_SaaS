<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateTenantMetadataRequest;
use App\Models\User;
use App\Modules\Platform\Services\TenantMetadataService;
use Symfony\Component\HttpFoundation\Response;

final class TenantMetadataController extends Controller
{
    public function __construct(private readonly TenantMetadataService $metadata) {}

    public function update(UpdateTenantMetadataRequest $request, string $publicUlid): Response
    {
        $actor = $request->attributes->get('central_user');
        abort_unless($actor instanceof User, 403);
        $this->metadata->update($request, $actor, $publicUlid, $request->validated('name'), $request->validated('timezone'));

        return $request->expectsJson()
            ? response()->json(['status' => 'updated'])
            : redirect()->route('central.tenants.show', $publicUlid)->with('status', 'Metadata sekolah diperbarui.');
    }
}
