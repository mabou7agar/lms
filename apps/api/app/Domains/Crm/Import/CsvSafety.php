<?php

namespace App\Domains\Crm\Import;

/**
 * Spreadsheet formula-injection defense, shared by the import parser and the export writer.
 *
 * A cell whose first character is one of = + - @ (the spreadsheet formula triggers, incl. the
 * leading-tab / carriage-return variants some clients emit) is prefixed with a single quote so a
 * spreadsheet renders it as literal text and never executes it. This is applied to EVERY cell — on
 * import so an injected payload can neither execute nor slip through as a valid value (a neutralized
 * email fails validation and is rejected), and on export so a value an org stored is never weaponized
 * when their BI tool opens the produced CSV.
 */
trait CsvSafety
{
    /** @var list<string> The spreadsheet formula-injection trigger characters. */
    private const INJECTION_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    protected function neutralize(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::INJECTION_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }
}
