<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateCustomerRequest;
use App\Http\Requests\AdminUpdateCustomerPasswordRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerCollection;
use App\Http\Resources\CustomerResource;
use App\Imports\CustomerImport;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesPayment;
use App\Repositories\CustomerRepository;
use App\Services\AccountsReceivableService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * Class CustomerAPIController
 */
class CustomerAPIController extends AppBaseController
{
    /** @var CustomerRepository */
    private $customerRepository;

    public function __construct(CustomerRepository $customerRepository, private readonly AccountsReceivableService $receivables)
    {
        $this->customerRepository = $customerRepository;
    }

    public function creditProfile(Customer $customer): JsonResponse
    {
        $this->authorizeStoreOwnership($customer);

        return response()->json(['data' => $this->receivables->customerProfile($customer)]);
    }

    public function index(Request $request): CustomerCollection
    {
        $perPage = getPageSize($request);
        $customersQuery = $this->customerRepository;
        if ($storeId = $this->currentStoreId()) {
            $customersQuery->where('store_id', $storeId);
        }
        $customers = $customersQuery->paginate($perPage);
        $customers->getCollection()->load('account:id,customer_id,is_active');
        CustomerResource::usingWithCollection();

        return new CustomerCollection($customers);
    }

    /**
     * @throws ValidatorException
     */
    public function store(CreateCustomerRequest $request): CustomerResource
    {
        $input = $request->all();
        if (! empty($input['dob'])) {
            $input['dob'] = $input['dob'] ?? date('Y/m/d');
        }
        $input['store_id'] = $input['store_id'] ?? $this->requireCurrentStoreId();
        $customer = $this->customerRepository->create($input);

        return new CustomerResource($customer);
    }

    public function show($id): CustomerResource
    {
        $customer = $this->customerRepository->find($id);
        $this->authorizeStoreOwnership($customer);
        $customer->load('account:id,customer_id,is_active');

        return new CustomerResource($customer);
    }

    public function updatePassword(AdminUpdateCustomerPasswordRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeStoreOwnership($customer);

        if ($customer->es_consumidor_final || !$customer->email) {
            return $this->sendError('Este cliente no puede utilizar una cuenta del catálogo.');
        }

        $email = Str::lower(trim($customer->email));
        $duplicateAccount = CustomerAccount::query()
            ->where('store_id', $customer->store_id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('customer_id', '<>', $customer->id)
            ->exists();

        if ($duplicateAccount) {
            return $this->sendError('El correo del cliente ya está vinculado a otra cuenta del catálogo.');
        }

        $created = false;
        DB::transaction(function () use ($customer, $request, $email, &$created) {
            $account = $customer->account()->first();
            $created = !$account;

            if (!$account) {
                $account = new CustomerAccount([
                    'store_id' => $customer->store_id,
                    'email' => $email,
                    'is_active' => true,
                ]);
                $account->customer()->associate($customer);
            }

            $account->forceFill([
                'email' => $email,
                'password' => Hash::make($request->password),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('customer_password_reset_tokens')
                ->where('store_id', $customer->store_id)
                ->where('email', $email)
                ->delete();
        });

        return $this->sendSuccess($created
            ? 'Acceso al catálogo creado y contraseña establecida correctamente.'
            : 'Contraseña del cliente actualizada correctamente.');
    }

    /**
     * @throws ValidatorException
     */
    public function update(UpdateCustomerRequest $request, $id): CustomerResource
    {
        $existingCustomer = $this->customerRepository->find($id);
        $this->authorizeStoreOwnership($existingCustomer);
        $input = $request->all();
        if (! empty($input['dob'])) {
            $input['dob'] = $input['dob'] ?? date('Y/m/d');
        }
        $customer = DB::transaction(function () use ($input, $id, $existingCustomer) {
            $updated = $this->customerRepository->update($input, $id);
            if ($existingCustomer->account) {
                $existingCustomer->account->update([
                    'email' => Str::lower(trim($updated->email)),
                ]);
            }

            return $updated;
        });
        $customer->load('account:id,customer_id,is_active');

        return new CustomerResource($customer);
    }

    public function destroy($id): JsonResponse
    {
        $this->authorizeStoreOwnership($this->customerRepository->find($id));
        if (getSettingValue('default_customer') == $id) {
            return $this->SendError('Default customer can\'t be deleted');
        }
        $this->customerRepository->delete($id);

        return $this->sendSuccess('Customer deleted successfully');
    }

    public function bestCustomersPdfDownload(): JsonResponse
    {
        $storeId = $this->requireCurrentStoreId();
        $month = Carbon::now('America/Guayaquil')->month;
        $topCustomers = Customer::leftJoin('sales', 'customers.id', '=', 'sales.customer_id')
            ->where('customers.store_id', $storeId)
            ->whereMonth('date', $month)
            ->select('customers.*', DB::raw('sum(sales.grand_total) as grand_total'))
            ->groupBy('customers.id')
            ->orderBy('grand_total', 'desc')
            ->latest()
            ->take(5)
            ->withCount('sales')
            ->get();

        $data = [];

        $path = tenantMediaPath('pdf/best-customers.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.best-customers-pdf', compact('topCustomers'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['best_customers_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function pdfDownload(Customer $customer): JsonResponse
    {
        $this->authorizeStoreOwnership($customer);
        $customer = $customer->load('sales.payments');

        $salesData = [];

        $salesData['totalSale'] = $customer->sales->count();

        $salesData['totalAmount'] = $customer->sales->sum('grand_total');

        $salesData['totalPaid'] = 0;

        foreach ($customer->sales as $sale) {
            $salesData['totalPaid'] = $salesData['totalPaid'] + $sale->payments->sum('amount');
        }

        $salesData['totalSalesDue'] = $salesData['totalAmount'] - $salesData['totalPaid'];

        $data = [];

        $path = tenantMediaPath('pdf/customers-report-'.$customer->id.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.customers-report-pdf', compact('customer', 'salesData'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['customers_report_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerSalesPdfDownload(Customer $customer): JsonResponse
    {
        $this->authorizeStoreOwnership($customer);
        $customer = $customer->load('sales.payments');

        $data = [];

        $path = tenantMediaPath('pdf/customer-sales-'.$customer->id.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.customer-sales-pdf', compact('customer'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['customers_sales_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    private const FORMA_PAGO_LABELS = [
        1 => 'EFECTIVO',
        2 => 'CHEQUE',
        3 => 'TRANSFERENCIA',
        4 => 'OTRO',
    ];

    /**
     * Resumen de ventas de un cliente -- una fila por venta. Usado por
     * el modal de "Historial de Ventas" que se abre desde Crear Venta.
     */
    public function salesSummary(Request $request, Customer $customer): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->get('search');

        $query = Sale::where('customer_id', $customer->id)
            ->with(['electronicInvoice', 'payments'])
            ->latest();

        if ($search) {
            $searchNumerico = ltrim($search, '0') ?: '0';
            $query->where(function ($q) use ($search, $searchNumerico) {
                $q->where('reference_code', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$searchNumerico}%")
                    ->orWhereHas('electronicInvoice', function ($qe) use ($search) {
                        $qe->where('secuencial', 'like', "%{$search}%")
                            ->orWhere('clave_acceso', 'like', "%{$search}%");
                    });
            });
        }

        $ventas = $query->paginate($perPage, ['*'], 'page', (int) $request->input('page', 1));

        $ventas->getCollection()->transform(function (Sale $sale) {
            $ei = $sale->electronicInvoice;

            $tipoDocumento = 'RECIBO ELECTRONICO';
            if ($ei) {
                $tipoDocumento = $ei->tipo_comprobante === '05'
                    ? 'NOTA DE DEBITO ELECTRONICA'
                    : 'FACTURA ELECTRONICA';
            }

            $formasPago = $sale->payments->isNotEmpty()
                ? $sale->payments->pluck('payment_type')
                    ->map(fn ($tipo) => self::FORMA_PAGO_LABELS[$tipo] ?? 'OTRO')
                    ->unique()
                    ->implode(' + ')
                : (self::FORMA_PAGO_LABELS[$sale->payment_type] ?? 'OTRO');

            return [
                'nro_orden' => str_pad((string) $sale->id, 9, '0', STR_PAD_LEFT),
                'nro_documento' => $ei ? $ei->numeroComprobante() : $sale->reference_code,
                'fecha_venta' => optional($sale->created_at)->format('Y-m-d'),
                'tipo_documento' => $tipoDocumento,
                'canal_venta' => 'VENTA DIRECTA',
                'forma_pago' => $formasPago,
                'total' => $sale->grand_total,
            ];
        });

        return response()->json(['success' => true, 'data' => $ventas]);
    }

    /**
     * Detalle de ventas de un cliente -- una fila por línea de
     * producto, a través de todas sus ventas.
     */
    public function salesDetail(Request $request, Customer $customer): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->get('search');

        $query = SaleItem::whereHas('sale', function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
        })
            ->with(['sale.electronicInvoice', 'product'])
            ->latest('id');

        if ($search) {
            $searchNumerico = ltrim($search, '0') ?: '0';
            $query->where(function ($q) use ($search, $searchNumerico) {
                $q->whereHas('product', function ($qp) use ($search) {
                    $qp->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                })
                    ->orWhereHas('sale', function ($qs) use ($search, $searchNumerico) {
                        $qs->where('reference_code', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$searchNumerico}%")
                            ->orWhereHas('electronicInvoice', function ($qe) use ($search) {
                                $qe->where('secuencial', 'like', "%{$search}%")
                                    ->orWhere('clave_acceso', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $items = $query->paginate($perPage, ['*'], 'page', (int) $request->input('page', 1));

        $items->getCollection()->transform(function (SaleItem $item) {
            $sale = $item->sale;
            $ei = $sale?->electronicInvoice;

            $unidadMedida = $item->product ? $item->product->getProductUnitName() : null;

            return [
                'nro_orden' => str_pad((string) $sale?->id, 9, '0', STR_PAD_LEFT),
                'nro_documento' => $ei ? $ei->numeroComprobante() : $sale?->reference_code,
                'fecha_venta' => optional($sale?->created_at)->format('Y-m-d'),
                'codigo_producto' => $item->product?->code,
                'producto' => $item->product?->name,
                'unidad_medida' => is_array($unidadMedida) ? ($unidadMedida['name'] ?? '') : $unidadMedida,
                'unidades' => $item->quantity,
                'precio' => $item->product_price,
                'descuento' => $item->discount_amount,
                'iva' => $item->tax_amount,
                'subtotal' => $item->sub_total,
                'total' => $item->sub_total + $item->tax_amount,
            ];
        });

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function customerQuotationsPdfDownload(Customer $customer): JsonResponse
    {
        $this->authorizeStoreOwnership($customer);
        $customer = $customer->load('quotations');

        $data = [];

        $path = tenantMediaPath('pdf/customer-quotations-'.$customer->id.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.customer-quotations-pdf', compact('customer'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['customers_quotations_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerReturnsPdfDownload(Customer $customer): JsonResponse
    {
        $this->authorizeStoreOwnership($customer);
        $customer = $customer->load('salesReturns');

        $data = [];

        $path = tenantMediaPath('pdf/customer-returns-'.$customer->id.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.customer-returns-pdf', compact('customer'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['customers_returns_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerPaymentsPdfDownload($id): JsonResponse
    {
        $this->authorizeStoreOwnership(Customer::findOrFail($id));
        $saleIds = [];

        $sales = Sale::whereCustomerId($id)->get();

        foreach ($sales as $sale) {
            $saleIds[] = $sale->id;
        }

        $payments = SalesPayment::whereIn('sale_id', $saleIds)->with('sale')->get();

        $data = [];

        $path = tenantMediaPath('pdf/customer-payments-'.$id.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.customer-payments-pdf', compact('payments'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['customers_payments_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function importCustomers(Request $request)
    {
        Excel::import(new CustomerImport(), request()->file('file'));

        return $this->sendSuccess('Customers imported successfully');
    }
}
