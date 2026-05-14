<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * Renders an {@see InspectionReport} as a list of plain text lines with
 * Symfony OutputFormatter colour tags embedded for the status chips.
 *
 * Each skill row starts with a colour-coded status chip — a coloured
 * background block in a TTY, plain text when output is undecorated
 * (`--no-ansi`, redirected to a file, …):
 *
 *     Target: <absolute-target-path>
 *
 *     <vendor>/
 *       <package-name>                         <source>     [via built-in trust]
 *         <chip>  <skill-name>                 <description>[ (modified)| with <pkg>]
 *
 *     Skipped:
 *       <chip>  <package>     <reason>[: <detail>]
 *
 * Each chip code is surrounded by one space inside the coloured block,
 * and the whole chip is right-padded to a uniform visible width so the
 * column after the chip starts at the same offset on every row:
 *
 *   ` OK `   InSync   — green bg, bold
 *   ` NEW`   NotSynced — cyan bg, bold
 *   ` MOD`   Drift     — yellow bg, bold
 *   ` !! `   Conflict  — red bg, bold
 *
 * In the `Skipped:` section the chip carries the *reason* itself rather
 * than a generic SKIP — the section header already says "skipped", so a
 * dedicated chip would be redundant. Reason chips are coloured by
 * severity: red for vendor breakage (malformed / source-missing),
 * yellow for trust-required cases (untrusted / untrusted-named), white
 * for self-imposed exclusions (filtered-out), cyan for informational
 * cases (not-declared).
 *
 * The `[via built-in trust]` annotation is shown only when the donor was
 * approved by the built-in list alone (neither project config nor
 * `--trust=` covers it) — keeps noise low for the common case where the
 * user explicitly configured the trust.
 *
 * Returns a `list<string>` rather than printing directly so the runner
 * can route lines to either stdout or stderr as it sees fit.
 *
 * @psalm-immutable
 */
final readonly class ReportFormatter
{
    /**
     * Chip definitions: each code is wrapped in a Symfony OutputFormatter
     * colour tag and surrounded by one space on each side so the code
     * "breathes" inside the coloured block. Codes have different lengths
     * (`OK`, `NEW`, `MOD`, `!!`, `SKIP`), so plain trailing whitespace
     * pads each chip to the same visible width (6 chars) — that way the
     * following column starts at the same offset regardless of which
     * status this row carries.
     *
     * Bold is enabled on the actionable chips and dropped on `SKIP` so
     * skipped donors visually recede from the main listing.
     */
    private const CHIP_OK = '<bg=green;fg=black;options=bold> OK </>  ';

    private const CHIP_NEW = '<bg=cyan;fg=black;options=bold> NEW </> ';
    private const CHIP_MOD = '<bg=yellow;fg=black;options=bold> MOD </> ';
    private const CHIP_CONFLICT = '<bg=red;fg=white;options=bold> !! </>  ';

    /**
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    public function format(InspectionReport $report): array
    {
        $main = $this->formatMain($report);
        $skipped = $report->skipped !== []
            ? $this->formatSkipped($report->skipped)
            : [];

        $hint = $this->formatDiscoveryHint($report);

        if ($main === [] && $skipped === [] && $hint === []) {
            return ['No donor packages found.'];
        }

        // Target header — single line at the top so the per-skill rows
        // can use the second column for description rather than path.
        $lines = ['Target: ' . (string) $report->target, ''];
        if ($main !== []) {
            $lines = [...$lines, ...$main];
        }
        if ($skipped !== []) {
            if ($main !== []) {
                $lines[] = '';
            }
            $lines = [...$lines, ...$skipped];
        }
        if ($hint !== []) {
            $lines[] = '';
            $lines = [...$lines, ...$hint];
        }

        return $lines;
    }

    /**
     * @return list<string>
     *
     * @psalm-pure
     */
    private function formatDiscoveryHint(InspectionReport $report): array
    {
        if ($report->discoveryActive || $report->undeclaredCandidatesCount === 0) {
            return [];
        }

        return [\sprintf(
            '<comment>[hint] %d package(s) ship undeclared skills under skills/. '
            . 'Rerun with --discovery (-d) to include them, or set extra.skills.discovery: true.</comment>',
            $report->undeclaredCandidatesCount,
        )];
    }

    /**
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private function formatMain(InspectionReport $report): array
    {
        if ($report->donors === []) {
            return [];
        }

        // Group by vendor (the part before the first `/`). Group order is
        // discovery order — first donor of a vendor decides the vendor's slot.
        $grouped = [];
        foreach ($report->donors as $donor) {
            [$vendor] = $this->splitName($donor->donor->packageName);
            $grouped[$vendor][] = $donor;
        }

        $lines = [];
        $firstVendor = true;
        foreach ($grouped as $vendor => $donors) {
            if (!$firstVendor) {
                $lines[] = '';
            }
            $firstVendor = false;
            $lines[] = '<fg=cyan>' . $vendor . '/</>';

            foreach ($donors as $donor) {
                [, $pkgTail] = $this->splitName($donor->donor->packageName);
                $trustNote = $donor->trustSource === TrustSource::Builtin
                    ? '    [via built-in trust]'
                    : '';
                $discoveredNote = $donor->donor->discovered
                    ? '  <fg=magenta>[discovered]</>'
                    : '';
                // str_pad on the plain text inside the colour tag keeps
                // visible-width alignment correct — sprintf %-Ns would
                // measure the tag bytes as part of the field and break it.
                $lines[] = '  <fg=cyan>' . \str_pad($pkgTail, 40) . '</> '
                    . $donor->donor->source . $discoveredNote . $trustNote;

                foreach ($donor->skills as $skill) {
                    $lines[] = $this->formatSkillLine($skill);
                }
            }
        }

        return $lines;
    }

    /**
     * @param list<SkippedDonor> $skipped
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private function formatSkipped(array $skipped): array
    {
        // Compute the chip slot's visible width from the set of reasons
        // that actually appear — this keeps the column tight when only
        // one reason is in play and prevents " not-declared " padding
        // from inflating an output that only has malformed donors.
        $chipWidth = 0;
        foreach ($skipped as $row) {
            $w = \strlen($row->reason->value) + 2;
            if ($w > $chipWidth) {
                $chipWidth = $w;
            }
        }

        $lines = ['Skipped:'];
        foreach ($skipped as $row) {
            $code = $row->reason->value;
            $chipInner = ' ' . $code . ' ';
            $chip = $this->skipReasonOpen($row->reason) . $chipInner . '</>';
            $chipPad = \str_repeat(' ', $chipWidth - \strlen($chipInner));

            // The package name shares the cyan colour cue with the main
            // listing's vendor/package rows, so the visual rhyme makes
            // it scannable across sections.
            $name = '<fg=cyan>' . \str_pad($row->packageName, 30) . '</>';

            $detail = $row->detail !== null && $row->detail !== ''
                ? '  ' . $row->detail
                : '';

            $lines[] = '  ' . $chip . $chipPad . '  ' . $name . $detail;
        }

        return $lines;
    }

    /**
     * Pick the chip opener tag for a skip reason. Returns just the
     * opening `<bg=…;fg=…;options=…>` portion; the caller appends the
     * chip's visible text and the closing `</>`. Splitting it this way
     * keeps the call site readable and concentrates the colour table.
     *
     * @psalm-pure
     */
    private function skipReasonOpen(SkipReason $reason): string
    {
        return match ($reason) {
            // Vendor's broken — loud red.
            SkipReason::Malformed,
            SkipReason::SourceMissing => '<bg=red;fg=white;options=bold>',
            // Trust decision required — yellow warning.
            SkipReason::Untrusted => '<bg=yellow;fg=black;options=bold>',
            // User self-excluded — muted neutral.
            SkipReason::FilteredOut => '<bg=white;fg=black>',
            // Auto-discovery candidate the user has not opted in to — cyan
            // to differentiate from anything action-required.
            SkipReason::NotDeclared => '<bg=cyan;fg=black>',
        };
    }

    /**
     * @psalm-pure
     */
    private function formatSkillLine(SkillInspection $skill): string
    {
        // A conflicting skill overrides the byte-level state: the next
        // sync aborts before touching the filesystem, so a green chip
        // would be misleading even if the local copy happens to match.
        if ($skill->conflictWith !== null) {
            $chip = self::CHIP_CONFLICT;
            $suffix = \sprintf(' with %s', $skill->conflictWith);
        } else {
            $chip = match ($skill->status) {
                SyncStatus::InSync => self::CHIP_OK,
                SyncStatus::NotSynced => self::CHIP_NEW,
                SyncStatus::Drift => self::CHIP_MOD,
            };
            $suffix = $skill->status === SyncStatus::Drift ? ' (modified)' : '';
        }

        // Second column is the donor-supplied description (from the
        // skill's `SKILL.md` frontmatter). Skills without frontmatter
        // show an empty description column — still aligned, so a reader
        // can scan the column instead of every row's tail.
        $description = $skill->description ?? '';

        // sprintf %-Ns would break alignment when $chip contains formatter
        // tags (string length differs from visible length), so the chip
        // and name are emitted verbatim and str_pad only acts on the
        // plain skill-name portion.
        return '    ' . $chip . '  '
            . \str_pad($skill->skill->name, 28) . '  '
            . $description
            . ($suffix === '' ? '' : ' ' . $suffix);
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @psalm-pure
     */
    private function splitName(string $packageName): array
    {
        $slashAt = \strpos($packageName, '/');
        if ($slashAt === false) {
            return [$packageName, ''];
        }

        return [\substr($packageName, 0, $slashAt), \substr($packageName, $slashAt + 1)];
    }
}
