<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    require_once $testFile;
}

$classes = array_filter(get_declared_classes(), static function (string $class): bool {
    return is_subclass_of($class, GitupTestCase::class);
});

$results = [
    'passed' => 0,
    'failed' => 0,
];

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test')
    );

    foreach ($methods as $method) {
        $instance = $reflection->newInstance();
        $label = $reflection->getShortName() . '::' . $method->getName();

        try {
            $instance->setUp();
            $method->invoke($instance);
            $results['passed']++;
            echo "PASS {$label}\n";
        } catch (Throwable $e) {
            $results['failed']++;
            echo "FAIL {$label}\n";
            echo '  ' . $e->getMessage() . "\n";
        }
    }
}

echo "\n";
echo 'Passed: ' . $results['passed'] . "\n";
echo 'Failed: ' . $results['failed'] . "\n";

exit($results['failed'] > 0 ? 1 : 0);
