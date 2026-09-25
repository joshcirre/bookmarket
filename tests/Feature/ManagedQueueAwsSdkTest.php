<?php

use Aws\Sqs\SqsClient;

test('SQS client is available for managed queues', function (): void {
    expect(class_exists(SqsClient::class))->toBeTrue();
});
