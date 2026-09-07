<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\SmsTemplateUpdateRequest;
use App\Http\Resources\SmsTemplateCollection;
use App\Http\Resources\SmsTemplateResource;
use App\Models\SmsTemplate;
use App\Repositories\SmsTemplateRepository;
use Illuminate\Http\Request;

class SmsTemplateAPIController extends AppBaseController
{
    /** @var SmsTemplateRepository */
    private $smsTemplateRepository;

    public function __construct(SmsTemplateRepository $smsTemplateRepository)
    {
        $this->smsTemplateRepository = $smsTemplateRepository;
    }

    public function index(Request $request): SmsTemplateCollection
    {
        $perPage = getPageSize($request);
        $storeId = $this->requireCurrentStoreId();
        $this->ensureStoreTemplates($storeId);

        $smsTemplates = $this->smsTemplateRepository->where('store_id', $storeId);

        $smsTemplates = $smsTemplates->paginate($perPage);

        SmsTemplateResource::usingWithCollection();

        return new SmsTemplateCollection($smsTemplates);
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

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(int $id)
    {
        //
    }

    public function edit(SmsTemplate $smsTemplate): SmsTemplateResource
    {
        $this->authorizeStoreOwnership($smsTemplate);
        return new SmsTemplateResource($smsTemplate);
    }

    public function update(SmsTemplateUpdateRequest $request, $id): SmsTemplateResource
    {
        $input = $request->all();

        $smsTemplate = $this->smsTemplateRepository->updateSmsTemplate($input, $id, $this->requireCurrentStoreId());

        return new SmsTemplateResource($smsTemplate);
    }

    public function changeActiveStatus($id): SmsTemplateResource
    {
        $smsTemplate = SmsTemplate::where('store_id', $this->requireCurrentStoreId())->findOrFail($id);
        $status = ! $smsTemplate->status;
        $smsTemplate->update(['status' => $status]);

        return new SmsTemplateResource($smsTemplate);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(int $id)
    {
        //
    }

    private function ensureStoreTemplates(int $storeId): void
    {
        SmsTemplate::whereNull('store_id')->get()->each(function (SmsTemplate $template) use ($storeId) {
            SmsTemplate::firstOrCreate(
                ['store_id' => $storeId, 'type' => $template->type],
                [
                    'template_name' => $template->template_name,
                    'content' => $template->content,
                    'status' => $template->status,
                ]
            );
        });
    }
}
