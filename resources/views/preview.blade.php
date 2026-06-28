<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview · {{ $slug }} · Migration Engine</title>
    {{-- THROWAWAY: BUILD.md step 7 demo/preview bundle. --}}
    @viteReactRefresh
    @vite(['resources/js/preview/main.tsx'])
</head>
<body>
    <div id="preview-root" data-slug="{{ $slug }}"></div>
</body>
</html>
