{{-- Form Field Partial --}}
@if(isset($field['type']) && $field['type'] === 'message')
    {{-- Inline message field --}}
    <div class="component-message component-message--{{ $field['messageType'] }}" style="margin-bottom: var(--spacing-md);">
        @if(isset($field['prefix']) && $field['prefix'])
        <span class="message-prefix">{{ $field['prefix'] }}</span>
        @endif
        {{ $field['text'] }}
    </div>
@elseif(isset($field['type']) && $field['type'] === 'checkbox')
    {{-- Checkbox field --}}
    <div style="margin-bottom: 24px;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <input type="hidden" name="{{ $field['name'] }}" value="0" />
            <input 
                id="{{ $field['id'] }}" 
                name="{{ $field['name'] }}" 
                type="checkbox" 
                value="1"
                {{ old($field['name'], $field['defaultValue']) ? 'checked' : '' }}
                aria-label="{{ $field['ariaLabels']['field'] ?? '' }}"
                style="width: 20px; height: 20px; margin-top: 2px; cursor: pointer; flex-shrink: 0; accent-color: #007bff;"
            />
            <div style="flex: 1;">
                <label for="{{ $field['id'] }}" style="display: block; color: var(--text-primary); font-weight: 600; font-size: 1.05em; cursor: pointer; margin-bottom: 6px;">
                    {{ $field['label'] }}
                </label>
                @if(isset($field['description']) && $field['description'])
                <p style="color: var(--text-secondary); font-size: 0.9em; line-height: 1.5; margin: 0;">
                    {{ $field['description'] }}
                </p>
                @endif
            </div>
        </div>
    </div>
@elseif(isset($field['type']) && $field['type'] === 'checkbox_array')
    {{-- Checkbox array field (for multiple checkboxes with same name) --}}
    <div style="margin-bottom: 24px;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <input 
                id="{{ $field['id'] }}" 
                name="{{ $field['name'] }}" 
                type="checkbox" 
                value="{{ $field['value'] }}"
                {{ $field['defaultValue'] ? 'checked' : '' }}
                aria-label="{{ $field['ariaLabels']['field'] ?? '' }}"
                style="width: 20px; height: 20px; margin-top: 2px; cursor: pointer; flex-shrink: 0; accent-color: #007bff;"
            />
            <div style="flex: 1;">
                <label for="{{ $field['id'] }}" style="display: block; color: var(--text-primary); font-weight: 600; font-size: 1.05em; cursor: pointer; margin-bottom: 6px;">
                    {{ $field['label'] }}
                </label>
                @if(isset($field['description']) && $field['description'])
                <p style="color: var(--text-secondary); font-size: 0.9em; line-height: 1.5; margin: 0;">
                    {{ $field['description'] }}
                </p>
                @endif
            </div>
        </div>
    </div>
@else
<div class="form-mobile-group">
    @if(isset($field['type']) && $field['type'] === 'segmented')
        <span class="form-mobile-label">{{ $field['label'] }}</span>
    @else
        <label for="{{ $field['id'] }}" class="form-mobile-label">{{ $field['label'] }}</label>
    @endif

    @if(isset($field['type']) && $field['type'] === 'segmented')
        @if(isset($field['description']) && $field['description'])
        <p style="color: var(--text-secondary); font-size: 0.9em; line-height: 1.5; margin: 0 0 12px 0;">
            {{ $field['description'] }}
        </p>
        @endif
        <div class="segmented-control" id="{{ $field['id'] }}">
            @foreach($field['options'] as $option)
                <input 
                    type="radio" 
                    name="{{ $field['name'] }}" 
                    id="{{ $field['id'] }}-{{ $option['value'] }}" 
                    value="{{ $option['value'] }}" 
                    class="segmented-control-input"
                    {{ old($field['name'], $field['defaultValue']) == $option['value'] ? 'checked' : '' }}
                />
                <label for="{{ $field['id'] }}-{{ $option['value'] }}" class="segmented-control-label">
                    {{ $option['label'] }}
                </label>
            @endforeach
            <div class="segmented-control-switch option-count-{{ count($field['options']) }}"></div>
        </div>
    @elseif(isset($field['type']) && $field['type'] === 'select')
        <select id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="form-select" aria-label="{{ $field['ariaLabels']['field'] ?? '' }}">
            @foreach($field['options'] as $option)
                <option value="{{ $option['value'] }}" {{ old($field['name'], $field['defaultValue']) == $option['value'] ? 'selected' : '' }}>
                    {{ $option['label'] }}
                </option>
            @endforeach
        </select>
    @elseif(isset($field['type']) && $field['type'] === 'text')
        <input type="text" id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="text-input" value="{{ old($field['name'], $field['defaultValue']) }}" placeholder="{{ $field['placeholder'] ?? '' }}" aria-label="{{ $field['ariaLabels']['field'] ?? '' }}">
    @elseif(isset($field['type']) && $field['type'] === 'password')
        <input type="password" id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="text-input" placeholder="{{ $field['placeholder'] ?? '' }}" aria-label="{{ $field['ariaLabels']['field'] ?? '' }}" autocomplete="{{ $field['name'] === 'current-password' ? 'current-password' : 'new-password' }}">
    @elseif(isset($field['type']) && $field['type'] === 'file')
        <input type="file" id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="file-input" accept="{{ $field['accept'] ?? 'image/*' }}" aria-label="{{ $field['ariaLabels']['field'] ?? '' }}">
    @elseif(isset($field['type']) && $field['type'] === 'textarea')
        <textarea id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="comment-textarea{{ isset($field['cssClass']) && $field['cssClass'] ? ' ' . $field['cssClass'] : '' }}" placeholder="{{ $field['placeholder'] ?? '' }}" rows="3" aria-label="{{ $field['ariaLabels']['field'] ?? '' }}">{{ old($field['name'], $field['defaultValue']) }}</textarea>
    @elseif(isset($field['type']) && $field['type'] === 'date')
        <input type="date" id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="text-input" value="{{ old($field['name'], $field['defaultValue']) }}" placeholder="{{ $field['placeholder'] ?? '' }}" aria-label="{{ $field['ariaLabels']['field'] ?? '' }}">
    @else
        <div class="number-input-group" 
             data-increment="{{ $field['increment'] }}" 
             data-min="{{ $field['min'] }}" 
             data-max="{{ $field['max'] ?? '' }}">
            <button type="button" class="decrement-button" aria-label="{{ $field['ariaLabels']['decrease'] ?? '' }}">{{ $data['buttons']['decrement'] }}</button>
            <input type="text" id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="number-input" value="{{ old($field['name'], $field['defaultValue']) }}" inputmode="numeric" step="{{ $field['step'] ?? '1' }}" data-step="{{ $field['step'] ?? '1' }}">
            <button type="button" class="increment-button" aria-label="{{ $field['ariaLabels']['increase'] ?? '' }}">{{ $data['buttons']['increment'] }}</button>
        </div>
    @endif
</div>
@endif
