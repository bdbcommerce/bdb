<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — BDB CMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center font-sans antialiased">
    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <img src="/images/logo.jpg" alt="BDB" class="h-10 w-auto mx-auto mb-6">
            <h1 class="text-lg font-semibold text-slate-900">Admin Panel</h1>
            <p class="text-sm text-slate-500 mt-1">Sign in to manage content</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-8 shadow-sm shadow-slate-200/50">
            <form method="POST" action="{{ route('admin.login.post') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('email') border-red-400 @enderror">
                    @error('email')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                    <input type="password" name="password" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="remember" id="remember" class="rounded border-slate-300">
                    <label for="remember" class="text-sm text-slate-600">Remember me</label>
                </div>
                <button type="submit"
                        class="w-full bg-slate-900 text-white text-sm font-medium rounded-lg py-2.5 hover:bg-slate-700 transition-colors">
                    Sign in
                </button>
            </form>
        </div>
    </div>
</body>
</html>
