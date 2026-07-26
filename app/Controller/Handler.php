<?php
declare(strict_types=1);

namespace PV\Controller;

/** Every route target implements this. The Router calls handle() and nothing else. */
interface Handler
{
    public function handle(): void;
}
