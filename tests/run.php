<?php
/**
 * Runs every tests/*Test.php file. Each file returns an array of
 * name => callable; a test fails by throwing. Exit code 1 on any failure.
 *
 *   php tests/run.php
 */
require __DIR__ . '/bootstrap.php';

/**
 * @param mixed $expected
 * @param mixed $actual
 * @param string $message
 */
function assert_same($expected, $actual, $message = '')
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\n      expected %s\n      actual   %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

/**
 * @param bool $condition
 * @param string $message
 */
function assert_true($condition, $message = '')
{
    assert_same(true, (bool) $condition, $message);
}

$failures = 0;
$count = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $tests = require $file;
    foreach ($tests as $name => $test) {
        ++$count;
        Configuration::$values = array();
        try {
            $test();
            echo "  ok    " . basename($file, '.php') . " › $name\n";
        } catch (Exception $e) {
            ++$failures;
            echo "  FAIL  " . basename($file, '.php') . " › $name\n      " . $e->getMessage() . "\n";
        }
    }
}

// remove the scratch cache directory
$dir = CITECUE_TEST_DIR;
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

echo sprintf("\n%d test(s), %d failure(s)\n", $count, $failures);
exit($failures > 0 ? 1 : 0);
