<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $texts['title'] }} — AutoParc</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f5f8;color:#182433;font:16px/1.5 system-ui,sans-serif;padding:32px 16px}main{max-width:480px;margin:4vh auto;background:white;padding:28px;border-radius:16px;box-shadow:0 8px 32px #18243312}.brand{color:#176446;font-size:22px;font-weight:750}h1{font-size:25px;line-height:1.25;margin:24px 0 16px}label{display:block;font-weight:600;margin-top:20px}input{width:100%;margin-top:8px;padding:12px;border:1px solid #7c8995;border-radius:8px;font:inherit}button,.contact{display:block;width:100%;border:0;border-radius:8px;padding:13px 16px;text-align:center;font:inherit;font-weight:600}button{background:#176446;color:white;margin-top:24px;cursor:pointer}.contact{border:1px solid #176446;color:#176446;text-decoration:none;margin-top:12px}.hint,.support{color:#526171;font-size:14px}.support{margin-top:28px}.errors{padding:12px 20px 12px 32px;background:#fff0f0;color:#981c27;border-radius:8px}input:focus,button:focus,a:focus{outline:3px solid #efac32;outline-offset:3px}
    </style>
</head>
<body>
<main>
    <div class="brand">AutoParc</div>
    @if ($state === 'form')
        <h1>{{ $texts['title'] }}</h1>
        @if ($validationErrors)
            <ul class="errors" role="alert">@foreach ($validationErrors as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif
        <form method="post" action="{{ $action }}">
            @csrf
            <label for="password">{{ $texts['password'] }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required aria-describedby="password-hint">
            <p class="hint" id="password-hint">{{ $texts['password_hint'] }}</p>
            <label for="password-confirmation">{{ $texts['confirmation'] }}</label>
            <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <button type="submit">{{ $texts['submit'] }}</button>
        </form>
    @else
        <h1>{{ $texts[$state.'_title'] }}</h1>
        <p role="status">{{ $texts[$state] }}</p>
    @endif
    <p class="support">{{ $texts['support'] }}</p>
    <a class="contact" href="tel:{{ $phone }}">{{ $texts['contact'] }} · {{ $phone }}</a>
</main>
</body>
</html>
