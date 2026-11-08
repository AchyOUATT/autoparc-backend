<!doctype html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>{{ $texts['mail_subject'] }}</title></head>
<body style="font-family:Arial,sans-serif;color:#182433;line-height:1.6;padding:24px;max-width:560px;margin:auto">
    <h1 style="color:#176446">AutoParc</h1>
    <h2>{{ $texts['title'] }}</h2>
    <p>{{ $space }}</p>
    <p>{{ $texts['mail_intro'] }}</p>
    <p><a href="{{ $url }}" style="display:inline-block;padding:12px 20px;color:white;background:#176446;border-radius:8px;text-decoration:none">{{ $texts['title'] }}</a></p>
    <p>{{ str_replace(':minutes', $minutes, $texts['mail_expiry']) }}</p>
    <p>{{ $texts['mail_ignore'] }}</p>
    <p>{{ $texts['mail_link_help'] }}</p>
    <p style="word-break:break-all"><a href="{{ $url }}">{{ $url }}</a></p>
</body>
</html>
