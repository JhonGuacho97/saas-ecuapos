<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class LoginLogController extends AppBaseController
{
    public function index(Request $request)
    {
        $perPage = getPageSize($request);

        $page = $request->input('page.number', 1);

        $logs = $this->logsForCurrentStore()
            ->with('user:id,first_name,last_name')
            ->when($request->search, fn($q) => $q->where('email', 'like', "%{$request->search}%"))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderByDesc('logged_at')
            ->paginate($perPage, ['*'], 'page', $page);
        return response()->json($logs);
    }

    public function getIpLocation(string $ip)
    {
        // IPs locales
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168')) {
            return response()->json(['error' => 'IP local — sin ubicación disponible']);
        }

        try {
            $response = Http::get("http://ip-api.com/json/{$ip}", [
                'lang' => 'es',
                'fields' => 'status,message,continent,country,regionName,city,zip,lat,lon,timezone,isp,org',
            ]);

            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al consultar la ubicación']);
        }
    }

    public function destroy($id)
    {
        $log = $this->logsForCurrentStore()->find($id);

        if (empty($log)) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado.'], 404);
        }

        $log->delete();

        return response()->json(['success' => true, 'message' => 'Registro eliminado correctamente.']);
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return response()->json(['success' => false, 'message' => 'No se recibieron registros para eliminar.'], 422);
        }

        $query = $this->logsForCurrentStore()->whereIn('id', $ids);
        if ((clone $query)->count() !== count(array_unique($ids))) {
            throw new AccessDeniedHttpException('Uno o más registros no pertenecen a la tienda activa.');
        }

        $deleted = $query->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} registro(s) eliminado(s) correctamente.",
        ]);
    }

    /**
     * Los intentos de inicio de sesión son globales por naturaleza, porque
     * ocurren antes de elegir una tienda. Para el panel de una tienda solo
     * exponemos intentos asociados a usuarios que pertenecen a ella. Los
     * intentos de correos desconocidos no se muestran a ningún tenant.
     */
    private function logsForCurrentStore(): Builder
    {
        $storeId = $this->requireCurrentStoreId();
        $users = User::whereHas('stores', fn ($query) => $query->whereKey($storeId))
            ->get(['users.id', 'users.email']);

        return LoginLog::query()->where(function (Builder $query) use ($users) {
            $query->whereIn('user_id', $users->pluck('id'))
                ->orWhere(function (Builder $emailQuery) use ($users) {
                    $emailQuery->whereNull('user_id')->whereIn('email', $users->pluck('email'));
                });
        });
    }
}
