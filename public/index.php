<?php

/**
 * Title X Report: which visits count, then the year's FPAR 2.0 file, what it refuses and what to fix first.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\TitleXReport\Http\Guide;
use OpenHealthcare\TitleXReport\Http\Request;
use OpenHealthcare\TitleXReport\Reference\Fpar;
use OpenHealthcare\TitleXReport\Reference\PovertyGuidelines;
use OpenHealthcare\TitleXReport\Report\PriorYear;
use OpenHealthcare\TitleXReport\Report\Problems;
use OpenHealthcare\TitleXReport\Report\TableChecks;
use OpenHealthcare\TitleXReport\Report\Tables;
use OpenHealthcare\TitleXReport\Report\TablesBuilder;
use OpenHealthcare\TitleXReport\Report\TitleXReport;
use OpenHealthcare\TitleXReport\Repository\EncounterSource;
use OpenHealthcare\TitleXReport\Repository\Settings;
use OpenHealthcare\TitleXReport\Http\Page;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('patients', 'demo')) {
    OpenEmr::deny('Title X Report: page refused', xl('Title X Report'), 'Title X Report');
}
$request = Request::get();
$settings = new Settings();
$source = new EncounterSource();
$saved = false;
if ($request->isMethod('POST') && Request::text($request->request->all(), 'action') === 'settings') {
    OpenEmr::requireCsrf(Request::text($request->request->all(), 'csrf_token_form'));
    $settings->save(array_map('intval', $request->request->all('categories')), array_map('intval', $request->request->all('facilities')), Request::text($request->request->all(), 'region'), (int)OpenEmr::session('authUserID'));
    OpenEmr::audit('other', 'Title X Report: settings saved');
    $saved = true;
}
$thisYear = (int)date('Y');
$year = $request->query->getInt('year', $thisYear - 1); // FPAR reports the prior calendar year
$year = max(2020, min($thisYear, $year));
$categories = $settings->categories();
$facilities = $settings->facilities();
$region = $settings->region();
$fpar = Fpar::load();
$guidelines = PovertyGuidelines::load();
$report = TitleXReport::build($year, $source->visits($year, $categories, $facilities), $fpar);
$tables = Tables::build($year, $report->written, $fpar, $guidelines, $region);
$tableChecks = TableChecks::run($tables);
// OPA compares each cell with last year's submission; the preview compares with last year as the record and
// these settings build it today.
$prior = TitleXReport::build($year - 1, $source->visits($year - 1, $categories, $facilities), $fpar);
$changes = $prior->written === [] ? [] : PriorYear::changes($tables, Tables::build($year - 1, $prior->written, $fpar, $guidelines, $region));
OpenEmr::audit('other', 'Title X Report: report viewed');
$csrf = OpenEmr::csrfToken();
$chosenCategories = array_flip($categories);
$chosenFacilities = array_flip($facilities);
$toFix = $report->toFix();
?>
<!DOCTYPE html>
<?php // The page is in the signed-in user's OpenEMR language, so translated text is announced in it. ?>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Title X Report'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\TitleXReport\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 70rem">
    <h1 class="h4"><?php echo xlt('Title X Report'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p><?php echo xlt('The FPAR 2.0 file for the Office of Population Affairs: one row per family planning visit in the calendar year, with the column names and codes OPA asks for.'); ?>
        <?php echo text(sprintf(xl('Codes from the OPA valid values file of %s.'), $fpar->version)); ?></p>

    <form method="get" class="form-inline mb-3">
        <label for="year" class="mr-2"><?php echo xlt('Year'); ?></label>
        <select id="year" name="year" class="form-control mr-2">
            <?php for ($y = $thisYear; $y >= $thisYear - 3; $y--) : ?>
                <option value="<?php echo attr((string)$y); ?>"<?php echo $y === $year ? ' selected' : ''; ?>><?php echo text((string)$y); ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-secondary"><?php echo xlt('Show'); ?></button>
    </form>

    <?php if ($categories === []) : ?>
        <div class="alert alert-info"><?php echo xlt('Choose the visit categories that are Title X visits below. Until then, only visits with a Title X visit record are counted.'); ?></div>
    <?php endif; ?>

    <?php $visits = count($report->written); $people = $report->users(); ?>
    <h2 class="h5"><?php echo text(sprintf(xl('Visits in the file: %1$d, for %2$d people'), $visits, $people)); ?></h2>
    <?php if ($report->written !== []) : ?>
        <form method="post" action="download.php" class="mb-2">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
            <input type="hidden" name="year" value="<?php echo attr((string)$year); ?>">
            <button type="submit" name="file" value="combined" class="btn btn-primary mr-2"><?php echo xlt('Download the FPAR file (CSV)'); ?></button>
            <span class="small"><?php echo xlt('Or two files, for a site that reports lab results apart:'); ?></span>
            <button type="submit" name="file" value="encounter" class="btn btn-secondary btn-sm ml-1"><?php echo xlt('Encounter file'); ?></button>
            <button type="submit" name="file" value="lab" class="btn btn-secondary btn-sm ml-1"><?php echo xlt('Lab results file'); ?></button>
        </form>
        <p class="small text-muted"><?php echo xlt('Upload them as they are. Opening and saving them in a spreadsheet program changes dates and long codes, which OPA warns about.'); ?></p>
    <?php endif; ?>

    <?php if ($report->refused !== []) : ?>
        <h2 class="h5 mt-4"><?php echo text(sprintf(xl('Left out of the file: %d'), count($report->refused))); ?></h2>
        <p><?php echo xlt('OPA rejects a whole file when any row lacks one of these, so these visits are not in it. Fix the record, then come back.'); ?></p>
        <table class="table table-sm">
            <thead><tr><th><?php echo xlt('Patient'); ?></th><th><?php echo xlt('Visit'); ?></th><th><?php echo xlt('Missing'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($report->refused as $row) : ?>
                <tr><td><?php echo text($row->cells['patient_id']); ?></td><td><?php echo text($row->cells['visit_date']); ?></td>
                    <td><?php echo text(implode(', ', $row->missingKeys)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($report->warnings !== []) : ?>
        <h2 class="h5 mt-4"><?php echo xlt('OPA data quality warnings'); ?></h2>
        <ul>
            <?php foreach ($report->warnings as $w) : ?>
                <li><?php echo text(sprintf(xl('%1$s: %2$d of %3$d people (%4$d percent; OPA warns at %5$d percent)'), xl($w['label']), $w['unknown'], $w['users'], (int)round($w['share'] * 100), (int)round($w['threshold'] * 100))); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($toFix !== []) : ?>
        <h2 class="h5 mt-4"><?php echo text(sprintf(xl('Visits to fix: %d'), count($toFix))); ?></h2>
        <p><?php echo xlt('These visits are in the file, with the field in question left blank.'); ?></p>
        <table class="table table-sm">
            <thead><tr><th><?php echo xlt('Patient'); ?></th><th><?php echo xlt('Visit'); ?></th><th><?php echo xlt('What to fix'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($toFix as $row) : ?>
                <tr><td><?php echo text($row->cells['patient_id']); ?></td><td><?php echo text($row->cells['visit_date']); ?></td>
                    <td><?php echo text(implode('; ', array_map(fn(array $p): string => Problems::text($p, 'xl'), $row->toFix))); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($report->written !== []) : ?>
        <h2 class="h5 mt-4"><?php echo xlt('OPA checks on the tables'); ?></h2>
        <?php if ($tableChecks === []) : ?>
            <p><?php echo xlt('None of the checks OPA runs on the tables flags this year.'); ?></p>
        <?php else : ?>
            <p><?php echo xlt('OPA asks for a comment on each of these when it is not a mistake in the record.'); ?></p>
            <ul>
                <?php foreach ($tableChecks as $check) : ?>
                    <li><?php echo text(sprintf(xl('Table %d'), $check['table'])); ?>: <?php echo text(TableChecks::text($check, 'xl')); ?> <span class="small text-muted">(<?php echo text($check['cells']); ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="h5 mt-4"><?php echo text(sprintf(xl('Compared with %d'), $year - 1)); ?></h2>
        <?php if ($prior->written === []) : ?>
            <p><?php echo text(sprintf(xl('No visits in %d count under these settings, so there is nothing to compare. OPA compares with what was submitted for %d.'), $year - 1, $year - 1)); ?></p>
        <?php elseif ($changes === []) : ?>
            <p><?php echo xlt('No cell changed by half or more from the year before.'); ?></p>
        <?php else : ?>
            <p><?php echo text(sprintf(xl('OPA flags a cell that changes by 50 percent or more from the year before. These cells did, against %d as OpenEMR builds it today with these settings; OPA compares with what was submitted.'), $year - 1)); ?></p>
            <table class="table table-sm">
                <thead><tr><th><?php echo xlt('Cell'); ?></th><th><?php echo xlt('Row'); ?></th><th><?php echo xlt('Column'); ?></th>
                    <th class="text-right"><?php echo text((string)($year - 1)); ?></th><th class="text-right"><?php echo text((string)$year); ?></th><th class="text-right"><?php echo xlt('Change'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($changes as $c) : ?>
                    <tr><td><?php echo text($c['cell']); ?></td><td><?php echo text(xl($c['row'])); ?></td><td><?php echo text(xl($c['column'])); ?></td>
                        <td class="text-right"><?php echo text((string)$c['prior']); ?></td><td class="text-right"><?php echo text((string)$c['now']); ?></td>
                        <td class="text-right"><?php echo text($c['change'] === null ? xl('from none') : sprintf('%+d%%', $c['change'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 class="h5 mt-4"><?php echo xlt('The tables OPA will build'); ?></h2>
        <p><?php echo xlt('OPA builds these tables from the file. Each person counts once, at their age on June 30, with their latest visit for sex, race, ethnicity, insurance and English proficiency, and their latest method at exit. Tables 13 and 14 are entered in the portal, and Table 10 is retired.'); ?></p>
        <?php foreach ($tables->tables as $t) : ?>
            <h3 class="h6 mt-3"><?php echo text(sprintf(xl('Table %d'), $t->number)); ?>: <?php echo text(xl($t->title)); ?></h3>
            <table class="table table-sm table-bordered" style="width: auto">
                <thead><tr><th scope="col"></th>
                    <?php foreach ($t->columns as $i => $col) : ?>
                        <th scope="col" class="text-right"><?php echo text(xl($col)); ?> (<?php echo text(chr(65 + $i)); ?>)</th>
                    <?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($t->rows as $r => $row) : ?>
                    <tr<?php echo $r === count($t->rows) - 1 && $t->number !== 9 && $t->number !== 12 ? ' class="font-weight-bold"' : ''; ?>><th scope="row"><?php echo text(($r + 1) . '. ' . xl($row['label'])); ?></th>
                        <?php foreach ($row['cells'] as $cell) : ?>
                            <td class="text-right"><?php echo $cell === null ? '<span class="text-muted">' . xlt('n/a') . '</span>' : text((string)$cell); ?></td>
                        <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($t->note !== '') : ?>
                <p class="small text-muted"><?php echo text($t->note); ?></p>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="h5 mt-4"><?php echo xlt('Which visits count'); ?></h2>
    <?php if ($saved) : ?>
        <div class="alert alert-success"><?php echo xlt('Saved.'); ?></div>
    <?php endif; ?>
    <p><?php echo xlt('OPA leaves the definition of a family planning visit to each project. Choose the visit categories that are Title X visits; a visit with a Title X visit record counts either way. Choose facilities to count only those in the Title X project, or none for all.'); ?></p>
    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <input type="hidden" name="action" value="settings">
        <div class="row">
            <fieldset class="col-md-6">
                <legend class="h6"><?php echo xlt('Visit categories'); ?></legend>
                <?php foreach ($source->categories() as $c) : ?>
                    <div class="form-check"><label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="categories[]" value="<?php echo attr((string)$c['id']); ?>"<?php echo isset($chosenCategories[$c['id']]) ? ' checked' : ''; ?>>
                        <?php echo text(xl_appt_category($c['name'])); ?></label></div>
                <?php endforeach; ?>
            </fieldset>
            <fieldset class="col-md-6">
                <legend class="h6"><?php echo xlt('Facilities in the project'); ?></legend>
                <?php foreach ($source->facilities() as $f) : ?>
                    <div class="form-check"><label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="facilities[]" value="<?php echo attr((string)$f['id']); ?>"<?php echo isset($chosenFacilities[$f['id']]) ? ' checked' : ''; ?>>
                        <?php echo text($f['name']); ?></label></div>
                <?php endforeach; ?>
            </fieldset>
        </div>
        <div class="form-group mt-2">
            <label for="region"><?php echo xlt('Poverty guidelines for Table 4'); ?></label>
            <select id="region" name="region" class="form-control" style="max-width: 24rem">
                <?php foreach (TablesBuilder::REGION_NAMES as $value => $name) : ?>
                    <option value="<?php echo attr($value); ?>"<?php echo $value === $region ? ' selected' : ''; ?>><?php echo text(xl($name)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary mt-2"><?php echo xlt('Save'); ?></button>
    </form>
</main>
</body>
</html>
