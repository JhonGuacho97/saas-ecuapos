<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CatalogOrder;
use App\Models\CatalogSetting;
use App\Models\ManageStock;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Setting;
use App\Models\Store;
use App\Services\SaaS\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicCatalogController extends Controller
{
    public function show(Store $store): JsonResponse
    {
        $setting = $this->activeSetting($store);
        $warehouseId = (int) $setting->warehouse_id;

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('catalog_visible', true)
            ->with([
                'productCategory:id,name',
                'brand:id,name',
                'mainProduct.media',
                'media',
                'variationProduct.variation',
                'variationProduct.variationType',
                'presentations.variationType',
                'presentations.presentationType',
                'presentations.warehousePrices',
                'warehousePrices',
                'kitItems',
            ])
            ->orderByDesc('catalog_featured')
            ->orderBy('name')
            ->get();

        $stocks = ManageStock::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('quantity', 'product_id');

        $items = $this->groupProducts($products, $stocks, $warehouseId, $setting->show_stock);
        $categoryIds = collect($items)->pluck('category.id')->filter()->unique()->values();
        $categories = collect($items)
            ->pluck('category')
            ->filter(fn ($category) => $category && in_array($category['id'], $categoryIds->all()))
            ->unique('id')
            ->sortBy('name')
            ->values();

        $logo = Setting::where('store_id', $store->id)->where('key', 'logo')->first();

        return response()->json([
            'data' => [
                'store' => [
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'logo' => $logo?->logo ?: asset('images/ecua-pos-logo.png'),
                ],
                'settings' => [
                    'headline' => $setting->headline ?: 'Todo lo que necesitas, en un solo lugar.',
                    'description' => $setting->description,
                    'show_stock' => $setting->show_stock,
                    'allow_pickup' => $setting->allow_pickup,
                    'allow_delivery' => $setting->allow_delivery,
                    'delivery_fee' => (float) $setting->delivery_fee,
                    'minimum_order' => (float) $setting->minimum_order,
                ],
                'categories' => $categories,
                'products' => $items,
            ],
        ]);
    }

    public function storeOrder(Request $request, Store $store): JsonResponse
    {
        $setting = $this->activeSetting($store);
        $account = Auth::guard('catalog_customer')->user();
        if (!$account || !$account->is_active || (int) $account->store_id !== (int) $store->id) {
            throw new AuthenticationException('Debes iniciar sesión para realizar un pedido.', ['catalog_customer']);
        }
        $data = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:30',
            'fulfillment_type' => 'required|in:pickup,delivery',
            'delivery_address' => 'required_if:fulfillment_type,delivery|nullable|string|max:1000',
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer',
            'items.*.presentation_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001|max:99999',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        if ($data['fulfillment_type'] === 'pickup' && !$setting->allow_pickup) {
            throw ValidationException::withMessages(['fulfillment_type' => 'El retiro en tienda no está habilitado.']);
        }
        if ($data['fulfillment_type'] === 'delivery' && !$setting->allow_delivery) {
            throw ValidationException::withMessages(['fulfillment_type' => 'La entrega a domicilio no está habilitada.']);
        }

        $warehouseId = (int) $setting->warehouse_id;
        $customerId = (int) $account->customer_id;
        $order = DB::transaction(function () use ($data, $store, $setting, $warehouseId, $customerId) {
            $resolvedItems = [];
            $subtotal = 0;

            foreach ($data['items'] as $index => $requestedItem) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('catalog_visible', true)
                    ->with(['variationProduct.variationType', 'presentations.variationType', 'presentations.presentationType', 'warehousePrices'])
                    ->find($requestedItem['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages(["items.$index.product_id" => 'Uno de los productos ya no está disponible.']);
                }

                $presentation = null;
                $equivalence = 1;
                $unitPrice = $product->priceForWarehouse($warehouseId);
                if ($product->manage_presentations && empty($requestedItem['presentation_id'])) {
                    throw ValidationException::withMessages(["items.$index.presentation_id" => 'Debes seleccionar una presentación válida.']);
                }
                if (!empty($requestedItem['presentation_id'])) {
                    $presentation = $product->presentations
                        ->firstWhere('id', (int) $requestedItem['presentation_id']);
                    if (!$presentation) {
                        throw ValidationException::withMessages(["items.$index.presentation_id" => 'La presentación seleccionada no es válida.']);
                    }
                    $equivalence = (float) $presentation->equivalence;
                    $unitPrice = $presentation->priceForWarehouse($warehouseId);
                }

                $stock = $product->is_kit
                    ? $product->buildableQuantity($warehouseId)
                    : (float) (ManageStock::where('warehouse_id', $warehouseId)
                        ->where('product_id', $product->id)->value('quantity') ?? 0);
                $requiredUnits = (float) $requestedItem['quantity'] * $equivalence;
                if ($requiredUnits > $stock) {
                    throw ValidationException::withMessages(["items.$index.quantity" => "No hay stock suficiente para {$product->name}."]);
                }

                $lineTotal = round($unitPrice * (float) $requestedItem['quantity'], 4);
                $subtotal += $lineTotal;
                $resolvedItems[] = [
                    'product_id' => $product->id,
                    'product_presentation_id' => $presentation?->id,
                    'product_name' => $product->mainProduct?->name ?: $product->name,
                    'variant_name' => $product->variationProduct?->variationType?->name,
                    'presentation_name' => $presentation?->displayName(),
                    'presentation_equivalence' => $equivalence,
                    'quantity' => (float) $requestedItem['quantity'],
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'notes' => $requestedItem['notes'] ?? null,
                ];
            }

            if ($subtotal < (float) $setting->minimum_order) {
                throw ValidationException::withMessages(['items' => 'El pedido mínimo es $'.number_format($setting->minimum_order, 2).'.']);
            }

            $deliveryFee = $data['fulfillment_type'] === 'delivery' ? (float) $setting->delivery_fee : 0;
            $order = CatalogOrder::create([
                'store_id' => $store->id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'status' => 'pending',
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'fulfillment_type' => $data['fulfillment_type'],
                'delivery_address' => $data['delivery_address'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'grand_total' => $subtotal + $deliveryFee,
            ]);
            $order->update(['reference' => 'PED-'.now()->format('ymd').'-'.str_pad($order->id, 6, '0', STR_PAD_LEFT)]);
            $order->items()->createMany($resolvedItems);
            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => CatalogOrder::PENDING,
                'note' => null,
            ]);

            return $order->load('items');
        });

        $message = $this->whatsappMessage($order, $store);
        $phone = $this->normalizeEcuadorPhone($setting->whatsapp_number);
        $order->update(['whatsapp_opened_at' => now()]);

        return response()->json([
            'message' => 'Pedido registrado correctamente.',
            'data' => [
                'reference' => $order->reference,
                'grand_total' => $order->grand_total,
                'whatsapp_url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message),
            ],
        ], 201);
    }

    private function activeSetting(Store $store): CatalogSetting
    {
        abort_unless($store->is_active, 404);
        abort_unless($store->organization?->is_active, 404);
        app(EntitlementService::class)->assertOrganizationWritable((int) $store->organization_id);
        $setting = $store->catalogSetting;
        abort_unless($setting?->is_enabled && $setting->warehouse_id && $setting->whatsapp_number, 404);
        abort_unless($store->warehouses()->whereKey($setting->warehouse_id)->active()->exists(), 404);

        return $setting;
    }

    private function groupProducts(Collection $products, Collection $stocks, int $warehouseId, bool $showStock): array
    {
        return $products
            ->groupBy(fn (Product $product) => $product->main_product_id ? 'main-'.$product->main_product_id : 'product-'.$product->id)
            ->map(function (Collection $group) use ($stocks, $warehouseId, $showStock) {
                $first = $group->first();
                $options = $group->map(fn (Product $product) => $this->productOption($product, $stocks, $warehouseId, $showStock))->values();
                $images = $first->mainProduct?->image_url ?: $first->image_url;
                $imageUrls = is_array($images) ? ($images['imageUrls'] ?? []) : [];

                return [
                    'id' => $first->main_product_id ? 'main-'.$first->main_product_id : 'product-'.$first->id,
                    'name' => $first->mainProduct?->name ?: $first->name,
                    'description' => $first->catalog_description ?: $first->notes,
                    'featured' => $group->contains('catalog_featured', true),
                    'category' => $first->productCategory ? ['id' => $first->productCategory->id, 'name' => $first->productCategory->name] : null,
                    'brand' => $first->brand?->name,
                    'images' => array_values($imageUrls),
                    'min_price' => (float) $options->min('price'),
                    'max_price' => (float) $options->max('price'),
                    'available' => $options->contains('available', true),
                    'options' => $options,
                ];
            })
            ->values()
            ->all();
    }

    private function productOption(Product $product, Collection $stocks, int $warehouseId, bool $showStock): array
    {
        $stock = $product->is_kit ? $product->buildableQuantity($warehouseId) : (float) ($stocks[$product->id] ?? 0);
        $presentations = $product->manage_presentations
            ? $product->presentations->sortBy('sort')->map(function (ProductPresentation $presentation) use ($warehouseId, $stock, $showStock) {
                $available = (int) floor($stock / $presentation->equivalence);
                return [
                    'id' => $presentation->id,
                    'name' => $presentation->displayName(),
                    'equivalence' => (float) $presentation->equivalence,
                    'price' => $presentation->priceForWarehouse($warehouseId),
                    'available' => $available > 0,
                    'stock' => $showStock ? $available : null,
                    'is_default' => $presentation->is_default,
                ];
            })->values()
            : collect();

        $defaultPresentation = $presentations->firstWhere('is_default', true) ?: $presentations->first();

        return [
            'product_id' => $product->id,
            'name' => $product->variationProduct?->variationType?->name ?: $product->name,
            'code' => $product->code,
            'price' => $presentations->isNotEmpty()
                ? (float) $defaultPresentation['price']
                : $product->priceForWarehouse($warehouseId),
            'available' => $stock > 0,
            'stock' => $showStock ? $stock : null,
            'unit_name' => $product->getProductUnitName()['name'] ?? 'unidad',
            'presentations' => $presentations,
        ];
    }

    private function whatsappMessage(CatalogOrder $order, Store $store): string
    {
        $lines = ["🛒 *Nuevo pedido {$store->name}*", "*{$order->reference}*", '', "Cliente: {$order->customer_name}", "Teléfono: {$order->customer_phone}", 'Entrega: '.($order->fulfillment_type === 'delivery' ? 'Domicilio' : 'Retiro en tienda'), ''];
        foreach ($order->items as $item) {
            $option = collect([$item->variant_name, $item->presentation_name])->filter()->implode(' · ');
            $lines[] = "• {$item->quantity} × {$item->product_name}".($option ? " ({$option})" : '').' — $'.number_format($item->line_total, 2);
            if ($item->notes) $lines[] = "  Nota: {$item->notes}";
        }
        $lines[] = '';
        $lines[] = 'Subtotal: $'.number_format($order->subtotal, 2);
        if ($order->delivery_fee > 0) $lines[] = 'Envío: $'.number_format($order->delivery_fee, 2);
        $lines[] = '*Total: $'.number_format($order->grand_total, 2).'*';
        if ($order->delivery_address) $lines[] = "Dirección: {$order->delivery_address}";
        if ($order->payment_method) $lines[] = "Pago previsto: {$order->payment_method}";
        if ($order->notes) $lines[] = "Observaciones: {$order->notes}";

        return implode("\n", $lines);
    }

    private function normalizeEcuadorPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (Str::startsWith($digits, '0')) return '593'.substr($digits, 1);
        if (strlen($digits) === 9 && Str::startsWith($digits, '9')) return '593'.$digits;
        return $digits;
    }
}
