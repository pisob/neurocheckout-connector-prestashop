<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Community/AutomaticSourceBinding.php';

use NeuroCheckout\Community\AutomaticSourceBinding;

$expected = hash_hmac('sha256', "neurocheckout-community-source-v1\0euroka", 'nc_live_secret');
if (AutomaticSourceBinding::secret('nc_live_secret', 'euroka') !== $expected) {
    fwrite(STDERR, "automatic source binding mismatch\n");
    exit(1);
}
if (AutomaticSourceBinding::secret('nc_live_secret', 'another') === $expected) {
    fwrite(STDERR, "source binding is not shop scoped\n");
    exit(1);
}
foreach ([['', 'euroka'], ['key', '../euroka'], ['key', '']] as $invalid) {
    try {
        AutomaticSourceBinding::secret($invalid[0], $invalid[1]);
        fwrite(STDERR, "invalid source binding accepted\n");
        exit(1);
    } catch (RuntimeException $expectedError) {
        // Expected.
    }
}
echo "automatic source binding test passed\n";
