{{-- $definitions, $input, $action (url), $missing (list) --}}
@if ($definitions !== [])
    <form method="get" action="{{ $action }}" class="card">
        <div class="vars">
            @foreach ($definitions as $name => $d)
                @php($value = $input[$name] ?? $d['default'] ?? '')
                <div>
                    <label for="v-{{ $name }}">{{ $d['label'] }} <span class="mono" style="font-weight:400">{{ '{'.$name.'}' }}</span></label>
                    @if ($d['type'] === 'select')
                        <select id="v-{{ $name }}" name="v[{{ $name }}]">
                            @foreach ($d['options'] as $option)
                                <option value="{{ $option }}" @selected((string) $value === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    @elseif ($d['type'] === 'boolean')
                        <select id="v-{{ $name }}" name="v[{{ $name }}]">
                            <option value="1" @selected(in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true))>Yes</option>
                            <option value="0" @selected(in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', ''], true))>No</option>
                        </select>
                    @else
                        @php($type = \AmjadIqbal\SqlDashboard\Sql\VariableType::from($d['type']))
                        <input id="v-{{ $name }}" name="v[{{ $name }}]" value="{{ $value }}"
                               type="{{ preg_match('/^(now|today|yesterday|tomorrow|[+-]\d)/i', (string) $value) ? 'text' : $type->inputType() }}"
                               @if ($d['type'] === 'decimal') step="any" @endif>
                    @endif
                    @if (in_array($name, $missing ?? [], true))<div class="err">Needs a value.</div>@endif
                </div>
            @endforeach
            <div><button class="btn primary" type="submit">Run</button></div>
        </div>
    </form>
@endif
