<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contract preview · {{ $slug }} · Migration Engine</title>
    {{-- Slice 10 (M1): renders the Site Import Contract v1 envelope. --}}
    @viteReactRefresh
    @vite(['resources/js/preview-contract/main.tsx'])
</head>
<body>
    <div id="contract-preview-root" data-slug="{{ $slug }}"></div>
</body>
</html>
