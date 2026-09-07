<?php

namespace App\Http\Controllers;

use App\Http\Requests\MailTemplateUpdateRequest;
use App\Http\Resources\MailCollection;
use App\Http\Resources\MailResource;
use App\Models\MailTemplate;
use App\Repositories\MailRepository;
use Illuminate\Http\Request;

class MailTemplateAPIController extends AppBaseController
{
    /** @var mailRepository */
    private $mailRepository;

    public function __construct(MailRepository $mailRepository)
    {
        $this->mailRepository = $mailRepository;
    }

    public function index(Request $request): MailCollection
    {
        $perPage = getPageSize($request);
        $storeId = $this->requireCurrentStoreId();
        $this->ensureStoreTemplates($storeId);

        $mailTemplates = $this->mailRepository->where('store_id', $storeId);

        $mailTemplates = $mailTemplates->paginate($perPage);

        MailResource::usingWithCollection();

        return new MailCollection($mailTemplates);
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

    public function edit(MailTemplate $mailTemplate): MailResource
    {
        $this->authorizeStoreOwnership($mailTemplate);
        return new MailResource($mailTemplate);
    }

    public function update(MailTemplateUpdateRequest $request, $id): MailResource
    {
        $input = $request->all();

        $mailTemplate = $this->mailRepository->updateMailTemplate($input, $id, $this->requireCurrentStoreId());

        return new MailResource($mailTemplate);
    }

    public function changeActiveStatus($id): MailResource
    {
        $mailTemplate = MailTemplate::where('store_id', $this->requireCurrentStoreId())->findOrFail($id);
        $status = ! $mailTemplate->status;
        $mailTemplate->update(['status' => $status]);

        return new MailResource($mailTemplate);
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
        MailTemplate::whereNull('store_id')->get()->each(function (MailTemplate $template) use ($storeId) {
            MailTemplate::firstOrCreate(
                ['store_id' => $storeId, 'type' => $template->type],
                [
                    'template_name' => $template->template_name,
                    'subject' => $template->subject,
                    'content' => $template->content,
                    'status' => $template->status,
                ]
            );
        });
    }
}
