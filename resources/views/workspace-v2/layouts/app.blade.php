<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#101314">
    <title>@yield('title', 'Workspace OASIS')</title>
    @vite(['resources/css/workspace-v2.css', 'resources/js/app.js', 'resources/js/workspace-v2/app.js'])
</head>
<body class="workspace-v2-body">
    <div class="workspace-v2-shell" data-workspace-shell>
        @include('workspace-v2.components.sidebar')
        <div class="workspace-v2-main">
            @include('workspace-v2.components.topbar')
            <main class="workspace-v2-content" id="workspace-content">
                @yield('content')
            </main>
        </div>
    </div>
    <div class="workspace-v2-mobile-backdrop" data-mobile-backdrop hidden></div>
    @stack('scripts')
</body>
</html>
