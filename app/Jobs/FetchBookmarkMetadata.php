<?php

namespace App\Jobs;

use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

class FetchBookmarkMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bookmark $bookmark,
        public bool $generateWithAI = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent('Mozilla/5.0 (compatible; Bookmarket/1.0)')
                ->get($this->bookmark->url);

            if (! $response->successful()) {
                return;
            }

            $html = $response->body();

            $updates = [
                'domain' => parse_url($this->bookmark->url, PHP_URL_HOST),
            ];

            // Extract basic metadata
            $extractedTitle = $this->extractTitle($html);
            $extractedDescription = $this->extractDescription($html);

            // Extract favicon
            $favicon = $this->extractFavicon($html, $this->bookmark->url);
            if ($favicon !== '' && $favicon !== '0') {
                $updates['favicon_url'] = $favicon;
            }

            // Use AI to generate a better title if enabled and we have an API key
            if ($this->generateWithAI && config('prism.providers.openai.api_key')) {
                $aiResult = $this->generateWithAI($extractedTitle, $extractedDescription, $html);

                if ($aiResult) {
                    // Only update title if it's still the URL (user didn't set a custom one)
                    if ($this->bookmark->title === $this->bookmark->url && (isset($aiResult['title']) && ($aiResult['title'] !== '' && $aiResult['title'] !== '0'))) {
                        $updates['title'] = $aiResult['title'];
                    }

                    // Set description if not already set
                    if (empty($this->bookmark->description) && (isset($aiResult['description']) && ($aiResult['description'] !== '' && $aiResult['description'] !== '0'))) {
                        $updates['description'] = $aiResult['description'];
                    }

                    // Auto-tag if no tags exist
                    if ($this->bookmark->tags()->count() === 0 && (isset($aiResult['tags']) && $aiResult['tags'] !== [])) {
                        $this->attachTags($aiResult['tags']);
                    }
                }
            } else {
                // Fallback to extracted metadata
                if ($this->bookmark->title === $this->bookmark->url && $extractedTitle) {
                    $updates['title'] = $extractedTitle;
                }

                if (empty($this->bookmark->description) && $extractedDescription) {
                    $updates['description'] = $extractedDescription;
                }
            }

            $this->bookmark->update($updates);
        } catch (\Exception $exception) {
            // Silently fail - the bookmark was already created
            report($exception);
        }
    }

    /**
     * Use AI to generate title, description, and suggested tags.
     *
     * @return array{title: string, description: string, tags: array<string>}|null
     */
    private function generateWithAI(?string $title, ?string $description, string $html): ?array
    {
        try {
            // Extract text content for context (limit to avoid token limits)
            $textContent = $this->extractTextContent($html);

            $prompt = <<<PROMPT
Analyze this webpage and provide:
1. A concise, descriptive title (max 60 chars)
2. A brief description of why someone might save this (max 150 chars)
3. 3-5 relevant tags (single words, lowercase)

URL: {$this->bookmark->url}
Original Title: {$title}
Original Description: {$description}

Page Content (excerpt):
{$textContent}

Respond in this exact format:
TITLE: [your title]
DESCRIPTION: [your description]
TAGS: [tag1, tag2, tag3]
PROMPT;

            $response = (new Prism)
                ->text()
                ->using(Provider::OpenAI, 'gpt-4o-mini')
                ->withPrompt($prompt)
                ->withMaxTokens(200)
                ->asText();

            return $this->parseAIResponse($response->text);
        } catch (\Exception $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Parse the AI response into structured data.
     *
     * @return array{title: string, description: string, tags: array<string>}|null
     */
    private function parseAIResponse(string $response): ?array
    {
        $result = [
            'title' => '',
            'description' => '',
            'tags' => [],
        ];

        // Extract title
        if (preg_match('/TITLE:\s*(.+?)(?:\n|$)/i', $response, $matches)) {
            $result['title'] = trim($matches[1]);
        }

        // Extract description
        if (preg_match('/DESCRIPTION:\s*(.+?)(?:\n|$)/i', $response, $matches)) {
            $result['description'] = trim($matches[1]);
        }

        // Extract tags
        if (preg_match('/TAGS:\s*(.+?)(?:\n|$)/i', $response, $matches)) {
            $tags = array_map(trim(...), explode(',', $matches[1]));
            $result['tags'] = array_filter($tags, fn ($tag): bool => (string) $tag !== '' && strlen((string) $tag) < 30);
        }

        return $result['title'] || $result['description'] || $result['tags'] ? $result : null;
    }

    /**
     * Attach tags to the bookmark.
     *
     * @param  array<string>  $tagNames
     */
    private function attachTags(array $tagNames): void
    {
        $tagIds = collect($tagNames)->map(fn ($name) => Tag::findOrCreateByName(strtolower(trim($name)))->id);

        $this->bookmark->tags()->syncWithoutDetaching($tagIds);
    }

    /**
     * Extract readable text content from HTML.
     */
    private function extractTextContent(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', (string) $html);

        // Remove HTML tags
        $text = strip_tags((string) $html);

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);

        // Limit to ~1000 chars to stay within token limits
        return mb_substr($text, 0, 1000);
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private function extractDescription(string $html): ?string
    {
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private function extractFavicon(string $html, string $url): string
    {
        $baseUrl = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);

        // Look for link rel="icon" or rel="shortcut icon"
        if (preg_match('/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $favicon = $matches[1];

            if (str_starts_with($favicon, '//')) {
                return 'https:'.$favicon;
            }

            if (str_starts_with($favicon, '/')) {
                return $baseUrl.$favicon;
            }

            if (str_starts_with($favicon, 'http')) {
                return $favicon;
            }

            return $baseUrl.'/'.$favicon;
        }

        // Default to /favicon.ico
        return $baseUrl.'/favicon.ico';
    }
}
