<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateQuotationRequest;
use App\Http\Requests\UpdateQuotationRequest;
use App\Http\Resources\QuotationCollection;
use App\Http\Resources\QuotationResource;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Repositories\QuotationRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QuotationAPIController extends AppBaseController
{
    /** @var quotationRepository */
    private $quotationRepository;

    public function __construct(QuotationRepository $quotationRepository)
    {
        $this->quotationRepository = $quotationRepository;
    }

    public function index(Request $request)
    {
        $storeId = $this->requireCurrentStoreId();
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $customer = Customer::where('store_id', $storeId)->where('name', 'LIKE', "%$search%")->exists();
        $warehouse = Warehouse::where('store_id', $storeId)->active()->where('name', 'LIKE', "%$search%")->exists();

        $quotations = $this->quotationRepository;
        $this->scopeQueryToCurrentStore($quotations);
        if ($customer || $warehouse) {
            $quotations->whereHas('customer', function (Builder $q) use ($search, $customer) {
                if ($customer) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $quotations->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $quotations->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('customer_id')) {
            $quotations->where('customer_id', $request->get('customer_id'));
        }

        if ($request->get('status') && $request->get('status') != 'null') {
            $quotations->Where('status', $request->get('status'));
        }

        $quotations = $quotations->paginate($perPage);

        QuotationResource::usingWithCollection();

        return new QuotationCollection($quotations);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    public function store(CreateQuotationRequest $request): QuotationResource
    {
        $input = $request->all();
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));
        $this->authorizeStoreModelId(Customer::class, $request->input('customer_id'));
        $this->authorizeProductItems($input['quotation_items'] ?? []);
        $quotation = $this->quotationRepository->storeQuotation($input);

        return new QuotationResource($quotation);
    }

    public function show($id): QuotationResource
    {
        $quotation = $this->quotationRepository->find($id);
        $this->authorizeWarehouseAccess($quotation->warehouse_id);

        return new QuotationResource($quotation);
    }

    public function quotationInfo(Quotation $quotation): JsonResponse
    {
        $this->authorizeWarehouseAccess($quotation->warehouse_id);
        $quotation = $quotation->load('quotationItems.product.variationType', 'warehouse', 'customer');
        $keyName = [
            'email', 'company_name', 'phone', 'address',
        ];
        $quotation['company_info'] = collect($keyName)->mapWithKeys(fn ($key) => [$key => getSettingValue($key)])->all();

        return $this->sendResponse($quotation, 'Quotation information retrieved successfully');
    }

    public function edit(Quotation $quotation): QuotationResource
    {
        $this->authorizeWarehouseAccess($quotation->warehouse_id);
        $quotation = $quotation->load('quotationItems.product.stocks', 'warehouse');

        return new QuotationResource($quotation);
    }

    public function update(UpdateQuotationRequest $request, $id): QuotationResource
    {
        $existing = Quotation::findOrFail($id);
        $this->authorizeWarehouseAccess($existing->warehouse_id);
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));
        $this->authorizeStoreModelId(Customer::class, $request->input('customer_id'));
        $input = $request->all();
        $this->authorizeProductItems($input['quotation_items'] ?? []);
        $quotation = $this->quotationRepository->updateQuotation($input, $id);

        return new QuotationResource($quotation);
    }

    public function destroy(Quotation $quotation): JsonResponse
    {
        $this->authorizeWarehouseAccess($quotation->warehouse_id);
        $this->quotationRepository->delete($quotation->id);

        return $this->sendSuccess('Quotation Deleted successfully');
    }

    public function pdfDownload(Quotation $quotation): JsonResponse
    {
        $this->authorizeWarehouseAccess($quotation->warehouse_id);
        $quotation = $quotation->load('customer', 'quotationItems.product');
        $data = [];
        $path = tenantMediaPath('pdf/Quotation-'.$quotation->reference_code.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);
        $pdf = PDF::loadView('pdf.quotation-pdf', compact('quotation'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['quotation_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'Quotation pdf retrieved Successfully');
    }
}
