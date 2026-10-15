<?php
// backend/tests/test_product_video_stream.php
// Standalone check (php backend/tests/test_product_video_stream.php; exit 0 = PASS) of
// SanPhamController::streamProductVideo against a stub AI Idol (Flask) server. It starts throwaway
// `php -S` servers on 127.0.0.1, so it needs no Docker stack, MongoDB or network.

declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/SanPhamController.php';

$R2_BASE = 'https://pub-test.r2.dev';
$ID_PLAIN = 'aaaaaaaaaaaaaaaaaaaaaaaa';   // Flask streams the file (200 / 206)
$ID_R2 = 'bbbbbbbbbbbbbbbbbbbbbbbb';      // Flask redirects to the R2 copy
$ID_FOREIGN = 'cccccccccccccccccccccccc'; // Flask redirects to another host
$ID_LOOKALIKE = 'dddddddddddddddddddddddd'; // Flask redirects to "<r2 host>.evil.example"
$ID_MISSING = 'eeeeeeeeeeeeeeeeeeeeeeee'; // Flask answers 404

$failures = 0;
function check(string $label, bool $condition): void {
    global $failures;
    echo ($condition ? '  [PASS] ' : '  [FAIL] ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// ---------------------------------------------------------------- redirect target rules (pure function)
echo "productVideoRedirectTarget\n";
$target = static fn(string $location, string $base): ?string => SanPhamController::productVideoRedirectTarget($location, $base);
check('R2 file is allowed', $target("$R2_BASE/product-videos/x.mp4", $R2_BASE) === "$R2_BASE/product-videos/x.mp4");
check('trailing slash on the configured base is ignored', $target("$R2_BASE/product-videos/x.mp4", "$R2_BASE/") === "$R2_BASE/product-videos/x.mp4");
check('another host is rejected', $target('https://evil.example/x.mp4', $R2_BASE) === null);
check('lookalike host is rejected', $target("$R2_BASE.evil.example/x.mp4", $R2_BASE) === null);
check('userinfo trick is rejected', $target("https://pub-test.r2.dev@evil.example/x.mp4", $R2_BASE) === null);
check('bare base without a path is rejected', $target($R2_BASE, $R2_BASE) === null);
check('R2 not configured rejects everything', $target("$R2_BASE/x.mp4", '') === null);
check('empty location is rejected', $target('', $R2_BASE) === null);
check('header injection is rejected', $target("$R2_BASE/x.mp4\r\nSet-Cookie: a=b", $R2_BASE) === null);
check('whitespace in the target is rejected', $target("$R2_BASE/a b.mp4", $R2_BASE) === null);

// ---------------------------------------------------------------- stub servers
$dir = sys_get_temp_dir() . '/skinsyntax-stream-test-' . bin2hex(random_bytes(4));
mkdir($dir);
$requestLog = "$dir/requests.log";

file_put_contents("$dir/flask.php", <<<'PHP'
<?php
// Stub for GET /api/ai-idol/videos/<id>?rendition=product_page
file_put_contents(getenv('REQUEST_LOG'), $_SERVER['REQUEST_URI'] . ' range=' . ($_SERVER['HTTP_RANGE'] ?? '-') . "\n", FILE_APPEND);
$id = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$base = getenv('R2_BASE');
switch ($id) {
    case 'bbbbbbbbbbbbbbbbbbbbbbbb':
        header('Location: ' . $base . '/product-videos/b.mp4', true, 302);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>Redirecting to the R2 copy</p>';
        break;
    case 'cccccccccccccccccccccccc':
        header('Location: https://evil.example/c.mp4', true, 302);
        echo '<p>Redirecting elsewhere</p>';
        break;
    case 'dddddddddddddddddddddddd':
        header('Location: ' . $base . '.evil.example/d.mp4', true, 302);
        echo '<p>Redirecting to a lookalike</p>';
        break;
    case 'aaaaaaaaaaaaaaaaaaaaaaaa':
        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        if (($_SERVER['HTTP_RANGE'] ?? '') === 'bytes=2-5') {
            http_response_code(206);
            header('Content-Range: bytes 2-5/10');
            header('Content-Length: 4');
            echo '2345';
        } else {
            header('Content-Length: 10');
            echo '0123456789';
        }
        break;
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"ok": false}';
}
PHP);

file_put_contents("$dir/site.php", <<<'PHP'
<?php
// Harness: the real SanPhamController::streamProductVideo with a fake database that publishes every id.
require_once getenv('CONTROLLER_FILE');

class StubCollection {
    public function createIndex(...$args) {}
    public function findOne($filter) {
        $id = (string)$filter['_id'];
        return ['_id' => $id, 'video_id' => $id, 'is_published' => true, 'associated_product_ids' => ['93']];
    }
}
class StubDb {
    public function __get($name) { return new StubCollection(); }
}

(new SanPhamController(new StubDb()))->streamProductVideo();
PHP);

function free_port(): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int)explode(':', stream_socket_get_name($socket, false))[1];
    fclose($socket);
    return $port;
}

$processes = [];
function start_server(string $router, array $env, string $dir, array &$processes): int {
    $port = free_port();
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
        [0 => ['pipe', 'r'], 1 => ['file', "$dir/server-$port.out", 'w'], 2 => ['file', "$dir/server-$port.err", 'w']],
        $pipes,
        $dir,
        array_merge(getenv(), $env)
    );
    $processes[] = $process;
    for ($i = 0; $i < 100; $i++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($connection) {
            fclose($connection);
            return $port;
        }
        usleep(100000);
    }
    fwrite(STDERR, "server on port $port did not start\n");
    exit(2);
}

$flaskPort = start_server("$dir/flask.php", ['REQUEST_LOG' => $requestLog, 'R2_BASE' => $R2_BASE], $dir, $processes);
$withR2 = start_server("$dir/site.php", [
    'CONTROLLER_FILE' => realpath(__DIR__ . '/../app/controllers/SanPhamController.php'),
    'AI_IDOL_ENDPOINT' => "http://127.0.0.1:$flaskPort",
    'R2_PUBLIC_BASE_URL' => $R2_BASE,
], $dir, $processes);
$withoutR2 = start_server("$dir/site.php", [
    'CONTROLLER_FILE' => realpath(__DIR__ . '/../app/controllers/SanPhamController.php'),
    'AI_IDOL_ENDPOINT' => "http://127.0.0.1:$flaskPort",
    'R2_PUBLIC_BASE_URL' => '',
], $dir, $processes);

function fetch(int $port, string $id, array $headers = []): array {
    $ch = curl_init("http://127.0.0.1:$port/index.php?r=product_video_stream&id=$id");
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
}

echo "streamProductVideo\n";
$plain = fetch($withR2, $ID_PLAIN);
check('plain stream: 200 with the file bytes', $plain['status'] === 200 && $plain['body'] === '0123456789');
check('plain stream keeps the video headers', ($plain['headers']['content-type'] ?? '') === 'video/mp4' && ($plain['headers']['accept-ranges'] ?? '') === 'bytes');

$ranged = fetch($withR2, $ID_PLAIN, ['Range: bytes=2-5']);
check('range request: 206 with the requested slice', $ranged['status'] === 206 && $ranged['body'] === '2345');
check('range request keeps Content-Range', ($ranged['headers']['content-range'] ?? '') === 'bytes 2-5/10');

$redirected = fetch($withR2, $ID_R2);
check('R2 redirect: browser gets 302 to the R2 file', $redirected['status'] === 302 && ($redirected['headers']['location'] ?? '') === "$R2_BASE/product-videos/b.mp4");
check('R2 redirect: Flask redirect body is not leaked', $redirected['body'] === '');

$foreign = fetch($withR2, $ID_FOREIGN);
check('redirect to another host: 404, no Location', $foreign['status'] === 404 && !isset($foreign['headers']['location']));
check('redirect to another host: Flask body is not leaked', !str_contains($foreign['body'], 'Redirecting'));

$lookalike = fetch($withR2, $ID_LOOKALIKE);
check('redirect to a lookalike host: 404, no Location', $lookalike['status'] === 404 && !isset($lookalike['headers']['location']));

$unconfigured = fetch($withoutR2, $ID_R2);
check('R2 not configured: even the R2 redirect is 404', $unconfigured['status'] === 404 && !isset($unconfigured['headers']['location']));

$missing = fetch($withR2, $ID_MISSING);
check('Flask 404 is passed through', $missing['status'] === 404);

$log = (string)file_get_contents($requestLog);
check('every request to Flask asks for the product_page rendition', substr_count($log, 'rendition=product_page') === substr_count($log, "\n") && $log !== '');

// ---------------------------------------------------------------- cleanup
foreach ($processes as $process) {
    proc_terminate($process);
    proc_close($process);
}
foreach (glob("$dir/*") ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

echo $failures === 0 ? "\nPASS\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
