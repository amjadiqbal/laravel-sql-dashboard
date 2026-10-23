{{-- $result (Result), $chart (?Chart), $chartWhy (?string), $rowsShown (int, optional) --}}
@php($numeric = array_map(fn ($i) => \AmjadIqbal\SqlDashboard\Charts\Chart::kind($result->column($i)) === 'number', array_keys($result->columns)))
<div class="stats" style="margin-bottom:10px">
    {{ number_format($result->rowCount()) }} {{ $result->rowCount() === 1 ? 'row' : 'rows' }} · {{ number_format($result->durationMs) }} ms
    @if ($result->truncated === 'rows')
        · <b>cut off at the row limit</b>: the query returned more
    @elseif ($result->truncated === 'bytes')
        · <b>cut off at the size limit</b>: the values got too large
    @endif
</div>
@if ($chart)
    <div style="margin-bottom:14px">{!! \AmjadIqbal\SqlDashboard\Charts\SvgChart::render($chart) !!}</div>
    @if ($chart->note)<p class="hint">{{ $chart->note }}</p>@endif
@elseif ($chartWhy)
    <p class="hint">{{ $chartWhy }}</p>
@endif
@if ($result->rowCount() === 0)
    <div class="empty">No rows.</div>
@else
    <div class="table-wrap" @isset($maxHeight) style="max-height: {{ $maxHeight }}px" @endisset>
        <table class="data">
            <thead><tr>@foreach ($result->columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach (array_slice($result->rows, 0, $rowsShown ?? PHP_INT_MAX) as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        @if ($value === null)
                            <td class="null">null</td>
                        @else
                            <td @class(['num' => $numeric[$i] ?? false])>{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if (isset($rowsShown) && $result->rowCount() > $rowsShown)
        <p class="hint">Showing {{ $rowsShown }} of {{ $result->rowCount() }} rows.</p>
    @endif
@endif
