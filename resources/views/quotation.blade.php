<!DOCTYPE html><html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}"><title inertia>参考报价 · 鑫源昌</title>
@vite('resources/css/app.css')
@viteReactRefresh
@vite('resources/js/quotation.jsx', 'quotation-build')
@inertiaHead
</head><body>@inertia</body></html>
