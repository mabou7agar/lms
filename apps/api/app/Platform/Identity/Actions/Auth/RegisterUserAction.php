<?php

namespace App\Platform\Identity\Actions\Auth;

use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Events\UserRegistered;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Enterprise\Contracts\CompanyRegistrationPort;
use App\Platform\Shared\Enterprise\Data\CompanyRegistrationInput;

/**
 * Registers a personal account, or a company account and the organization behind it.
 *
 * A company registration creates the organization, makes the registering user its OWNER and stamps
 * `organization_id` on the user so the tenant resolves. Manager authority then follows from that
 * membership through ManagerScope — no extra role is assigned, because inventing one would create a
 * second authorization path beside the one the enterprise portal already trusts.
 *
 * The organization is created through a Shared port; Identity never imports a CRM model.
 */
class RegisterUserAction extends BaseAction
{
    public function __construct(
        private readonly CompanyRegistrationPort $companies,
    ) {}

    /**
     * @param  array{
     *     name: string, email: string, password: string, phone?: ?string, locale?: ?string,
     *     account_type?: ?string, company?: ?array<string, mixed>
     * }  $data
     */
    public function execute(array $data): User
    {
        $isCompany = ($data['account_type'] ?? 'personal') === 'company';

        $user = $this->transaction(function () use ($data, $isCompany): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'en',
                'is_active' => true,
            ]);

            // Mark the account as password-capable (distinguishes it from a social-only account, which
            // leaves this null). Read by unlink orphan-safety.
            $user->forceFill(['password_set_at' => now()])->save();

            $user->profile()->create([]);
            $user->assignRole(Role::Student->value);

            if ($isCompany) {
                $company = $data['company'] ?? [];

                $organizationId = $this->companies->registerCompany(
                    new CompanyRegistrationInput(
                        name: (string) ($company['name'] ?? ''),
                        size: $this->nullableString($company['size'] ?? null),
                        country: $this->nullableString($company['country'] ?? null),
                        industry: $this->nullableString($company['industry'] ?? null),
                        phone: $this->nullableString($company['phone'] ?? null),
                        taxId: $this->nullableString($company['tax_id'] ?? null),
                        billingAddress: $this->nullableString($company['billing_address'] ?? null),
                        website: $this->nullableString($company['website'] ?? null),
                        locale: $data['locale'] ?? 'en',
                    ),
                    (int) $user->id,
                    (string) $user->email,
                );

                // Resolves the tenant for this user on every later request.
                $user->forceFill(['organization_id' => $organizationId])->save();
            }

            return $user;
        });

        UserRegistered::dispatch($user);

        return $user;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
