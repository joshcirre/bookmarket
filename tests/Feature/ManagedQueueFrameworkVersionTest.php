<?php

use Illuminate\Foundation\Application;

test('Laravel version supports managed queues', function (): void {
    expect(Application::VERSION)->toStartWith('12.');
    expect(version_compare(Application::VERSION, '12.63.0', '>='))->toBeTrue();
});
