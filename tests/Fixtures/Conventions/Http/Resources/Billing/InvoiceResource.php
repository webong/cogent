<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Conventions\Http\Resources\Billing;

use Illuminate\Http\Resources\Json\JsonResource;
use Webong\Cogent\Concerns\PreparesResources;

final class InvoiceResource extends JsonResource
{
    use PreparesResources;
}
