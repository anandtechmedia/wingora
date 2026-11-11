<?php

namespace App\Models;

use App\Models\Concerns\BillingAudited;

/** An invoice mirrored from Stripe. Amounts are minor units; the PDF and payment page stay on Stripe. */
class Invoice extends TenantModel
{
    use BillingAudited;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'stripe_synced_at'];

    /** Hosted invoice links are capability URLs, so they are kept out of the audit trail. */
    protected array $auditRedacted = ['hosted_invoice_url', 'invoice_pdf_url'];

    protected function casts(): array
    {
        return ['amount_due' => 'integer', 'amount_paid' => 'integer', 'period_start' => 'datetime', 'period_end' => 'datetime',
            'issued_at' => 'datetime', 'paid_at' => 'datetime', 'stripe_synced_at' => 'integer'];
    }
}
