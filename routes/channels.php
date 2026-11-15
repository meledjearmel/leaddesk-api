<?php

use App\Broadcasting\CompanyChannel;
use Illuminate\Support\Facades\Broadcast;

/*
| Canaux temps réel. L'application s'y abonne avec son jeton Sanctum via
| POST /api/v1/broadcasting/auth. Voir docs/api/README.md.
*/

Broadcast::channel('company.{companyId}', CompanyChannel::class);
