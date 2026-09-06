<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\LandingPageSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LandingPageSettingController extends AppBaseController
{
    public function show(): JsonResponse
    {
        $setting = LandingPageSetting::first();

        return response()->json(['success' => true, 'data' => [
            'id' => $setting?->id,
            'content' => LandingPageSetting::mergeWithDefaults($setting?->content),
            'is_published' => $setting?->is_published ?? true,
            'updated_at' => $setting?->updated_at?->toIso8601String(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $setting = LandingPageSetting::firstOrNew();
        $setting->fill([
            'content' => $validated['content'],
            'is_published' => $validated['is_published'],
            'updated_by' => $request->user('sanctum')->id,
        ])->save();

        return response()->json(['success' => true, 'message' => 'Landing page actualizada.', 'data' => $setting->fresh()]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
            'section' => ['required', Rule::in(['hero', 'partners', 'testimonials'])],
        ]);
        $path = $request->file('image')->store("landing/{$data['section']}", 'public');

        return response()->json(['success' => true, 'data' => [
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]], 201);
    }

    private function rules(): array
    {
        $rules = [
            'is_published' => 'required|boolean',
            'content' => 'required|array',
            'content.general' => 'required|array',
            'content.general.*' => 'nullable|string|max:1000',
            'content.legal' => 'required|array',
            'content.legal.terms' => 'nullable|string|max:30000',
            'content.legal.privacy' => 'nullable|string|max:30000',
            'content.legal.refunds' => 'nullable|string|max:30000',
        ];
        $sectionFields = [
            'services' => ['title', 'description', 'icon'],
            'benefits' => ['title', 'description', 'icon'],
            'steps' => ['number', 'title', 'description'],
            'testimonials' => ['name', 'role', 'quote', 'avatar'],
            'faqs' => ['question', 'answer'],
            'partners' => ['name', 'image'],
        ];
        foreach ($sectionFields as $section => $fields) {
            $rules["content.{$section}"] = 'present|array|max:30';
            $rules["content.{$section}.*"] = 'array';
            $rules["content.{$section}.*.id"] = 'required|string|max:80';
            $rules["content.{$section}.*.is_active"] = 'required|boolean';
            foreach ($fields as $field) {
                $rules["content.{$section}.*.{$field}"] = 'nullable|string|max:3000';
            }
        }

        return $rules;
    }
}
