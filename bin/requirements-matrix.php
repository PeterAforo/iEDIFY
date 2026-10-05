<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/docs/iEDIFY_Devin_Master_Build_Prompt.md';
$output = $root . '/docs/REQUIREMENTS_MATRIX.md';
if (is_file($output) && !in_array('--refresh', $argv, true)) {
    fwrite(STDERR, "Matrix already exists. Review changes before explicitly refreshing it.\n");
    exit(1);
}
$modules = [
    1 => 'All phases', 2 => 'CMS/Media/Imports', 3 => 'Core/Operations', 4 => 'Design system',
    5 => 'Public/CMS/Enquiries/Newsletter', 6 => 'CMS/Media', 7 => 'Identity/Policies',
    8 => 'Programs/Applications/Participants', 9 => 'Learning/Mentorship/Startups', 10 => 'Funding',
    11 => 'Impact', 12 => 'Partners', 13 => 'Community/Events/Opportunities',
    14 => 'Notifications/Search/Exports', 15 => 'Database', 16 => 'Security/Privacy/Operations',
    17 => 'SEO/Accessibility/Performance', 18 => 'Migration/Hosting', 19 => 'Private handover',
    20 => 'Build tracking', 21 => 'Acceptance tests', 22 => 'Final delivery',
];
$lines = file($source, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    throw new RuntimeException('Master prompt is unavailable.');
}
$text = "# Requirements matrix\n\nEvery nonempty requirement paragraph/bullet in master-prompt sections 1–22 is preserved below. IDs use the original source line, keeping traceability stable while the source remains unchanged. Implementation, verification and activation are separate. Foundation components alone do not complete a whole requirement.\n\nInitial status is conservative: `not_started` until evidence is linked. `docs/BUILD_STATUS.md` records current slice-level results. During implementation expand paragraphs into child acceptance checks and update routes/services, migration and test evidence in this table; never replace missing implementation with an external dependency label. Do not regenerate over manually maintained evidence without review.\n\n| ID / source line | Requirement | Owner module | Implementation | Routes/services; migration | Tests/evidence or precise dependency |\n|---|---|---|---|---|---|\n";
$evidencePath = $root . '/docs/requirements-evidence.json';
$evidence = is_file($evidencePath) ? json_decode((string) file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR) : [];
$section = 0;
$count = 0;
foreach ($lines as $offset => $line) {
    if (preg_match('/^## (\d+)\./', $line, $match)) {
        $section = (int) $match[1];
        continue;
    }
    if (str_starts_with($line, '## ') || $line === '---') {
        $section = 0;
    }
    if ($section < 1 || $section > 22 || trim($line) === '') {
        continue;
    }
    $number = $offset + 1;
    $id = sprintf('REQ-%02d-L%03d', $section, $number);
    $requirement = str_replace('|', '\\|', trim($line));
    $entry = $evidence[$id] ?? ['status' => 'not_started', 'implementation' => 'Pending implementation', 'evidence' => 'Pending verification'];
    $entry = array_map(static fn (string $value): string => str_replace('|', '\\|', $value), $entry);
    $text .= "| {$id} | {$requirement} | {$modules[$section]} | {$entry['status']} | {$entry['implementation']} | {$entry['evidence']} |\n";
    $count++;
}
file_put_contents($output, $text);
fwrite(STDOUT, "Tracked {$count} source requirement paragraphs/bullets across all 22 sections.\n");
