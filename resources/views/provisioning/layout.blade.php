<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title>@yield('title') · flitops</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%232563EB'/%3E%3Cpath transform='translate(16 16) scale(0.75) translate(-16 -17)' fill='%23FFFFFF' fill-rule='evenodd' d='M8 6h16a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zM20.75 9a1.25 1.25 0 1 0 2.5 0a1.25 1.25 0 1 0-2.5 0zM8 14h10a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2zM14.75 17a1.25 1.25 0 1 0 2.5 0a1.25 1.25 0 1 0-2.5 0zM8 22h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2z'/%3E%3C/svg%3E">
<style>
    :root {
        --background: #ffffff;
        --foreground: #0a0a0a;
        --muted: #737373;
        --border: #e5e5e5;
        --surface: #fafafa;
        --button: #171717;
        --button-text: #fafafa;
        --brand: #2563eb;
        --accent: #ff7a59;
        --glow-brand: rgba(37, 99, 235, 0.16);
        --glow-accent: rgba(255, 122, 89, 0.16);
    }

    @media (prefers-color-scheme: dark) {
        :root {
            --background: #0a0a0a;
            --foreground: #fafafa;
            --muted: #a3a3a3;
            --border: #262626;
            --surface: #141414;
            --button: #fafafa;
            --button-text: #171717;
            --glow-brand: rgba(37, 99, 235, 0.28);
            --glow-accent: rgba(255, 122, 89, 0.2);
        }
    }

    * {
        box-sizing: border-box;
    }

    html {
        -webkit-text-size-adjust: 100%;
    }

    body {
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        min-height: 100dvh;
        margin: 0;
        overflow-x: hidden;
        background: var(--background);
        color: var(--foreground);
        font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    a {
        color: inherit;
    }

    a:focus-visible {
        outline: 2px solid var(--brand);
        outline-offset: 3px;
    }

    .backdrop {
        position: absolute;
        inset: 0 0 auto;
        height: 640px;
        overflow: hidden;
        pointer-events: none;
    }

    .backdrop-grid {
        position: absolute;
        inset: 0;
        background-image: linear-gradient(to right, var(--border) 1px, transparent 1px), linear-gradient(to bottom, var(--border) 1px, transparent 1px);
        background-size: 56px 56px;
        opacity: 0.7;
        -webkit-mask-image: radial-gradient(ellipse 60% 55% at 50% 0%, #000, transparent);
        mask-image: radial-gradient(ellipse 60% 55% at 50% 0%, #000, transparent);
    }

    .backdrop-glow {
        position: absolute;
        border-radius: 50%;
    }

    .backdrop-glow-brand {
        top: -140px;
        left: 50%;
        width: 760px;
        height: 460px;
        transform: translateX(-50%);
        background: radial-gradient(closest-side, var(--glow-brand), transparent);
    }

    .backdrop-glow-accent {
        top: 80px;
        left: 54%;
        width: 480px;
        height: 320px;
        background: radial-gradient(closest-side, var(--glow-accent), transparent);
    }

    header,
    main,
    footer {
        position: relative;
    }

    header {
        width: 100%;
        max-width: 1120px;
        margin: 0 auto;
        padding: 20px 24px;
    }

    .logo {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: -0.01em;
        text-decoration: none;
    }

    .logo svg {
        display: block;
        width: 28px;
        height: 28px;
    }

    main {
        display: flex;
        flex: 1;
        align-items: center;
        justify-content: center;
        padding: 40px 24px 64px;
    }

    .content {
        width: 100%;
        max-width: 640px;
        text-align: center;
    }

    .status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        max-width: 100%;
        padding: 4px 14px 4px 4px;
        border: 1px solid var(--border);
        border-radius: 999px;
        background: var(--background);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        font-size: 13px;
    }

    .status-code {
        flex: none;
        padding: 2px 8px;
        border-radius: 999px;
        background: var(--accent);
        color: #0a0a0a;
        font-size: 12px;
        font-weight: 600;
    }

    .status-host {
        min-width: 0;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
        text-align: left;
        overflow-wrap: anywhere;
    }

    h1 {
        margin: 28px 0 0;
        font-size: clamp(2.5rem, 8vw, 4rem);
        font-weight: 600;
        line-height: 1.04;
        letter-spacing: -0.035em;
        text-wrap: balance;
    }

    h1 span {
        color: var(--muted);
    }

    .lead {
        max-width: 500px;
        margin: 20px auto 0;
        color: var(--muted);
        font-size: 17px;
        text-wrap: pretty;
    }

    .owner {
        max-width: 500px;
        margin: 40px auto 0;
        padding: 20px 24px;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: var(--surface);
        text-align: left;
    }

    .owner h2 {
        margin: 0;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: -0.01em;
    }

    .owner p {
        margin: 6px 0 0;
        color: var(--muted);
        font-size: 14px;
    }

    .actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 12px;
        margin-top: 32px;
    }

    .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 44px;
        padding: 0 24px;
        border: 1px solid transparent;
        border-radius: 999px;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        transition: background-color 0.15s, opacity 0.15s;
    }

    .button svg {
        width: 16px;
        height: 16px;
    }

    .button-primary {
        background: var(--button);
        color: var(--button-text);
    }

    .button-primary:hover {
        opacity: 0.88;
    }

    .button-secondary {
        border-color: var(--border);
        background: var(--background);
    }

    .button-secondary:hover {
        background: var(--surface);
    }

    footer {
        padding: 24px;
        color: var(--muted);
        font-size: 13px;
        text-align: center;
    }

    footer a {
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    footer a:hover {
        color: var(--foreground);
    }

    @media (max-width: 480px) {
        .actions {
            flex-direction: column;
        }

        .button {
            width: 100%;
        }
    }
</style>
</head>
<body>
<div class="backdrop" aria-hidden="true">
    <div class="backdrop-grid"></div>
    <div class="backdrop-glow backdrop-glow-brand"></div>
    <div class="backdrop-glow backdrop-glow-accent"></div>
</div>

<header>
    <a class="logo" href="https://flitops.com">
        <svg viewBox="0 0 32 32" aria-hidden="true">
            <rect width="32" height="32" rx="7" fill="#2563EB"/>
            <path transform="translate(16 16) scale(0.75) translate(-16 -17)" fill="#FFFFFF" fill-rule="evenodd" d="M8 6h16a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zM20.75 9a1.25 1.25 0 1 0 2.5 0a1.25 1.25 0 1 0-2.5 0zM8 14h10a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2zM14.75 17a1.25 1.25 0 1 0 2.5 0a1.25 1.25 0 1 0-2.5 0zM8 22h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2z"/>
        </svg>
        flitops
    </a>
</header>

<main>
    <div class="content">
@yield('content')
    </div>
</main>

<footer>
    @yield('footer')
</footer>
</body>
</html>
