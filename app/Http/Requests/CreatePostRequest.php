<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user is authenticated and has Bluesky connection
        return auth()->check() && auth()->user()->blueskyAuth()->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'text' => 'required|string|max:300', // Bluesky's character limit
            'media_urls' => 'nullable|array|max:4', // Max 4 media items per post
            'media_urls.*' => [
                'required_with:media_urls',
                'url',
                'regex:/^https?:\/\//', // Must be http/https URL
            ],
            'media_types' => 'required_with:media_urls|array|size:'.count($this->media_urls ?? []),
            'media_types.*' => 'required_with:media_urls|string|in:image,video', // Only allow image/video
            'alt_texts' => 'nullable|array|size:'.count($this->media_urls ?? []),
            'alt_texts.*' => 'nullable|string|max:1000', // Alt text character limit
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'text.required' => 'Post text is required',
            'text.max' => 'Post text cannot exceed 300 characters',
            'media_urls.max' => 'Cannot upload more than 4 media items',
            'media_urls.*.url' => 'Invalid media URL format',
            'media_urls.*.regex' => 'Media URL must start with http:// or https://',
            'media_types.*.in' => 'Invalid media type. Must be either image or video',
            'alt_texts.*.max' => 'Alt text cannot exceed 1000 characters',
            'media_types.size' => 'Media types must match number of media URLs',
            'alt_texts.size' => 'Alt texts must match number of media URLs',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check media file types if media URLs are present
            if ($this->has('media_urls') && $this->has('media_types')) {
                foreach ($this->media_urls as $index => $url) {
                    $type = $this->media_types[$index] ?? null;
                    
                    if ($type === 'image' && !$this->isValidImageUrl($url)) {
                        $validator->errors()->add(
                            "media_urls.{$index}",
                            'Invalid image URL or unsupported format'
                        );
                    }
                    
                    if ($type === 'video' && !$this->isValidVideoUrl($url)) {
                        $validator->errors()->add(
                            "media_urls.{$index}",
                            'Invalid video URL or unsupported format'
                        );
                    }
                }
            }
        });
    }

    /**
     * Validate image URL and format
     */
    private function isValidImageUrl(string $url): bool
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        
        // Basic extension check
        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        // Optional: Verify file exists and is accessible
        try {
            $headers = get_headers($url, 1);
            if (!$headers || strpos($headers[0], '200') === false) {
                return false;
            }

            $contentType = $headers['Content-Type'] ?? '';
            return strpos($contentType, 'image/') === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate video URL and format
     */
    private function isValidVideoUrl(string $url): bool
    {
        $allowedExtensions = ['mp4', 'mov'];
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        
        // Basic extension check
        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        // Optional: Verify file exists and is accessible
        try {
            $headers = get_headers($url, 1);
            if (!$headers || strpos($headers[0], '200') === false) {
                return false;
            }

            $contentType = $headers['Content-Type'] ?? '';
            return strpos($contentType, 'video/') === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Trim whitespace from text
        if ($this->has('text')) {
            $this->merge([
                'text' => trim($this->text)
            ]);
        }

        // Clean up alt texts
        if ($this->has('alt_texts')) {
            $altTexts = array_map(function ($text) {
                return $text ? trim($text) : null;
            }, $this->alt_texts);
            
            $this->merge(['alt_texts' => $altTexts]);
        }
    }
}