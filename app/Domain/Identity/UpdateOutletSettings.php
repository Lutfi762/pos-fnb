<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Audit\Audit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;

/**
 * What an outlet prints and charges.
 *
 * The outlet's face (name, address, receipt lines, whether the kitchen has a
 * screen) is a manager's call. What a guest pays on top of the menu price is
 * the owner's: tax, service, and rounding change every bill from the next one
 * on, so only an owner may touch them. Bills already saved keep their totals.
 */
class UpdateOutletSettings
{
    /** Rounding steps a cash payment can be rounded to. 0 turns it off. */
    public const ROUNDING = [0, 100, 500, 1000];

    public const MAX_TAX_PERCENT = 25;

    public const MAX_SERVICE_PERCENT = 25;

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{name: string, phone: ?string, address: ?string, receipt_header: ?string, receipt_footer: ?string, has_kitchen_display: bool}  $input
     */
    public function profile(Outlet $outlet, array $input, User $by): Outlet
    {
        abort_unless(SaveStaff::roleOf($by, $outlet)?->managesOutlet() === true, 403);

        return $this->apply($outlet, 'outlet.profile_changed', [
            'name' => trim($input['name']),
            'phone' => self::blankToNull($input['phone']),
            'address' => self::blankToNull($input['address']),
            'receipt_header' => self::blankToNull($input['receipt_header']),
            'receipt_footer' => self::blankToNull($input['receipt_footer']),
            'has_kitchen_display' => $input['has_kitchen_display'],
        ], $by);
    }

    /**
     * @param  array{tax_percent: string|float, service_charge_percent: string|float, price_includes_tax: bool, cash_rounding: int}  $input
     */
    public function pricing(Outlet $outlet, array $input, User $by): Outlet
    {
        abort_unless(SaveStaff::roleOf($by, $outlet) === Role::Owner, 403);

        $tax = round((float) $input['tax_percent'], 2);
        $service = round((float) $input['service_charge_percent'], 2);

        abort_unless($tax >= 0 && $tax <= self::MAX_TAX_PERCENT, 422, __('outlet.settings.tax_invalid'));
        abort_unless($service >= 0 && $service <= self::MAX_SERVICE_PERCENT, 422, __('outlet.settings.service_invalid'));
        abort_unless(in_array($input['cash_rounding'], self::ROUNDING, true), 422, __('outlet.settings.rounding_invalid'));

        return $this->apply($outlet, 'outlet.pricing_changed', [
            'tax_percent' => number_format($tax, 2, '.', ''),
            'service_charge_percent' => number_format($service, 2, '.', ''),
            'price_includes_tax' => $input['price_includes_tax'],
            'cash_rounding' => $input['cash_rounding'],
        ], $by);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function apply(Outlet $outlet, string $action, array $changes, User $by): Outlet
    {
        $before = array_intersect_key($outlet->getAttributes(), $changes);

        $outlet->forceFill($changes)->save();

        $this->audit->record($action, $outlet, $before, $changes, user: $by, outletId: $outlet->getKey());

        return $outlet;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
