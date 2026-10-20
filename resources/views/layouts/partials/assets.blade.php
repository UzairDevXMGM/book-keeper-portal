{{--
    CSS and JS for every page: plain files in public/, no build step (no Vite / npm).
    Edit public/css/app.css and public/js/app.js directly; "?v=" changes when the file changes, so browsers reload it.
--}}
@php($version = fn (string $file) => @filemtime(public_path($file)) ?: '1')
{{-- Light / dark: set before the CSS loads so the page never flashes the wrong colors.
     A saved choice wins; otherwise the device setting decides. --}}
<script>
    (function () {
        var theme = null;
        try { theme = localStorage.getItem('theme'); } catch (e) {}
        if (theme !== 'light' && theme !== 'dark') {
            theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
</script>
<link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $version('css/app.css') }}">
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}" defer></script>
<script src="{{ asset('js/app.js') }}?v={{ $version('js/app.js') }}" data-chart-src="{{ asset('vendor/chart.js/chart.umd.min.js') }}" defer></script>
