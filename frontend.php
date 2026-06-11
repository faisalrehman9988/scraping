<?php
require_once 'db.php';

function youtubeVideoId(?string $url): string
{
    if (empty($url)) {
        return '';
    }

    $parts = parse_url($url);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['v'])) {
            return $query['v'];
        }
    }

    $path = trim($parts['path'] ?? '', '/');
    $segments = array_values(array_filter(explode('/', $path)));

    if (($segments[0] ?? '') === 'embed' && !empty($segments[1])) {
        return $segments[1];
    }

    return end($segments) ?: '';
}

if (isset($_GET['play_id'])) {
    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['play_id']);
    ?>
    <!DOCTYPE html>
    <html class="player-page" lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Live Video Player</title>
        <link rel="stylesheet" href="assets/css/frontend.css">
    </head>
    <body>
        <iframe
            class="player-frame"
            src="https://www.youtube.com/embed/<?php echo htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8'); ?>?autoplay=1&rel=0"
            allow="autoplay; encrypted-media; picture-in-picture"
            allowfullscreen>
        </iframe>
    </body>
    </html>
    <?php
    exit;
}

$streams = [];
$dbError = null;
$pdo = getDBconnection();

try {
    if (!$pdo) {
        throw new RuntimeException('Database connection failed.');
    }

    $stmt = $pdo->query("
        SELECT ROW_NUMBER() OVER (ORDER BY id DESC) AS row_num, title, stream_url, country
        FROM live_streams
        ORDER BY id DESC
    ");
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    
}

$successMsg = $_GET['success'] ?? null;
$errorMsg = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Streams</title>
    <link rel="stylesheet" href="assets/css/frontend.css">
</head>
<body>
    <main class="page">
        <header class="page-header">
            <h1 class="page-title">
                Live Streams
                <?php if (!empty($streams)): ?>
                    <span class="count-badge"><?php echo count($streams); ?></span>
                <?php endif; ?>
            </h1>
        </header>
<?php if ($successMsg): ?>
    <div id="successAlert" class="alert alert-success">
        <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <script>
        setTimeout(() => {
            const el = document.getElementById('successAlert');
            if (el) el.style.display = 'none';
        }, 10000);
    </script>
<?php endif; ?>

        <?php if ($errorMsg || $dbError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMsg ?: $dbError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="panel">
            <form id="scrapeForm" class="scrape-form" action="scraper.php" method="POST">
                <input
                    id="urlInput"
                    class="input"
                    type="url"
                    name="url"
                    value="https://liveworldwebcams.com"
                    placeholder="Enter liveworldwebcams.com URL"
                    required>

                <select class="select" name="limit" title="Max cams to scrape">
                    <option value="30">30 cams</option>
                    <option value="40">40 cams</option>
                    <option value="50" selected>50 cams</option>
                    <option value="60">60 cams</option>
                    <option value="100">100 cams</option>
                </select>

                <button id="scrapeBtn" class="button button-primary" type="submit">Scrape URL</button>
                <button id="stopBtn" class="button button-danger" type="button">Stop</button>
            </form>

            <div id="progressArea" class="progress">
                <div class="progress-track">
                    <div id="progressBar" class="progress-bar"></div>
                </div>
                <div class="progress-meta">
                    <span id="progressStatus">Starting...</span>
                    <span id="progressCount">0 found</span>
                </div>
            </div>
        </section>

        <section class="table-panel">
            <?php if (empty($streams)): ?>
                <div class="empty-state">No streams found. Scrape a URL above to get started.</div>
            <?php else: ?>
                <table class="streams-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Title</th>
                            <th class="country-heading">Country</th>
                            <th>Stream</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($streams as $stream): ?>
                            <?php $videoId = youtubeVideoId($stream['stream_url'] ?? ''); ?>
                            <tr>
                                <td class="number-cell"><?php echo (int) $stream['row_num']; ?></td>
                                <td class="title-cell"><?php echo htmlspecialchars($stream['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="country-cell">
                                    <?php if (!empty($stream['country'])): ?>
                                        <?php echo htmlspecialchars($stream['country'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($stream['stream_url']) && $videoId): ?>
                                        <a
                                            class="stream-link"
                                            href="frontend.php?play_id=<?php echo htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8'); ?>"
                                            target="_blank"
                                            rel="noopener">
                                            Open Stream
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">No Stream</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>

    <script>
    const form = document.getElementById('scrapeForm');
    const scrapeBtn = document.getElementById('scrapeBtn');
    const stopBtn = document.getElementById('stopBtn');
    const progressArea = document.getElementById('progressArea');
    const progressBar = document.getElementById('progressBar');
    const progressStatus = document.getElementById('progressStatus');
    const progressCount = document.getElementById('progressCount');
    const limitSelect = document.querySelector('select[name="limit"]');

    let pollInterval = null;

    form.addEventListener('submit', startScraping);

    stopBtn.addEventListener('click', () => {
        fetch('scraper.php?action=stop')
            .then(() => {
                progressStatus.textContent = 'Stopping...';
                stopBtn.disabled = true;
            })
            .catch(() => {
                progressStatus.textContent = 'Could not send stop request.';
            });
    });

    function startScraping() {
        const limit = Number.parseInt(limitSelect.value, 10) || 50;

        scrapeBtn.disabled = true;
        scrapeBtn.textContent = 'Scraping...';
        stopBtn.style.display = 'inline-flex';
        stopBtn.disabled = false;
        progressArea.style.display = 'block';
        progressBar.style.width = '5%';
        progressBar.style.background = '';
        progressStatus.textContent = 'Starting...';
        progressCount.textContent = '0 found';

        pollInterval = setInterval(() => pollProgress(limit), 1500);
    }

    function pollProgress(limit) {
        fetch('scraper.php?action=progress')
            .then(response => response.json())
            .then(data => updateProgress(data, limit))
            .catch(() => {});
    }

    function updateProgress(data, limit) {
        const found = data.found || 0;
        const status = data.status || '';

        progressStatus.textContent = status;
        progressCount.textContent = `${found} found`;
        progressBar.style.width = `${progressPercent(status, found, limit)}%`;

        if (status === 'done') {
            finishScraping();
        }
    }

    function progressPercent(status, found, limit) {
        if (status.includes('stream') || status.includes('Stream')) {
            const match = status.match(/\((\d+)\s*\/\s*(\d+)\)/);
            return match ? 60 + Math.round((Number(match[1]) / Number(match[2])) * 35) : 62;
        }

        if (status.includes('Collect') || status.includes('category')) {
            return Math.min(55, 10 + Math.round((found / limit) * 45));
        }

        if (status.includes('Saving')) {
            return 97;
        }

        return 5;
    }

    function finishScraping() {
        clearInterval(pollInterval);
        progressBar.style.width = '100%';
        progressBar.style.background = '#16803f';
        progressStatus.textContent = 'Complete. Reloading...';
        setTimeout(() => location.reload(), 1200);
    }
    </script>
</body>
</html>
