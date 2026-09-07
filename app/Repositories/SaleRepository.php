<?php

namespace App\Repositories;

use App\Exceptions\InsufficientStockException;
use App\Mail\MailSender;
use App\Models\Customer;
use App\Models\MailTemplate;
use App\Models\ManageStock;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesPayment;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Services\SalePaymentAllocator;
use App\Services\InventoryCostSnapshotService;
use App\Services\AccountsReceivableService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SaleRepository
 */
class SaleRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'date',
        'grand_total',
        'paid_amount',
        'created_at',
        'reference_code'
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'date',
        'grand_total',
        'paid_amount',
        'created_at',
        'reference_code'
    ];

    /**
     * Return searchable fields
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model(): string
    {
        return Sale::class;
    }

    public function storeSale($input): Sale
    {
        $clientUuid = $input['client_uuid'] ?? null;
        $input['created_offline'] = (bool) ($clientUuid && ! empty($input['offline_created_at']));
        $offlineExpectedTotal = $input['created_offline']
            ? round((float) ($input['grand_total'] ?? 0), 2)
            : null;
        if ($clientUuid) {
            $existingSale = Sale::where('client_uuid', $clientUuid)
                ->where('user_id', Auth::id())
                ->first();
            if ($existingSale) {
                return $existingSale;
            }
        }

        try {
            DB::beginTransaction();

            $input['date'] = $input['date'] ?? Carbon::now('America/Guayaquil')->toDateString();
            $input['is_sale_created'] = $input['is_sale_created'] ?? false;
            $QuotationId = $input['quotation_id'] ?? false;
            $saleInputArray = Arr::only($input, [
                'client_uuid',
                'customer_id',
                'warehouse_id',
                'tax_rate',
                'tax_amount',
                'discount',
                'shipping',
                'grand_total',
                'received_amount',
                'paid_amount',
                'payment_type',
                'note',
                'date',
                'offline_created_at',
                'created_offline',
                'status',
                'payment_status',
                'payment_due_date',
                'payment_terms_days',
                'collection_note',
            ]);

            if (in_array((int) ($saleInputArray['payment_status'] ?? Sale::PAID), [Sale::UNPAID, Sale::PARTIAL_PAID], true)
                && empty($saleInputArray['payment_due_date'])) {
                $terms = (int) (Customer::whereKey($saleInputArray['customer_id'])
                    ->value('default_payment_terms_days') ?? 0);
                $saleInputArray['payment_terms_days'] = $terms;
                $saleInputArray['payment_due_date'] = Carbon::parse($saleInputArray['date'])->addDays($terms)->toDateString();
            }

            $saleInputArray['user_id'] = Auth::id();
            /** @var Sale $sale */
            $sale = Sale::create($saleInputArray);
            if ($input['is_sale_created'] && $QuotationId) {
                $quotation = Quotation::find($QuotationId);
                $quotation->update([
                    'is_sale_created' => true,
                ]);
            }
            $sale = $this->storeSaleItems($sale, $input);

            $customer = Customer::findOrFail($sale->customer_id);
            app(AccountsReceivableService::class)->assertCreditAvailable(
                $customer,
                (float) $sale->dueAmount($sale->id),
                $sale->id
            );

            // El precio del catálogo local es una fotografía. Si cambió en
            // el servidor durante el corte, no registramos silenciosamente
            // un cobro distinto al que vio el cliente: la cola lo mostrará
            // como "Requiere revisión" para decisión del cajero.
            if ($offlineExpectedTotal !== null
                && abs(round((float) $sale->grand_total, 2) - $offlineExpectedTotal) > 0.01) {
                throw new UnprocessableEntityHttpException(
                    'Los precios o impuestos cambiaron desde que se registró la venta offline.'
                );
            }
            $reference_code = getSettingValue('sale_code') . '_111' . $sale->id;
            $this->generateBarcode($reference_code);
            $sale['barcode_image_url'] = Storage::url('sales/barcode-' . $reference_code . '.png');

            foreach ($input['sale_items'] as $saleItem) {
                $productModel = Product::with('kitItems')->whereId($saleItem['product_id'])->first();
                $baseUnitsQuantity = $productModel
                    ? $productModel->convertToBaseUnits($saleItem['quantity'], $saleItem['product_presentation_id'] ?? null)
                    : $saleItem['quantity'];

                // Si el producto es un kit (ej. "Pack Cerveza + Michelada"),
                // no tiene stock propio -- se descuenta cada componente
                // real según su receta. Para un producto normal esto
                // devuelve una sola entrada igual a sí mismo, mismo
                // comportamiento de siempre.
                $movimientos = $productModel
                    ? $productModel->resolverMovimientoStock($baseUnitsQuantity)
                    : [['product_id' => $saleItem['product_id'], 'quantity' => $baseUnitsQuantity]];

                foreach ($movimientos as $movimiento) {
                    // lockForUpdate() dentro de la transacción -- evita que dos
                    // ventas concurrentes del mismo producto lean el mismo
                    // stock antes de que ninguna haga commit (lost update /
                    // sobreventa).
                    $product = ManageStock::whereWarehouseId($input['warehouse_id'])->whereProductId($movimiento['product_id'])->lockForUpdate()->first();
                    if ($product && $product->quantity >= $movimiento['quantity']) {
                        $totalQuantity = $product->quantity - $movimiento['quantity'];
                        $product->update([
                            'quantity' => $totalQuantity,
                        ]);
                    } else {
                        $stockProduct = Product::find($movimiento['product_id']);
                        $required = (float) $movimiento['quantity'];
                        $available = (float) ($product?->quantity ?? 0);
                        $conflict = [
                            'stock_product_id' => (int) $movimiento['product_id'],
                            'stock_product_name' => $stockProduct?->name ?? $productModel?->name ?? 'Producto',
                            'stock_product_code' => $stockProduct?->code,
                            'requested_quantity' => round($required, 4),
                            'available_quantity' => round($available, 4),
                            'shortage_quantity' => round(max(0, $required - $available), 4),
                            'unit_name' => $stockProduct?->getProductUnitName(),
                            'is_kit_component' => (bool) ($productModel?->is_kit),
                            'sources' => [],
                        ];
                        throw new InsufficientStockException([$conflict]);
                    }
                }

                if ($productModel && $productModel->is_kit) {
                    $productModel->syncKitStock($input['warehouse_id']);
                }
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // Dos reintentos pueden llegar casi al mismo tiempo. El índice
            // único resuelve la carrera y este lookup convierte el segundo
            // intento en una respuesta idempotente, no en otra venta.
            if ($clientUuid) {
                $existingSale = Sale::where('client_uuid', $clientUuid)
                    ->where('user_id', Auth::id())
                    ->first();
                if ($existingSale) {
                    return $existingSale;
                }
            }
            if ($e instanceof InsufficientStockException) {
                throw $e;
            }
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        // Correo/SMS de confirmación -- va DESPUÉS del commit, a
        // propósito. Antes esto vivía dentro de la misma transacción de
        // la venta: si el proveedor de correo/SMS fallaba o tardaba, una
        // venta perfectamente válida (stock y pago ya correctos) se
        // revertía entera solo porque la notificación falló, y además la
        // transacción se mantenía abierta reteniendo los locks de stock
        // mientras esperaba una llamada HTTP externa. Un fallo acá ya no
        // puede deshacer la venta -- solo se loguea.
        try {
            $this->enviarNotificacionesVenta($sale);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "storeSale: la venta {$sale->id} se guardó correctamente pero falló el envío de la notificación: " . $e->getMessage()
            );
        }

        return $sale;
    }

    /**
     * Correo y SMS de confirmación de venta -- extraído de storeSale()
     * para poder llamarlo DESPUÉS del commit de la transacción.
     */
    private function enviarNotificacionesVenta(Sale $sale): void
    {
        $storeId = $sale->warehouse?->store_id ?? currentStoreId();
        $mailTemplate = MailTemplate::effectiveForStore($storeId, MailTemplate::MAIL_TYPE_SALE);
        $smsTemplate = SmsTemplate::effectiveForStore($storeId, SmsTemplate::SMS_TYPE_SALE);

        $subject = 'Venta al cliente';

        $customer = Customer::whereId($sale->customer_id)->first();

        $search = [
            '{customer_name}',
            '{sales_id}',
            '{sales_date}',
            '{sales_amount}',
            '{paid_amount}',
            '{due_amount}',
            '{app_name}',
        ];

        $totalPayAmount = SalesPayment::whereSaleId($sale->id)->sum('amount');

        $dueAmount = $sale->grand_total - $totalPayAmount;

        $payAmount = 0;

        if (($dueAmount < 0) || ($sale->payment_status == Sale::PAID)) {
            $dueAmount = 0;
            $payAmount = $sale->grand_total;
        }

        $payAmount = number_format($payAmount, 2);
        $dueAmount = number_format($dueAmount, 2);

        $replace = [
            $customer->name,
            $sale->reference_code,
            $sale->date,
            number_format($sale->grand_total, 2),
            $payAmount,
            $dueAmount,
            getSettingValue('company_name'),
        ];

        if (!empty($mailTemplate) && $mailTemplate->status == MailTemplate::ACTIVE) {
            $data['data'] = str_replace($search, $replace, $mailTemplate->content);

            Mail::to($customer->email)
                ->send(new MailSender('emails.mail-sender', $subject, $data));
        }

        if (!empty($smsTemplate) && $smsTemplate->status == SmsTemplate::ACTIVE) {
            $message = str_replace($search, $replace, $smsTemplate->content);

            $client = new \GuzzleHttp\Client();

            $url = SmsSetting::effectiveValue($storeId, 'url');
            // $token = SmsSetting::where('key', 'token')->value('value');
            //            $url = "https://xrjv8e.api.infobip.com/sms/2/text/advanced";

            $data = SmsSetting::effectiveValue($storeId, 'payload');

            $data = preg_replace('/\s/', '', $data);

            $data = json_decode($data, true);

            $toKey = SmsSetting::effectiveValue($storeId, 'mobile_key');
            $number = $customer->phone;

            $messageKey = SmsSetting::effectiveValue($storeId, 'message_key');

            $data = replaceArrayValue($data, $toKey, $number);
            $data = replaceArrayValue($data, $messageKey, $message);

            $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'form_params' => [$data],
            ]);
        }
    }

    /**
     * @return mixed
     */
    public function calculationSaleItems($saleItem, $warehouseId = null)
    {
        $validator = Validator::make($saleItem, SaleItem::$rules);
        if ($validator->fails()) {
            throw new UnprocessableEntityHttpException($validator->errors()->first());
        }

        // Presentaciones (Unidad/Six Pack/Caja): la 'quantity' que llega del
        // frontend es la cantidad de la PRESENTACIÓN elegida (ej: 2 cajas),
        // no de unidades base de inventario. Se usa tal cual para el cálculo
        // monetario (junto al precio propio de esa presentación). Al final de
        // este método se convierte 'quantity' a unidades base, para que el
        // resto del sistema (stock, devoluciones, transferencias, reportes)
        // siga funcionando exactamente igual que hoy, sin cambios.
        $presentationQuantity = $saleItem['quantity'];
        $equivalence = 1;
        $presentation = null;

        $product = Product::whereId($saleItem['product_id'])->first();
        if (!$product) {
            throw new UnprocessableEntityHttpException('Producto no encontrado.');
        }

        if (!empty($saleItem['product_presentation_id'])) {
            if (!$product->manage_presentations) {
                throw new UnprocessableEntityHttpException('El producto no admite la presentación seleccionada.');
            }
            $presentation = $product->presentations()->whereId($saleItem['product_presentation_id'])->first();
            if (!$presentation) {
                throw new UnprocessableEntityHttpException('Presentación inválida para el producto ' . $product->name);
            }
            $equivalence = $presentation->equivalence;
        }

        $saleItem['presentation_quantity'] = $presentationQuantity;
        $saleItem['presentation_equivalence'] = $equivalence;

        // Precio autoritativo: NUNCA se confía en el product_price que llega
        // del cliente -- se recalcula siempre desde el precio real del
        // producto o de la presentación en BD (con override por sucursal si
        // existe), para que no se pueda vender a un precio manipulado
        // enviando un product_price distinto en el request.
        $saleItem['product_price'] = $presentation
            ? $presentation->priceForWarehouse($warehouseId)
            : $product->priceForWarehouse($warehouseId);

        //discount calculation
        $perItemDiscountAmount = 0;
        $saleItem['net_unit_price'] = $saleItem['product_price'];
        if ($saleItem['discount_type'] == Sale::PERCENTAGE) {
            if ($saleItem['discount_value'] <= 100 && $saleItem['discount_value'] >= 0) {
                $saleItem['discount_amount'] = ($saleItem['discount_value'] * $saleItem['product_price'] / 100) * $saleItem['quantity'];
                $perItemDiscountAmount = $saleItem['discount_amount'] / $saleItem['quantity'];
                $saleItem['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException('Please enter discount value between 0 to 100.');
            }
        } elseif ($saleItem['discount_type'] == Sale::FIXED) {
            if ($saleItem['discount_value'] <= $saleItem['product_price'] && $saleItem['discount_value'] >= 0) {
                $saleItem['discount_amount'] = $saleItem['discount_value'] * $saleItem['quantity'];
                $perItemDiscountAmount = $saleItem['discount_amount'] / $saleItem['quantity'];
                $saleItem['net_unit_price'] -= $perItemDiscountAmount;
            } else {
                throw new UnprocessableEntityHttpException("Please enter  discount's value between product's price.");
            }
        }

        //tax calculation
        $perItemTaxAmount = 0;
        if ($saleItem['tax_value'] <= 100 && $saleItem['tax_value'] >= 0) {
            if ($saleItem['tax_type'] == Sale::EXCLUSIVE) {
                $saleItem['tax_amount'] = (($saleItem['net_unit_price'] * $saleItem['tax_value']) / 100) * $saleItem['quantity'];
                $perItemTaxAmount = $saleItem['tax_amount'] / $saleItem['quantity'];
            } elseif ($saleItem['tax_type'] == Sale::INCLUSIVE) {
                $saleItem['tax_amount'] = ($saleItem['net_unit_price'] * $saleItem['tax_value']) / (100 + $saleItem['tax_value']) * $saleItem['quantity'];
                $perItemTaxAmount = $saleItem['tax_amount'] / $saleItem['quantity'];
                $saleItem['net_unit_price'] -= $perItemTaxAmount;
            }
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100 ');
        }
        $saleItem['sub_total'] = ($saleItem['net_unit_price'] + $perItemTaxAmount) * $saleItem['quantity'];

        // A partir de aquí 'quantity' pasa a representar unidades base de
        // inventario (ej: 2 cajas * 24 = 48), para que el descuento de stock
        // y todo lo demás que consume 'quantity' funcione sin cambios.
        $saleItem['quantity'] = $presentationQuantity * $equivalence;
        $saleItem = array_merge($saleItem, app(InventoryCostSnapshotService::class)->forSale(
            $product->loadMissing(['kitItems.component', 'kitItems.presentation.product']),
            (float) $saleItem['quantity'],
            $presentation,
            (float) $presentationQuantity
        ));

        return $saleItem;
    }

    /**
     * @return mixed
     */
    public function storeSaleItems($sale, $input)
    {
        foreach ($input['sale_items'] as $saleItem) {
            $product = Product::whereId($saleItem['product_id'])->first();

            $equivalence = ! empty($saleItem['product_presentation_id'])
                ? ($saleItem['presentation_equivalence'] ?? 1)
                : 1;
            $quantityInBaseUnits = $saleItem['quantity'] * $equivalence;

            if (!empty($product) && isset($product->quantity_limit) && $quantityInBaseUnits > $product->quantity_limit) {
                throw new UnprocessableEntityHttpException('Please enter less than ' . $product->quantity_limit . ' quantity of ' . $product->name . ' product.');
            }
            $item = $this->calculationSaleItems($saleItem, $input['warehouse_id'] ?? null);
            $saleItem = new SaleItem($item);
            $sale->saleItems()->save($saleItem);
        }

        $subTotalAmount = $sale->saleItems()->sum('sub_total');

        if ($input['discount'] <= $subTotalAmount) {
            $input['grand_total'] = $subTotalAmount - $input['discount'];
        } else {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        if ($input['tax_rate'] <= 100 && $input['tax_rate'] >= 0) {
            // $subTotalAmount (y por lo tanto grand_total en este punto) ya
            // incluye el impuesto de CADA línea (SaleItem.tax_amount) --
            // aplicar tax_rate directamente sobre eso cobra impuesto sobre
            // el impuesto ya cobrado por línea. Se resta lo ya gravado por
            // línea antes de aplicar el impuesto de orden, para que ambos
            // mecanismos no se acumulen entre sí (si las líneas no tienen
            // impuesto propio, esto no cambia nada: sigue funcionando
            // igual que antes).
            $impuestoYaAplicadoPorLinea = $sale->saleItems()->sum('tax_amount');
            $baseParaImpuestoDeOrden = $input['grand_total'] - $impuestoYaAplicadoPorLinea;
            $input['tax_amount'] = $baseParaImpuestoDeOrden * $input['tax_rate'] / 100;
        } else {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        $input['grand_total'] += $input['tax_amount'];
        if ($input['shipping'] <= $input['grand_total'] && $input['shipping'] >= 0) {
            $input['grand_total'] += $input['shipping'];
        } else {
            throw new UnprocessableEntityHttpException('Shipping amount should not be greater than total.');
        }

        if (!empty($input['payments']) && is_array($input['payments'])) {
            // Flujo nuevo (POS): el cliente puede pagar con varias formas de
            // pago en la misma venta (ej. $20 efectivo + $10 transferencia).
            // El estado de pago NUNCA se confía del frontend -- se calcula
            // acá comparando lo realmente pagado contra el grand_total ya
            // calculado arriba, para que no se pueda marcar "Pagado" sin
            // haber cubierto el total.
            //
            // Si el cliente entrega de más (ej. $35 en efectivo para una
            // venta de $30), esos $5 son VUELTO, no ingreso -- no deben
            // contar como "pagado". El vuelto solo tiene sentido devolverlo
            // en efectivo (no se puede "devolver vuelto" por transferencia),
            // así que se descuenta únicamente de las filas en efectivo.
            $totalPaid = 0;
            $firstPaymentType = null;

            $allocatedPayments = app(SalePaymentAllocator::class)->allocate(
                $input['payments'],
                (float) $input['grand_total']
            );
            foreach ($allocatedPayments as $payment) {
                SalesPayment::create([
                    'sale_id' => $sale->id,
                    'payment_date' => Carbon::now('America/Guayaquil')->toDateString(),
                    'payment_type' => $payment['payment_type'],
                    'amount' => $payment['amount'],
                    'received_amount' => $payment['received_amount'],
                ]);
                $totalPaid += $payment['amount'];
                if ($firstPaymentType === null) {
                    $firstPaymentType = $payment['payment_type'];
                }
            }

            $input['paid_amount'] = $totalPaid;
            $input['payment_type'] = $firstPaymentType ?? SalesPayment::CASH;

            if ($totalPaid >= $input['grand_total']) {
                $input['payment_status'] = Sale::PAID;
            } elseif ($totalPaid > 0) {
                $input['payment_status'] = Sale::PARTIAL_PAID;
            } else {
                $input['payment_status'] = Sale::UNPAID;
            }
        } elseif ($input['payment_status'] == Sale::PAID) {
            // Flujo anterior (formulario admin): una sola forma de pago, sin cambios.
            $input['paid_amount'] = $input['grand_total'];
            SalesPayment::create([
                'sale_id' => $sale->id,
                'payment_date' => Carbon::now('America/Guayaquil')->toDateString(), // ✅
                'payment_type' => $input['payment_type'],
                'amount' => $input['paid_amount'],
                'received_amount' => $input['paid_amount'],
            ]);
        } elseif ($input['payment_status'] == Sale::PARTIAL_PAID) {
            $partialAmount = round((float) ($input['paid_amount'] ?? 0), 2);
            if ($partialAmount <= 0 || $partialAmount >= round((float) $input['grand_total'], 2)) {
                throw new UnprocessableEntityHttpException('El abono inicial debe ser mayor a cero y menor al total de la venta.');
            }
            $input['paid_amount'] = $partialAmount;
            SalesPayment::create([
                'sale_id' => $sale->id,
                'payment_date' => Carbon::now('America/Guayaquil')->toDateString(),
                'payment_type' => $input['payment_type'],
                'amount' => $partialAmount,
                'received_amount' => $partialAmount,
            ]);
        } elseif ($input['payment_status'] == Sale::UNPAID) {
            $input['paid_amount'] = 0;
        }

        $input['reference_code'] = getSettingValue('sale_code') . '_111' . $sale->id;
        $sale->update($input);

        return $sale;
    }

    /**
     * @return mixed
     */
    public function updateSale($input, $id)
    {
        try {
            DB::beginTransaction();
            $sale = Sale::findOrFail($id);
            $saleItemIds = SaleItem::whereSaleId($id)->pluck('id')->toArray();
            $saleItmOldIds = [];
            foreach ($input['sale_items'] as $key => $saleItem) {
                $product = Product::whereId($saleItem['product_id'])->first();

                // quantity_limit está definido en unidades base; si la línea
                // es una presentación (ej. Six Pack), 'quantity' que llega
                // aquí todavía es la cantidad de presentaciones, así que hay
                // que convertir antes de comparar contra el límite.
                $equivalence = ! empty($saleItem['product_presentation_id'])
                    ? ($saleItem['presentation_equivalence'] ?? 1)
                    : 1;
                $quantityInBaseUnits = $saleItem['quantity'] * $equivalence;

                if (!empty($product) && isset($product->quantity_limit) && $quantityInBaseUnits > $product->quantity_limit) {
                    throw new UnprocessableEntityHttpException('Please enter less than ' . $product->quantity_limit . ' quantity of ' . $product->name . ' product.');
                }

                //get different ids & update
                $saleItmOldIds[$key] = $saleItem['sale_item_id'];
                $saleItemArray = Arr::only($saleItem, [
                    'sale_item_id',
                    'product_id',
                    'product_presentation_id',
                    'presentation_quantity',
                    'presentation_equivalence',
                    'product_price',
                    'net_unit_price',
                    'tax_type',
                    'tax_value',
                    'tax_amount',
                    'discount_type',
                    'discount_value',
                    'discount_amount',
                    'sale_unit',
                    'quantity',
                    'sub_total',
                ]);
                $this->updateItem($saleItemArray, $input['warehouse_id']);
                //create new product items
                if (is_null($saleItem['sale_item_id'])) {
                    $saleItem = $this->calculationSaleItems($saleItem, $input['warehouse_id']);
                    $saleItemArray = Arr::only($saleItem, [
                        'product_id',
                        'product_presentation_id',
                        'presentation_quantity',
                        'presentation_equivalence',
                        'product_price',
                        'net_unit_price',
                        'tax_type',
                        'tax_value',
                        'tax_amount',
                        'discount_type',
                        'discount_value',
                        'discount_amount',
                        'sale_unit',
                        'quantity',
                        'sub_total',
                    ]);
                    $sale->saleItems()->create($saleItemArray);

                    $productModelNuevo = Product::with('kitItems')->find($saleItem['product_id']);
                    $movimientosNuevo = $productModelNuevo
                        ? $productModelNuevo->resolverMovimientoStock($saleItem['quantity'])
                        : [['product_id' => $saleItem['product_id'], 'quantity' => $saleItem['quantity']]];

                    foreach ($movimientosNuevo as $movimiento) {
                        $product = ManageStock::whereWarehouseId($input['warehouse_id'])->whereProductId($movimiento['product_id'])->lockForUpdate()->first();
                        if ($product) {
                            if ($product->quantity >= $movimiento['quantity']) {
                                $product->update([
                                    'quantity' => $product->quantity - $movimiento['quantity'],
                                ]);
                            } else {
                                throw new UnprocessableEntityHttpException('Quantity must be less than Available quantity.');
                            }
                        }
                    }

                    if ($productModelNuevo && $productModelNuevo->is_kit) {
                        $productModelNuevo->syncKitStock($input['warehouse_id']);
                    }
                }
            }
            $removeItemIds = array_diff($saleItemIds, $saleItmOldIds);
            //delete remove product
            if (!empty(array_values($removeItemIds))) {
                foreach ($removeItemIds as $removeItemId) {
                    // remove quantity manage storage
                    $oldProduct = SaleItem::whereId($removeItemId)->first();

                    $productModelViejo = Product::with('kitItems')->find($oldProduct->product_id);
                    $movimientosViejo = $productModelViejo
                        ? $productModelViejo->resolverMovimientoStock($oldProduct->quantity)
                        : [['product_id' => $oldProduct->product_id, 'quantity' => $oldProduct->quantity]];

                    foreach ($movimientosViejo as $movimiento) {
                        $productQuantity = ManageStock::whereWarehouseId($input['warehouse_id'])->whereProductId($movimiento['product_id'])->lockForUpdate()->first();
                        if ($productQuantity) {
                            $productQuantity->update([
                                'quantity' => $productQuantity->quantity + $movimiento['quantity'],
                            ]);
                        } else {
                            ManageStock::create([
                                'warehouse_id' => $input['warehouse_id'],
                                'product_id' => $movimiento['product_id'],
                                'quantity' => $movimiento['quantity'],
                            ]);
                        }
                    }

                    if ($productModelViejo && $productModelViejo->is_kit) {
                        $productModelViejo->syncKitStock($input['warehouse_id']);
                    }
                }
                SaleItem::whereIn('id', array_values($removeItemIds))->delete();
            }
            $this->generateBarcode($sale->reference_code);
            $sale['barcode_image_url'] = Storage::url('sales/barcode-' . $sale->reference_code . '.png');
            $sale = $this->updateSaleCalculation($input, $id);
            DB::commit();

            return $sale;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function updateItem($saleItem, $warehouseId): bool
    {
        try {
            $saleItem = $this->calculationSaleItems($saleItem, $warehouseId);
            $item = SaleItem::whereId($saleItem['sale_item_id']);
            $oldItem = SaleItem::whereId($saleItem['sale_item_id'])->first();

            if ($oldItem && $oldItem->quantity != $saleItem['quantity']) {
                // Delta con signo: positivo = se vendió más que antes (hay
                // que restar más stock), negativo = se vendió menos (hay
                // que devolver la diferencia). Se expande a componentes
                // reales si el producto es un kit -- ver
                // Product::resolverMovimientoStock().
                $deltaVendido = $saleItem['quantity'] - $oldItem->quantity;

                $productModel = Product::with('kitItems')->find($saleItem['product_id']);
                $movimientos = $productModel
                    ? $productModel->resolverMovimientoStock($deltaVendido)
                    : [['product_id' => $saleItem['product_id'], 'quantity' => $deltaVendido]];

                foreach ($movimientos as $movimiento) {
                    $product = ManageStock::whereWarehouseId($warehouseId)->whereProductId($movimiento['product_id'])->lockForUpdate()->first();

                    if ($movimiento['quantity'] > 0) {
                        // $product puede ser null si nunca se cargó inventario
                        // inicial para este producto en esta bodega -- antes esto
                        // producía un error fatal (Call to a member function on
                        // null) en vez de un mensaje de negocio claro.
                        if (!$product || $product->quantity < $movimiento['quantity']) {
                            throw new UnprocessableEntityHttpException('Quantity must be less than Available quantity.');
                        }
                        $product->update(['quantity' => $product->quantity - $movimiento['quantity']]);
                    } elseif ($movimiento['quantity'] < 0) {
                        if ($product) {
                            // restar un negativo = sumar la diferencia devuelta
                            $product->update(['quantity' => $product->quantity - $movimiento['quantity']]);
                        } else {
                            ManageStock::create([
                                'warehouse_id' => $warehouseId,
                                'product_id' => $movimiento['product_id'],
                                'quantity' => -$movimiento['quantity'],
                            ]);
                        }
                    }
                }

                if ($productModel && $productModel->is_kit) {
                    $productModel->syncKitStock($warehouseId);
                }
            }
            unset($saleItem['sale_item_id']);
            $item->update($saleItem);

            return true;
        } catch (Exception $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function updateSaleCalculation($input, $id)
    {
        $sale = Sale::findOrFail($id);
        $subTotalAmount = $sale->saleItems()->sum('sub_total');

        if ($input['discount'] > $subTotalAmount || $input['discount'] < 0) {
            throw new UnprocessableEntityHttpException('Discount amount should not be greater than total.');
        }
        $input['grand_total'] = $subTotalAmount - $input['discount'];
        if ($input['tax_rate'] > 100 || $input['tax_rate'] < 0) {
            throw new UnprocessableEntityHttpException('Please enter tax value between 0 to 100.');
        }
        // Mismo criterio que storeSaleItems() -- no cobrar el impuesto de
        // orden sobre una base que ya incluye el impuesto de cada línea.
        $impuestoYaAplicadoPorLinea = $sale->saleItems()->sum('tax_amount');
        $baseParaImpuestoDeOrden = $input['grand_total'] - $impuestoYaAplicadoPorLinea;
        $input['tax_amount'] = $baseParaImpuestoDeOrden * $input['tax_rate'] / 100;

        $input['grand_total'] += $input['tax_amount'];

        if ($input['shipping'] > $input['grand_total'] || $input['shipping'] < 0) {
            throw new UnprocessableEntityHttpException('Shipping amount should not be greater than total.');
        }

        $input['grand_total'] += $input['shipping'];

        $sale->first();
        $saleExistGrandTotal = $sale->grand_total;

        if ($input['payment_status'] == Sale::PAID && $input['grand_total'] > $saleExistGrandTotal) {
            $input['payment_status'] = Sale::PARTIAL_PAID;
        }

        $saleInputArray = Arr::only($input, [
            'customer_id',
            'warehouse_id',
            'tax_rate',
            'tax_amount',
            'discount',
            'shipping',
            'grand_total',
            'received_amount',
            'paid_amount',
            'payment_type',
            'note',
            'date',
            'status',
            'payment_status',
        ]);
        $sale->update($saleInputArray);

        return $sale;
    }

    /**
     * @param $input
     */
    public function generateBarcode($code): bool
    {
        $generator = new BarcodeGeneratorPNG();
        $barcodeType = $generator::TYPE_CODE_128;

        Storage::disk(config('app.media_disc'))->put(
            'sales/barcode-' . $code . '.png',
            $generator->getBarcode(Sale::CODE128, $barcodeType, 4, 70)
        );

        return true;
    }
}
