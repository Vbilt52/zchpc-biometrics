<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page Not Found | ZCHPC</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #001633;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .error-card {
            background: #001f47;
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 16px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.5), 0 0 25px rgba(12, 112, 162, 0.2);
            max-width: 580px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .error-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(12, 112, 162, 0.15);
            border: 2px solid #0c70a2;
            color: #38bdf8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.25rem;
            margin-bottom: 24px;
        }
        .error-code {
            font-size: 0.85rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #38bdf8;
            margin-bottom: 8px;
        }
        .error-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 14px;
        }
        .error-desc {
            color: #94a3b8;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn-row {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-family: inherit;
        }
        .btn-primary {
            background: #0c70a2;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(12, 112, 162, 0.4);
        }
        .btn-primary:hover {
            background: #08557d;
        }
        .btn-outline {
            background: transparent;
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.25);
        }
        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }
        .btn-logout {
            background: rgba(220, 38, 38, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.5);
            width: 100%;
        }
        .btn-logout:hover {
            background: #dc2626;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="fa-solid fa-magnifying-glass"></i>
        </div>

        <div class="error-code">HTTP 404 Not Found</div>
        <h1 class="error-title">Page Not Found</h1>

        <p class="error-desc">
            The requested resource or page could not be located on the ZCHPC Access Control Suite.
        </p>

        <div class="action-buttons">
            <div class="btn-row">
                <a href="/" class="btn btn-primary">
                    <i class="fa-solid fa-house"></i> Return to Dashboard
                </a>
                <button onclick="window.history.back()" class="btn btn-outline" type="button">
                    <i class="fa-solid fa-arrow-left"></i> Go Back
                </button>
            </div>

            @auth
                <form action="{{ route('logout') }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-logout" title="Sign out of current account">
                        <i class="fa-solid fa-right-from-bracket"></i> Sign Out / Switch Account
                    </button>
                </form>
            @endauth
        </div>
    </div>
</body>
</html>

