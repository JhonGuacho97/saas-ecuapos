<?php

namespace App\Http\Controllers\API\M1;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Hold;
use App\Repositories\SaleRepository;

/**
 * Class SaleAPIController
 */
class SaleAPIController extends AppBaseController
{
    /** @var saleRepository */
    private $saleRepository;

    public function __construct(SaleRepository $saleRepository)
    {
        $this->saleRepository = $saleRepository;
    }

    public function store(CreateSaleRequest $request): SaleResource
    {
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));
        $this->authorizeStoreModelId(\App\Models\Customer::class, $request->input('customer_id'));
        $this->authorizeProductItems($request->input('sale_items', []));
        if (isset($request->hold_ref_no)) {
            $holdExist = Hold::whereReferenceCode($request->hold_ref_no)
                ->where('warehouse_id', $request->input('warehouse_id'))->first();
            if (! empty($holdExist)) {
                $holdExist->delete();
            }
        }
        $input = $request->all();
        $sale = $this->saleRepository->storeSale($input);

        return new SaleResource($sale);
    }
}
