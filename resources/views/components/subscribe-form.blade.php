{{--
    Subscription form.

    Publish with `php artisan vendor:publish --tag=automateflow-views` to restyle.
    Everything is escaped by Blade's `{{ }}`; the field labels and names come from the
    API and are not trusted to be safe markup.
--}}
<div class="automateflow-form">
    @if ($definition === null)
        <p class="automateflow-form__notice">{{ __('This form is temporarily unavailable.') }}</p>
    @else
        @if ($title = $title())
            <h3 class="automateflow-form__title">{{ $title }}</h3>
        @endif

        @if (session('automateflow.status'))
            <p class="automateflow-form__status automateflow-form__status--{{ session('automateflow.status') }}">
                {{ session('automateflow.message') }}
            </p>
        @endif

        <form method="POST" action="{{ route($action) }}">
            @csrf
            <input type="hidden" name="uuid" value="{{ $uuid }}">

            {{-- Honeypot: invisible to people, filled in by bots. Cheap, and it keeps
                 the package free of a captcha dependency. --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;">
                <label for="automateflow-website-{{ $uuid }}">{{ __('Leave this field empty') }}</label>
                <input type="text" name="website" id="automateflow-website-{{ $uuid }}" tabindex="-1" autocomplete="off">
            </div>

            <p>
                <label for="automateflow-email-{{ $uuid }}">{{ __('Email') }}</label>
                <input type="email" name="email" id="automateflow-email-{{ $uuid }}" required>
            </p>

            @foreach ($fields() as $field)
                <p>
                    <label for="automateflow-{{ $field['name'] }}-{{ $uuid }}">
                        {{ $field['label'] ?? $field['name'] }}
                    </label>
                    <input
                        type="text"
                        name="fields[{{ $field['name'] }}]"
                        id="automateflow-{{ $field['name'] }}-{{ $uuid }}"
                        @if (! empty($field['required'])) required @endif
                    >
                </p>
            @endforeach

            <p>
                <button type="submit">{{ __('Subscribe') }}</button>
            </p>
        </form>
    @endif
</div>
