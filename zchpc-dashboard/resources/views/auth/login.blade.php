<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to ZCHPC Access Control — manage terminals, personnel and attendance records securely.">
    <title>Sign In — ZCHPC Access Control</title>
    <link rel="icon" type="image/png" href="{{ asset('images/zchpc-logo.png') }}">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Auth styles (self-contained, no app.css needed) -->
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
</head>
<body class="auth-page">

    <!-- ══════════════════════════════════════
         Login Card
         ══════════════════════════════════════ -->
    <div class="auth-card">

        <!-- ── Header (dark navy) ── -->
        <div class="auth-header">
            <div class="auth-emblem">
                <img src="{{ asset('images/zchpc-logo.png') }}" alt="ZCHPC Logo" width="74" height="74">
            </div>
            <div class="auth-emblem-label">ZCHPC</div>
            <h1 class="auth-title">ZCHPC Access Control</h1>
            <p class="auth-subtitle">Secure authentication for your control panel</p>

            {{-- Error alert --}}
            @if($errors->any())
                <div class="auth-alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            {{-- Success alert --}}
            @if(session('success'))
                <div class="auth-alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>{{ session('success') }}</span>
                </div>
            @endif
        </div>

        <!-- ── Tab bar ── -->
        <div class="auth-tabs">
            <div class="auth-tab auth-tab-active" id="tab-signin">Sign In</div>
            <div class="auth-tab auth-tab-inactive" id="tab-signup">Sign Up</div>
        </div>

        <!-- ── Card body ── -->
        <div class="auth-body">
            <form id="login-form" method="POST" action="{{ route('login.store') }}">
                @csrf

                {{-- Username --}}
                <div class="auth-input-group">
                    <i class="fa-solid fa-user input-icon-left"></i>
                    <input
                        type="text"
                        id="login"
                        name="login"
                        placeholder="Username"
                        value="{{ old('login') }}"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>

                {{-- Password --}}
                <div class="auth-input-group">
                    <i class="fa-solid fa-lock input-icon-left"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                        style="padding-right: 2.75rem;"
                    >
                    <button type="button" class="auth-eye-toggle" id="toggle-password" aria-label="Toggle password visibility">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>

                {{-- Options row --}}
                <div class="auth-options-row">
                    <label class="auth-remember">
                        <input type="checkbox" name="remember" @checked(old('remember'))>
                        Remember me
                    </label>
                    <a href="#" class="auth-forgot-link" id="forgot-link">Forgot password?</a>
                </div>

                {{-- Submit --}}
                <button type="submit" class="auth-submit-btn">
                    Sign In &nbsp;<i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>

        <!-- ── Card footer ── -->
        <div class="auth-card-footer">
            <i class="fa-solid fa-headset"></i>
            Need help? <a href="#" id="support-link">Contact support</a>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         Page Footer
         ══════════════════════════════════════ -->
    <div class="auth-page-footer">
        &copy; {{ date('Y') }} ZCHPC Access Control. All rights reserved.
    </div>

    <!-- ══════════════════════════════════════
         Sign Up Modal (restricted notice)
         ══════════════════════════════════════ -->
    <div class="auth-modal-overlay" id="signup-modal">
        <div class="auth-modal">
            <button class="auth-modal-close" data-close-modal="signup-modal"><i class="fa-solid fa-xmark"></i></button>
            <h3><i class="fa-solid fa-user-plus" style="color:#0070e0; margin-right:0.4rem;"></i> Registration Unavailable</h3>
            <p>User accounts are managed by the system administrator. Please contact your administrator to request access to the ZCHPC Access Control dashboard.</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         Forgot Password Modal
         ══════════════════════════════════════ -->
    <div class="auth-modal-overlay" id="forgot-modal">
        <div class="auth-modal">
            <button class="auth-modal-close" data-close-modal="forgot-modal"><i class="fa-solid fa-xmark"></i></button>
            <h3><i class="fa-solid fa-key" style="color:#f59e0b; margin-right:0.4rem;"></i> Reset Your Password</h3>
            <p>For security reasons, passwords can only be reset by the system administrator. Please contact the administrator who manages this server to recover your account.</p>
            <p style="margin-top:0.5rem; font-size:0.8rem; color:#94a3b8;">Your personnel and attendance records will be preserved.</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         Contact Support Modal
         ══════════════════════════════════════ -->
    <div class="auth-modal-overlay" id="support-modal">
        <div class="auth-modal">
            <button class="auth-modal-close" data-close-modal="support-modal"><i class="fa-solid fa-xmark"></i></button>
            <h3><i class="fa-solid fa-headset" style="color:#0070e0; margin-right:0.4rem;"></i> Contact Support</h3>
            <p>For technical assistance with the ZCHPC Access Control system, please contact your IT department or system administrator.</p>
            <p style="margin-top:0.5rem; font-size:0.8rem; color:#94a3b8;">Include your username and a description of the issue when reaching out.</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         Client-side JavaScript
         ══════════════════════════════════════ -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // ── Password visibility toggle ──
            const toggle = document.getElementById('toggle-password');
            const pwInput = document.getElementById('password');
            if (toggle && pwInput) {
                toggle.addEventListener('click', function () {
                    const isPassword = pwInput.type === 'password';
                    pwInput.type = isPassword ? 'text' : 'password';
                    toggle.querySelector('i').className = isPassword
                        ? 'fa-regular fa-eye-slash'
                        : 'fa-regular fa-eye';
                });
            }

            // ── Tab switching ──
            document.getElementById('tab-signup').addEventListener('click', function () {
                document.getElementById('signup-modal').classList.add('active');
            });

            // ── Forgot password link ──
            document.getElementById('forgot-link').addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('forgot-modal').classList.add('active');
            });

            // ── Contact support link ──
            document.getElementById('support-link').addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('support-modal').classList.add('active');
            });

            // ── Close modal (button or overlay click) ──
            document.querySelectorAll('.auth-modal-overlay').forEach(function (overlay) {
                // Close on background click
                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) overlay.classList.remove('active');
                });
            });
            document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById(btn.dataset.closeModal).classList.remove('active');
                });
            });

            // ── Escape key closes modals ──
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.auth-modal-overlay.active').forEach(function (m) {
                        m.classList.remove('active');
                    });
                }
            });
        });
    </script>
</body>
</html>
