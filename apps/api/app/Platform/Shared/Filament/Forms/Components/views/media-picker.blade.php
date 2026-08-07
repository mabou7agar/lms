@php
    $fieldWrapperView = $getFieldWrapperView();
    $stateKind = $getStateKind();
    $previewUrl = $getPreviewUrl();
    $previewKind = $previewKind();
    $label = $getSelectionLabel();
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <div
        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->class(['fi-media-picker'])
        }}
    >
        <div class="fi-media-picker-preview" style="display:flex; flex-direction:column; gap:0.75rem;">
            @if ($stateKind === 'empty')
                <div class="fi-media-picker-placeholder fi-text-sm" style="opacity:0.7;">
                    {{ __('No media selected.') }}
                </div>
            @else
                <div class="fi-media-picker-selection" style="display:flex; align-items:center; gap:0.75rem;">
                    @if ($previewUrl && $previewKind === 'image')
                        <img
                            src="{{ $previewUrl }}"
                            alt="{{ $label }}"
                            style="max-height:8rem; max-width:100%; border-radius:0.5rem; object-fit:contain;"
                        />
                    @elseif ($previewUrl && $previewKind === 'video')
                        <video
                            src="{{ $previewUrl }}"
                            controls
                            style="max-height:10rem; max-width:100%; border-radius:0.5rem;"
                        ></video>
                    @endif

                    <div style="display:flex; flex-direction:column; gap:0.25rem; min-width:0;">
                        <span class="fi-text-sm" style="font-weight:600; overflow:hidden; text-overflow:ellipsis;">
                            {{ $label ?? __('Selected media') }}
                        </span>

                        @if ($stateKind === 'legacy')
                            <span class="fi-text-xs" style="opacity:0.7;">
                                {{ __('Legacy value — replace it with a media asset to modernize this field.') }}
                            </span>
                        @endif

                        @if ($previewUrl && $previewKind === 'link')
                            <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="fi-link fi-text-sm">
                                {{ __('Open preview') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            <div class="fi-media-picker-actions" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                {{ $getAction('select') }}
                {{ $getAction('upload') }}
                {{ $getAction('remove') }}
            </div>
        </div>
    </div>
</x-dynamic-component>
