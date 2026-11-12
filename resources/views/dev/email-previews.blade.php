<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $name }} · Email previews</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
body {
    background: #f1f1f1;
    color: #101010;
    display: flex;
    font: 13px/20px Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }

.sidebar {
    background: #0a0a0a;
    color: #dcdcdc;
    display: flex;
    flex: none;
    flex-direction: column;
    overflow-y: auto;
    padding: 12px;
    width: 264px;
}
.brand { align-items: center; display: flex; gap: 8px; padding: 6px 8px 14px; }
.brand img { border-radius: 7px; height: 28px; width: 28px; }
.brand strong { color: #fff; font-size: 14px; font-weight: 600; }
.brand span { color: #8a8a8a; display: block; font-size: 12px; line-height: 16px; }
.group { color: #8a8a8a; font-size: 12px; font-weight: 500; padding: 14px 8px 4px; }
.sidebar nav a { border-radius: 8px; display: block; padding: 6px 8px; }
.sidebar nav a:hover { background: #1f1f1f; color: #fcfcfc; }
.sidebar nav a[aria-current] { background: #2b2b2b; color: #fcfcfc; font-weight: 500; }
.local { color: #6b6b6b; font-size: 12px; line-height: 16px; margin-top: auto; padding: 16px 8px 4px; }

.main { display: flex; flex: 1; flex-direction: column; min-width: 0; }
.toolbar {
    align-items: center;
    background: #fff;
    border-bottom: 1px solid #e3e3e3;
    display: flex;
    flex: none;
    gap: 16px;
    justify-content: space-between;
    padding: 12px 20px;
}
.meta { min-width: 0; }
.meta h1 { font-size: 15px; font-weight: 650; line-height: 22px; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.meta p { color: #616161; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.actions { align-items: center; display: flex; flex: none; gap: 12px; }
.devices { background: #f1f1f1; border-radius: 10px; display: flex; gap: 2px; padding: 3px; }
.devices button {
    background: none;
    border: 0;
    border-radius: 7px;
    color: #616161;
    cursor: pointer;
    font: inherit;
    font-weight: 500;
    padding: 4px 12px;
}
.devices button:hover { color: #101010; }
.devices button[aria-pressed='true'] { background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / 0.12); color: #101010; }
.open { border-radius: 8px; box-shadow: 0 0 0 1px rgb(0 0 0 / 0.12); font-weight: 500; padding: 5px 12px; }
.open:hover { background: #f7f7f7; }

.stage { display: flex; flex: 1; justify-content: center; min-height: 0; overflow: auto; padding: 20px; }
.frame {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.05), 0 0 0 1px rgb(0 0 0 / 0.08);
    flex: none;
    height: 100%;
    max-width: 100%;
    overflow: hidden;
    transition: width 0.2s ease;
    width: 100%;
}
.frame[data-device='tablet'] { width: 768px; }
.frame[data-device='phone'] { border-radius: 28px; height: min(100%, 844px); width: 390px; }
.frame iframe { border: 0; display: block; height: 100%; width: 100%; }
.size { color: #8a8a8a; font-variant-numeric: tabular-nums; min-width: 52px; text-align: right; }
</style>
</head>
<body>
<aside class="sidebar">
    <div class="brand">
        <img src="{{ asset('apple-touch-icon.png') }}" alt="">
        <div>
            <strong>flitops</strong>
            <span>Email previews</span>
        </div>
    </div>

    <nav>
        @foreach ($groups as $group => $emails)
            <div class="group">{{ $group }}</div>
            @foreach ($emails as $key => $label)
                <a href="{{ route('dev.emails.index', ['email' => $key]) }}" @if ($key === $selected) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        @endforeach
    </nav>

    <p class="local">Only available on your local machine. Sample data, nothing is sent.</p>
</aside>

<main class="main">
    <header class="toolbar">
        <div class="meta">
            <h1>{{ $subject }}</h1>
            <p>From {{ $from }}</p>
        </div>

        <div class="actions">
            <span class="size" id="size"></span>
            <div class="devices" role="group" aria-label="Preview size">
                <button type="button" data-device="desktop">Desktop</button>
                <button type="button" data-device="tablet">Tablet</button>
                <button type="button" data-device="phone">Phone</button>
            </div>
            <a class="open" href="{{ route('dev.emails.show', $selected) }}" target="_blank" rel="noopener">Open in new tab</a>
        </div>
    </header>

    <div class="stage">
        <div class="frame" id="frame">
            <iframe src="{{ route('dev.emails.show', $selected) }}" title="{{ $name }}"></iframe>
        </div>
    </div>
</main>

<script>
    const frame = document.getElementById('frame');
    const size = document.getElementById('size');
    const buttons = document.querySelectorAll('.devices button');

    function showDevice(device) {
        frame.dataset.device = device;
        buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.device === device)));
        localStorage.setItem('email-preview-device', device);
    }

    buttons.forEach((button) => button.addEventListener('click', () => showDevice(button.dataset.device)));
    new ResizeObserver(() => (size.textContent = `${Math.round(frame.clientWidth)}px`)).observe(frame);
    showDevice(localStorage.getItem('email-preview-device') ?? 'desktop');
</script>
</body>
</html>
