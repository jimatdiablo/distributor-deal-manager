<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$failed = false;
foreach (['src', 'public', 'views', 'config', 'routes', 'scripts'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $file) {
        if ($file->getExtension() === 'php') {
            exec(PHP_BINARY . ' -l ' . escapeshellarg($file->getPathname()), $output, $code);
            if ($code !== 0) { echo implode("\n", $output) . "\n"; $failed = true; }
            $output = [];
        }
    }
}
echo $failed ? "PHP lint failed\n" : "PHP lint passed\n";
exit($failed ? 1 : 0);
