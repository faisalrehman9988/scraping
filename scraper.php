<?php
require_once 'db.php';

set_time_limit(180);

// Stop signal used by the frontend.
$stopFile = sys_get_temp_dir() . '/scraper_stop_' . session_id();

if (isset($_GET['action']) && $_GET['action'] === 'stop') {
    session_start();
    file_put_contents($stopFile, '1');
    echo json_encode(['stopped' => true]);
    exit;
}

// Progress endpoint used by the frontend.
if (isset($_GET['action']) && $_GET['action'] === 'progress') {
    session_start();
    $progressFile = sys_get_temp_dir() . '/scraper_progress_' . session_id();
    $data = file_exists($progressFile) ? json_decode(file_get_contents($progressFile), true) : [];
    echo json_encode($data ?: ['found' => 0, 'saved' => 0, 'status' => 'idle']);
    exit;
}

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url'])) {

    @unlink($stopFile);
    $progressFile = sys_get_temp_dir() . '/scraper_progress_' . session_id();
    writeProgress($progressFile, ['found' => 0, 'saved' => 0, 'status' => 'Starting...']);

    $targetUrl  = filter_var($_POST['url'], FILTER_SANITIZE_URL);
    $limit      = max(10, min(200, (int)($_POST['limit'] ?? 50))); // clamp 10–200
    $targetHost = parse_url($targetUrl, PHP_URL_HOST);
    $bareHost   = preg_replace('/^www\./', '', $targetHost);

    if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
        header("Location: frontend.php?error=Invalid URL format.");
        exit;
    }

    $urlPath  = trim(parse_url($targetUrl, PHP_URL_PATH), '/');
    $segments = array_filter(explode('/', $urlPath));

    $camLinks = [];

    // -------------------------------------------------------------------------
    // Collect cam listing links (no individual cam page visits yet)
    // -------------------------------------------------------------------------
    if (count($segments) === 1 && reset($segments) !== 'category') {
        $camLinks[$targetUrl] = ['title' => titleFromSlug($targetUrl), 'country' => '', 'stream_url' => ''];

    } elseif (!empty($segments) && reset($segments) === 'category') {
        crawlCategoryFast($targetUrl, $bareHost, $camLinks, $limit, $stopFile, $progressFile);

    } else {
        $homeHtml = fetchOnePage($targetUrl);
        if (!$homeHtml) {
            header("Location: frontend.php?error=Cannot fetch homepage.");
            exit;
        }

        $categoryUrls = discoverLeafCategories($homeHtml, $bareHost);
        if (empty($categoryUrls)) {
            header("Location: frontend.php?error=No category links found.");
            exit;
        }

        writeProgress($progressFile, ['found' => 0, 'saved' => 0, 'status' => 'Fetching category pages...']);

        $extraPages   = [];
        $page1Results = fetchParallel($categoryUrls, 15);

        foreach ($page1Results as $catUrl => $html) {
            if (shouldStop($stopFile)) break;
            if (!$html) continue;
            extractCamCards($html, $bareHost, $camLinks);
            writeProgress($progressFile, ['found' => count($camLinks), 'saved' => 0, 'status' => 'Collecting links...']);
            if (count($camLinks) >= $limit) break;

            $maxPage = detectMaxPage($html);
            $base    = rtrim(preg_replace('#/page/\d+/?$#', '', $catUrl), '/');
            for ($p = 2; $p <= $maxPage; $p++) {
                $extraPages[] = $base . '/page/' . $p . '/';
            }
        }

        if (!shouldStop($stopFile) && count($camLinks) < $limit && !empty($extraPages)) {
            $extraResults = fetchParallel($extraPages, 15);
            foreach ($extraResults as $html) {
                if (shouldStop($stopFile)) break;
                if ($html) {
                    extractCamCards($html, $bareHost, $camLinks);
                    writeProgress($progressFile, ['found' => count($camLinks), 'saved' => 0, 'status' => 'Collecting links...']);
                }
                if (count($camLinks) >= $limit) break;
            }
        }
    }

    $camLinks = array_slice($camLinks, 0, $limit, true);

    writeProgress($progressFile, ['found' => count($camLinks), 'saved' => 0, 'status' => 'Fetching stream URLs...']);

    // -------------------------------------------------------------------------
    // NOW fetch individual cam pages for stream URLs — in parallel, with limit
    // -------------------------------------------------------------------------
    if (!shouldStop($stopFile)) {
        $urlsToFetch = array_keys($camLinks);
        $BATCH       = 10;
        for ($i = 0; $i < count($urlsToFetch); $i += $BATCH) {
            if (shouldStop($stopFile)) break;

            $batch     = array_slice($urlsToFetch, $i, $BATCH);
            $batchHtml = fetchParallel($batch, $BATCH);

            foreach ($batchHtml as $url => $html) {
                if (!$html) continue;
                $camLinks[$url]['stream_url'] = extractStreamUrl($html);
                if (empty($camLinks[$url]['title']))   $camLinks[$url]['title']   = extractTitle($html) ?: titleFromSlug($url);
                if (empty($camLinks[$url]['country'])) $camLinks[$url]['country'] = extractCountry($html);
            }

            $fetched = min($i + $BATCH, count($urlsToFetch));
            writeProgress($progressFile, [
                'found'  => count($camLinks),
                'saved'  => 0,
                'status' => "Fetching stream URLs... ($fetched / " . count($urlsToFetch) . ")"
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Save to DB
    // -------------------------------------------------------------------------
    writeProgress($progressFile, ['found' => count($camLinks), 'saved' => 0, 'status' => 'Saving to database...']);

    $pdo = getDBconnection();
    if (!$pdo) {
        header("Location: frontend.php?error=Database connection failed.");
        exit;
    }

    $allUrls      = array_keys($camLinks);
    $existingUrls = [];
    foreach (array_chunk($allUrls, 500) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT page_url FROM live_streams WHERE page_url IN ($ph)");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $u) $existingUrls[$u] = true;
    }

    $savedCount = $skippedCount = 0;
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO live_streams (title, page_url, stream_url, country) VALUES (:title,:page_url,:stream_url,:country)");
        foreach ($camLinks as $pageUrl => $meta) {
            if (isset($existingUrls[$pageUrl])) { $skippedCount++; continue; }
            $insert->execute([
                ':title'      => $meta['title']      ?: titleFromSlug($pageUrl),
                ':page_url'   => $pageUrl,
                ':stream_url' => $meta['stream_url'] ?? '',
                ':country'    => $meta['country']    ?? '',
            ]);
            $savedCount++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        header("Location: frontend.php?error=" . urlencode("DB Error: " . $e->getMessage()));
        exit;
    }

    $stopped = shouldStop($stopFile) ? ' (stopped early)' : '';
    writeProgress($progressFile, [
        'found'  => count($camLinks),
        'saved'  => $savedCount,
        'status' => 'done'
    ]);

    @unlink($stopFile);

    header("Location: frontend.php?success=" . urlencode("Done!$stopped $savedCount saved. $skippedCount skipped."));
    exit;

} else {
    header("Location: frontend.php");
    exit;
}


function shouldStop(string $stopFile): bool {
    return file_exists($stopFile);
}

function writeProgress(string $file, array $data): void {
    file_put_contents($file, json_encode($data));
}

function fetchParallel(array $urls, int $batchSize = 15): array
{
    $results = array_fill_keys($urls, false);
    foreach (array_chunk($urls, $batchSize) as $batch) {
        $mh = curl_multi_init();
        curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $batchSize);
        $handles = [];
        foreach ($batch as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept: text/html,*/*;q=0.8',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['handle' => $ch, 'url' => $url];
        }
        $running = null;
        $start   = time();
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
            if (time() - $start > 30) break;
        } while ($running > 0 && curl_multi_exec($mh, $running) === CURLM_OK);

        foreach ($handles as $info) {
            $ch   = $info['handle'];
            $url  = $info['url'];
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $results[$url] = ($code === 200 && $body) ? $body : false;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

function fetchOnePage(string $url, int $timeout = 15): string|false
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0','Accept: text/html,*/*;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : false;
}

function discoverLeafCategories(string $html, string $bareHost): array
{
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    $links    = (new DOMXPath($dom))->query('//a[@href]');
    $topLevel = ['americas','asia','africa','caribbean','oceania','europe','themes','time-travel'];
    $cats     = [];
    foreach ($links as $link) {
        $href = trim($link->getAttribute('href'));
        if (strpos($href, '//') === 0) $href = 'https:' . $href;
        if (strpos($href, 'http') !== 0) continue;
        $lHost = preg_replace('/^www\./', '', parse_url($href, PHP_URL_HOST) ?? '');
        if ($lHost !== $bareHost) continue;
        $path  = trim(parse_url($href, PHP_URL_PATH) ?? '', '/');
        $parts = explode('/', $path);
        if (($parts[0] ?? '') !== 'category') continue;
        if (count($parts) < 3) continue;
        if (count($parts) === 2 && in_array($parts[1] ?? '', $topLevel)) continue;
        $cats['https://' . $bareHost . '/' . $path . '/'] = true;
    }
    return array_keys($cats);
}

function crawlCategoryFast(string $url, string $bareHost, array &$camLinks, int $limit, string $stopFile, string $progressFile): void
{
    $base  = rtrim(preg_replace('#/page/\d+/?$#', '', $url), '/');
    $html1 = fetchOnePage($base . '/');
    if (!$html1) return;
    extractCamCards($html1, $bareHost, $camLinks);
    writeProgress($progressFile, ['found' => count($camLinks), 'saved' => 0, 'status' => 'Collecting links...']);
    if (count($camLinks) >= $limit || shouldStop($stopFile)) return;
    $max = detectMaxPage($html1);
    if ($max > 1) {
        $pages = [];
        for ($p = 2; $p <= $max; $p++) $pages[] = $base . '/page/' . $p . '/';
        foreach (fetchParallel($pages, 10) as $html) {
            if (shouldStop($stopFile) || count($camLinks) >= $limit) break;
            if ($html) extractCamCards($html, $bareHost, $camLinks);
        }
    }
}

function extractCamCards(string $html, string $bareHost, array &$camLinks): void
{
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $skipContains   = ['category','tag','page','author','feed','wp-','articles','about','contact','faq','privacy','terms','sitemap','search','embed','warmest','coldest'];
    $skipCountry    = ['themes','airports','beaches','mountains','lakes-and-rivers','ports','wildlife','railways','ski-slopes','surfing','birds','indoor','volcanoes','underwater','universities','zoo','markets','space','americas','europe','asia','africa','caribbean','oceania','time-travel','amusement-park','aurora-borealis','landscapes','world-heritage-sites','united-states'];
    $cards = $xpath->query('//article');
    if ($cards->length === 0) $cards = $xpath->query('//div[contains(@class,"post") or contains(@class,"cam") or contains(@class,"card")]');
    foreach ($cards as $card) {
        $camUrl = '';
        foreach ($xpath->query('.//a[@href]', $card) as $a) {
            $href = trim($a->getAttribute('href'));
            if (strpos($href, '//') === 0) $href = 'https:' . $href;
            if (strpos($href, 'http') !== 0) continue;
            $lHost = preg_replace('/^www\./', '', parse_url($href, PHP_URL_HOST) ?? '');
            if ($lHost !== $bareHost) continue;
            $path = trim(parse_url($href, PHP_URL_PATH) ?? '', '/');
            if (substr_count($path, '/') !== 0 || strlen($path) < 5) continue;
            $skip = false;
            foreach ($skipContains as $t) { if (stripos($path, $t) !== false) { $skip = true; break; } }
            if ($skip) continue;
            $camUrl = 'https://' . $bareHost . '/' . $path . '/';
            break;
        }
        if (empty($camUrl) || isset($camLinks[$camUrl])) continue;
        $title = '';
        foreach ($xpath->query('.//h2|.//h3|.//h4', $card) as $h) { $t = trim(strip_tags($h->textContent)); if ($t) { $title = $t; break; } }
        $country = '';
        foreach ($xpath->query('.//a[contains(@href,"/category/")]', $card) as $cl) {
            $catParts = array_reverse(array_values(array_filter(explode('/', trim(parse_url($cl->getAttribute('href'), PHP_URL_PATH) ?? '', '/')))));
            foreach ($catParts as $slug) {
                if ($slug === 'category') continue;
                if (!in_array($slug, $skipCountry) && strlen($slug) > 2) { $country = ucwords(str_replace('-', ' ', $slug)); break 2; }
            }
        }
        $camLinks[$camUrl] = ['title' => $title, 'country' => $country, 'stream_url' => ''];
    }
}

function detectMaxPage(string $html): int
{
    if (preg_match_all('#/page/(\d+)/#', $html, $m)) return min((int)max($m[1]), 20);
    return 1;
}

function extractStreamUrl(string $html): string
{
    $patterns = [
        '/["\']([^"\']*\.m3u8[^"\']*)["\']/',
        '/"hlsUrl"\s*:\s*"([^"]+\.m3u8[^"]*)"/',
        '/"streamUrl"\s*:\s*"([^"]+\.m3u8[^"]*)"/',
        '/"stream_url"\s*:\s*"([^"]+\.m3u8[^"]*)"/',
        '/"liveUrl"\s*:\s*"([^"]+\.m3u8[^"]*)"/',
        '/source\s+src=["\']([^"\']*\.m3u8[^"\']*)["\']/',
        '/["\']([^"\']*\.mp4[^"\']*)["\']/',
        '/"streamUrl"\s*:\s*"([^"]+)"/',
        '/"liveUrl"\s*:\s*"([^"]+)"/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) {
            $c = stripslashes($m[1]);
            if (filter_var($c, FILTER_VALIDATE_URL)) return $c;
        }
    }
    if (preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $iframes)) {
        $blocked = ['facebook.com','twitter.com','instagram.com','dailymotion.com','google.com/maps'];
        foreach ($iframes[1] as $src) {
            if (strpos($src, '//') === 0) $src = 'https:' . $src;
            if (!filter_var($src, FILTER_VALIDATE_URL)) continue;
            $iHost = parse_url($src, PHP_URL_HOST);
            if (str_contains($iHost, 'youtube') || str_contains($iHost, 'youtu.be')) {
                if (preg_match('#/embed/([a-zA-Z0-9_\-]{11})#', $src, $v))
                    return 'https://www.youtube.com/watch?v=' . $v[1];
                continue;
            }
            $bad = false;
            foreach ($blocked as $b) { if (str_contains($iHost, $b)) { $bad = true; break; } }
            if (!$bad) return $src;
        }
    }
    return '';
}

function extractTitle(string $html): string
{
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $m)) return trim(strip_tags($m[1]));
    if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m))
        return trim(preg_replace('/\s*[\|\-–—].*$/u', '', strip_tags($m[1])));
    return '';
}

function extractCountry(string $html): string
{
    $skip = ['themes','airports','beaches','mountains','lakes-and-rivers','ports','wildlife','railways','ski-slopes','surfing','birds','indoor','volcanoes','underwater','universities','zoo','markets','space','americas','europe','asia','africa','caribbean','oceania','time-travel','united-states'];
    if (preg_match_all('#/category/[^"\'<\s]+#', $html, $cats)) {
        foreach ($cats[0] as $catPath) {
            foreach (array_reverse(array_filter(explode('/', trim($catPath, '/')))) as $slug) {
                if (!in_array($slug, $skip) && strlen($slug) > 2)
                    return ucwords(str_replace('-', ' ', $slug));
            }
        }
    }
    return '';
}

function titleFromSlug(string $url): string
{
    $slug = end(explode('/', trim(parse_url($url, PHP_URL_PATH), '/')));
    return ucwords(str_replace('-', ' ', preg_replace('/-?(live-)?webcam$|-live-cam$|-cam$/i', '', $slug)));
}

?>
