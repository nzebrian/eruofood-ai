<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Environment;

/**
 * How badly the platform should react to a finding.
 *
 * `Error` means the configuration is unsafe to run: the verifier exits non-zero
 * and a deploy gated on it stops. `Warning` means the configuration is legal but
 * worth an operator's attention — a separation that currently holds by accident
 * rather than by rule, for instance.
 *
 * There is no `Info`. A validator that emits information nobody must act on
 * trains its readers to skim it.
 */
enum FindingSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
