<?php

// Temporary: proves that a failing test makes CI red (task:47). Reverted in the next commit.
test('CI turns red on a failing test', function () {
    expect(true)->toBeFalse();
});
