<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantFileDownloadController extends AppBaseController
{
    public function __invoke(string $category, string $filename): StreamedResponse
    {
        abort_unless(in_array($category, ['excel', 'pdf'], true), 404);
        abort_unless((bool) preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $filename), 404);

        $path = tenantMediaPath($category.'/'.$filename);
        $disk = Storage::disk('tenant_private');
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $filename);
    }
}
