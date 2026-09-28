<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Contracts;

/**
 * A local user that stands for an Accounting account under another primary key.
 */
interface AccountingIdentity
{
    /**
     * The account's id on the Accounting service: the "sub" of its access token.
     */
    public function accountingId(): string|int;
}
