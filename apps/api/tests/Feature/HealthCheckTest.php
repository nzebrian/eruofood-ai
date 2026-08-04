<?php

declare(strict_types=1);

// Framework-level smoke test: the built-in Laravel health endpoint responds.
it('exposes the framework health endpoint', function (): void {
    $this->get('/up')->assertOk();
});
