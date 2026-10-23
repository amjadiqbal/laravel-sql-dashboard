<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:24px;background:#F8FAFC;font-family:Helvetica,Arial,sans-serif;color:#0F172A">
    <div style="max-width:640px;margin:0 auto;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:12px;padding:20px 22px">
        <p style="margin:0 0 6px;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:{{ $check->state === 'passing' ? '#16A34A' : '#DC2626' }};font-weight:700">{{ $check->state === 'passing' ? 'Passing again' : ($check->state === 'failing' ? 'Failing' : 'Error') }}</p>
        <h1 style="margin:0 0 8px;font-size:20px">{{ $check->name }}</h1>
        <p style="margin:0 0 14px;color:#334155">{{ $check->last_message }}</p>
        <p style="margin:0 0 14px;color:#64748B;font-size:13px">Fails on {{ $check->describe() }} · was {{ $previous }}</p>
        @if ($check->state === 'failing' && $rows !== [])
            <table style="border-collapse:collapse;width:100%;font-size:13px">
                <tr>@foreach ($columns as $column)<th style="text-align:left;padding:6px 8px;background:#F1F5F9;border-bottom:1px solid #E2E8F0">{{ $column }}</th>@endforeach</tr>
                @foreach ($rows as $row)
                    <tr>@foreach ($row as $value)<td style="padding:5px 8px;border-top:1px solid #E2E8F0">{{ $value === null ? 'NULL' : (is_bool($value) ? ($value ? 'true' : 'false') : $value) }}</td>@endforeach</tr>
                @endforeach
            </table>
            @if ($more > 0)<p style="color:#64748B;font-size:13px">and {{ $more }} more.</p>@endif
        @endif
        @if ($url)<p style="margin:16px 0 0"><a href="{{ $url }}" style="color:#047857">Open the query</a></p>@endif
    </div>
</body>
</html>
