<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromView;

class ProductExcelExport implements FromView
{
    public function __construct(private readonly int $storeId)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        $query = Product::with('productCategory', 'brand', 'stock')
            ->where('store_id', $this->storeId);
        if (isset(request()->id)) {
            $query->where('product_unit', request()->id);
        }
        $products = $query->get();

        return view('excel.product-excel-export', ['products' => $products]);
    }
}
