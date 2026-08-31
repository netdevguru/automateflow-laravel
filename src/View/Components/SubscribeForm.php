<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\View\Components;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Component;

/**
 * Renders a workspace subscription form.
 *
 *     <x-automateflow-subscribe-form uuid="..." />
 *
 * ## The API key never reaches the browser
 *
 * The form posts to *this* application, which relays the submission with the key
 * attached server-side. The obvious alternative — posting straight to the API from the
 * visitor's browser — cannot work, because the credential is a workspace key with write
 * scope: publishing it in page source hands every visitor the ability to write to the
 * workspace.
 *
 * The definition is cached because a form on a popular page would otherwise spend one
 * API request per view, against a per-minute budget shared with everything else.
 */
class SubscribeForm extends Component
{
    public const CACHE_PREFIX = 'automateflow.form.';

    public const CACHE_SECONDS = 900;

    /** @var array<string, mixed>|null */
    public ?array $definition = null;

    public function __construct(
        public string $uuid,
        public ?string $heading = null,
        public string $action = 'automateflow.form.submit',
    ) {
        $this->definition = $this->definition();
    }

    /**
     * Fields the form should render, beyond the always-present email input.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $fields = $this->definition['fields'] ?? [];

        if (! is_array($fields)) {
            return [];
        }

        // `email` is rendered unconditionally by the view, so a definition that also
        // declares it must not produce a second input with the same name.
        return array_values(array_filter(
            $fields,
            static fn ($field): bool => is_array($field)
                && is_string($field['name'] ?? null)
                && $field['name'] !== 'email'
        ));
    }

    public function title(): ?string
    {
        return $this->heading ?? (is_string($this->definition['name'] ?? null) ? $this->definition['name'] : null);
    }

    /**
     * @return array<string, mixed>|null Null when the form could not be loaded.
     */
    private function definition(): ?array
    {
        return Cache::remember(
            self::CACHE_PREFIX.$this->uuid,
            self::CACHE_SECONDS,
            function (): ?array {
                try {
                    $response = app(Client::class)->form($this->uuid);
                } catch (AutomateFlowException) {
                    // Cached as null for the same window, so an outage does not turn
                    // every page view into another failing request.
                    return null;
                }

                $data = $response['data'] ?? $response;

                return is_array($data) ? $data : null;
            }
        );
    }

    public function render(): View
    {
        return view('automateflow::components.subscribe-form');
    }
}
