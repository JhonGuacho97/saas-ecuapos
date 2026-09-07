<?php

namespace App\Imports;

use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\MainProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ProductImport implements ToCollection, WithChunkReading, WithStartRow, WithValidation
{
    public function collection(Collection $rows): JsonResponse
    {
        $collection = $rows->toArray();
        ini_set('max_execution_time', 36000000);
        foreach ($collection as $key => $row) {
            try {
                DB::beginTransaction();

                $taxType = null;

                $storeId = requireCurrentStoreId();

                // Sin whereStoreId acá, un producto demo creado una vez en
                // CUALQUIER tienda bloqueaba para siempre volver a importar
                // esa misma fila demo en otra tienda distinta -- a
                // diferencia de ProductCategory/Brand más abajo, que sí
                // están scopeados por tienda.
                $productName = Product::whereName($row[0])->whereStoreId($storeId)->exists();
                if ($productName) {
                    throw new UnprocessableEntityHttpException('Product Name ' . $row[0] . ' is already exist.');
                }
                $productCode = Product::where('code', $row[1])->whereStoreId($storeId)->exists();
                if ($productCode) {
                    throw new UnprocessableEntityHttpException('Product Code ' . $row[1] . ' is already exist.');
                }

                $productCategory = ProductCategory::whereName($row[2])->whereStoreId($storeId)->first();
                $brand = Brand::whereName($row[3])->whereStoreId($storeId)->first();

                $baseUnit = BaseUnit::whereName(strtolower($row[7]))->first();

                if ($baseUnit) {
                    $productUnitId = $baseUnit->id;
                } else {
                    throw new UnprocessableEntityHttpException('Product unit ' . $row[7] . ' is not found.');
                }

                //                if (strtolower($row[7]) == 'piece') {
                //                    $productUnitId = 1;
                //                } elseif (strtolower($row[7]) == 'meter') {
                //                    $productUnitId = 2;
                //                } elseif (strtolower($row[7]) == 'kilogram') {
                //                    $productUnitId = 3;
                //                } else {
                //                    throw new UnprocessableEntityHttpException('Product unit '.$row[7].' is not found.');
                //                }

                // La tabla `units` no trae ningún dato por defecto en una
                // instalación nueva (a diferencia de `base_units`, que sí
                // se siembra) -- sin esto, CUALQUIER importación de
                // productos fallaba siempre en la primera fila con "Sale
                // unit ... is not found.", igual que ya se hace más abajo
                // con categoría/marca: si la unidad de venta/compra no
                // existe todavía para esta unidad base, se crea.
                $saleUnit = Unit::whereName(strtolower($row[8]))->whereBaseUnit($productUnitId)->first();
                if (!$saleUnit) {
                    $saleUnit = Unit::create([
                        'name' => strtolower($row[8]),
                        'short_name' => $row[8],
                        'base_unit' => $productUnitId,
                    ]);
                }
                $purchaseUnit = Unit::whereName(strtolower($row[9]))->whereBaseUnit($productUnitId)->first();
                if (!$purchaseUnit) {
                    $purchaseUnit = Unit::create([
                        'name' => strtolower($row[9]),
                        'short_name' => $row[9],
                        'base_unit' => $productUnitId,
                    ]);
                }

                if ($productCategory) {
                    $productCategoryId = $productCategory->id;
                } else {
                    $productCategory = ProductCategory::create(['name' => $row[2], 'store_id' => $storeId]);
                    $productCategoryId = $productCategory->id;
                }

                if ($brand) {
                    $brandId = $brand->id;
                } else {
                    $brand = Brand::create(['name' => $row[3], 'store_id' => $storeId]);
                    $brandId = $brand->id;
                }

                if ($row[4] == 'CODE128') {
                    $barcodeSymbol = 1;
                } elseif ($row[4] == 'CODE39') {
                    $barcodeSymbol = 2;
                } else {
                    throw new UnprocessableEntityHttpException('Product barcode symbol ' . $row[4] . ' is not found.');
                }

                if (strtolower($row[12]) == 'exclusive') {
                    $taxType = 1;
                } elseif (strtolower($row[12]) == 'inclusive') {
                    $taxType = 2;
                } else {
                    throw new UnprocessableEntityHttpException('Tax type ' . $row[12] . ' is not found.');
                }

                $mainProduct = MainProduct::create([
                    'name' => $row[0],
                    'code' => (string) $row[1],
                    'product_unit' => $productUnitId,
                    'product_type' => MainProduct::SINGLE_PRODUCT,
                    'store_id' => $storeId,
                ]);

                $productData = [
                    'name' => $row[0],
                    'code' => (string) $row[1],
                    'product_code' => (string) $row[1],
                    'product_category_id' => $productCategoryId,
                    'brand_id' => $brandId,
                    'barcode_symbol' => $barcodeSymbol,
                    'product_cost' => $row[5],
                    'product_price' => $row[6],
                    'product_unit' => $productUnitId,
                    'sale_unit' => !empty($saleUnit) ? $saleUnit->id : null,
                    'purchase_unit' => !empty($purchaseUnit) ? $purchaseUnit->id : null,
                    'stock_alert' => isset($row[10]) ? $row[10] : null,
                    'order_tax' => isset($row[11]) ? $row[11] : null,
                    'tax_type' => $taxType,
                    'notes' => isset($row[13]) ? $row[13] : null,
                    'main_product_id' => $mainProduct->id,
                    'store_id' => $storeId,
                ];

                $product = Product::create($productData);

                $reference_code = 'PR_' . $product->id;

                if (!empty($row[14]) && !empty($row[15]) && !empty($row[16])) {
                    $purchaseStock = [
                        'warehouse' => $row[14],
                        'supplier' => $row[15],
                        'quantity' => $row[16],
                    ];
                    $warehouse = Warehouse::active()
                        ->where('store_id', $storeId)
                        ->whereRaw('LOWER(name) = ?', [strtolower($purchaseStock['warehouse'])])
                        ->first();
                    $supplier = Supplier::where('store_id', $storeId)
                        ->whereRaw('LOWER(name) = ?', [strtolower($purchaseStock['supplier'])])->first();

                    if ($warehouse && $supplier) {
                        manageStock($warehouse->id, $product->id, $purchaseStock['quantity']);
                        $status = $row[17];
                        if ($status == 'received') {
                            $status = 1;
                        } elseif ($status == 'ordered') {
                            $status = 3;
                        } else {
                            $status = 2;
                        }

                        // tax_rate/tax_amount/discount/shipping son nullable
                        // en BD y sin default -- el formulario de editar
                        // compra (PurchaseForm.js) siempre asume que vienen
                        // con un número y llama .toFixed() directo sobre
                        // ellos, así que una compra creada sin estos campos
                        // (como esta, generada automáticamente al importar)
                        // rompía la pantalla con "Cannot read properties of
                        // null" apenas se intentaba editar. La compra
                        // "real" (PurchaseRepository::storePurchase) always
                        // los manda, aunque sea en 0.
                        $purchaseInputArray = [
                            'supplier_id' => $supplier->id,
                            'warehouse_id' => $warehouse->id,
                            'date' => Carbon::now('America/Guayaquil')->format('Y-m-d'),
                            'status' => $status,
                            'tax_rate' => 0,
                            'tax_amount' => 0,
                            'discount' => 0,
                            'shipping' => 0,
                        ];

                        $purchase = Purchase::create($purchaseInputArray);

                        $purchaseItemInputs = [
                            'purchase_id' => $purchase->id,
                            'product_id' => $product->id,
                            'product_cost' => $product->product_cost,
                            'net_unit_cost' => $product->product_cost,
                            'tax_type' => $product->tax_type,
                            'tax_value' => $product->order_tax,
                            'tax_amount' => 0,
                            'discount_type' => Purchase::FIXED,
                            'discount_value' => 0,
                            'discount_amount' => 0,
                            'purchase_unit' => $product->purchase_unit,
                            'quantity' => $purchaseStock['quantity'],
                            'sub_total' => $product->product_cost * $purchaseStock['quantity'],
                        ];

                        PurchaseItem::create($purchaseItemInputs);

                        $purchase->update([
                            'reference_code' => getSettingValue('purchase_code') . '_111' . $purchase->id,
                            'grand_total' => $purchaseItemInputs['sub_total'],
                        ]);
                    }
                }

                $barcodeType = null;
                $generator = new BarcodeGeneratorPNG();
                switch ($barcodeSymbol) {
                    case Product::CODE128:
                        $barcodeType = $generator::TYPE_CODE_128;
                        break;
                    case Product::CODE39:
                        $barcodeType = $generator::TYPE_CODE_39;
                        break;
                    case Product::EAN8:
                        $barcodeType = $generator::TYPE_EAN_8;
                        break;
                    case Product::EAN13:
                        $barcodeType = $generator::TYPE_EAN_13;
                        break;
                    case Product::UPC:
                        $barcodeType = $generator::TYPE_UPC_A;
                        break;
                }

                Storage::disk(config('app.media_disc'))->put(
                    'product_barcode/barcode-' . $reference_code . '.png',
                    $generator->getBarcode($row[1], $barcodeType, 4, 70)
                );

                DB::commit();
            } catch (Exception $e) {
                DB::rollBack();

                // Antes el throw estaba comentado: cualquier fila que
                // fallara (unidad no encontrada, nombre/código duplicado,
                // lo que sea) quedaba solo en laravel.log, y el controller
                // igual respondía "imported successfully" sin haber creado
                // nada -- probado end-to-end: 0 productos creados con la
                // respuesta reportando éxito. Se propaga igual que ya hacen
                // CustomerImport/SupplierImport, para que el usuario vea
                // por qué falló en vez de un éxito falso.
                Log::error($e->getMessage());
                throw new UnprocessableEntityHttpException($e->getMessage());
            }

            return response()->json([
                'data' => [
                    'message' => 'Products imported successfully',
                ],
            ]);
        }
    }

    public function chunkSize(): int
    {
        return 1;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function rules(): array
    {
        return [
            '0' => 'required',
            '1' => 'required',
            '2' => 'required',
            '3' => 'required',
            '4' => 'required',
            '5' => 'required|numeric',
            '6' => 'required|numeric',
            '7' => 'required',
            '8' => 'required',
            '9' => 'required',
            '10' => 'nullable|numeric',
            '11' => 'nullable|numeric',
            '12' => 'required',
        ];
    }

    public function customValidationMessages()
    {
        return [
            '0.required' => 'Name field is required',
            '1.required' => 'Code field is required',
            '2.required' => 'Category field is required',
            '3.required' => 'Brand field is required',
            '4.required' => 'Barcode symbol field is required',
            '5.required' => 'Product cost field is required',
            '5.numeric' => 'Product cost field must be a number',
            '6.required' => 'Product price is required',
            '6.numeric' => 'Product price field must be a number',
            '7.required' => 'Product unit field is required',
            '8.required' => 'Sale unit field is required',
            '9.required' => 'Purchase unit field is required',
            '10.nullable|numeric' => 'Stock alert field must be a number',
            '11.nullable|numeric' => 'Order tax percentage must be a number',
            '12.required' => 'Tax type field is required',
        ];
    }
}
