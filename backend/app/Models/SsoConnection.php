<?php

namespace App\Models;

/** An organization's OpenID Connect identity provider. The client secret is encrypted and never returned by the API. */
class SsoConnection extends TenantModel
{
    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'client_secret'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'allowed_domains' => 'array', 'enabled' => 'boolean', 'enforced' => 'boolean', 'version' => 'integer'];
    }

    public function allowsEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, array_map('strtolower', $this->allowed_domains ?? []), true);
    }
}
