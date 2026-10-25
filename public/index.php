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
use OpenHealthcare\TitleXReport\Http\Request;
use OpenHealthcare\TitleXReport\Reference\Fpar;
use OpenHealthcare\TitleXReport\Report\Problems;
use OpenHealthcare\TitleXReport\Report\TitleXReport;
use OpenHealthcare\TitleXReport\Repository\EncounterSource;
use OpenHealthcare\TitleXReport\Repository\Settings;

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
    $settings->save(array_map('intval', $request->request->all('categories')), array_map('intval', $request->request->all('facilities')), (int)OpenEmr::session('authUserID'));
    OpenEmr::audit('other', 'Title X Report: settings saved');
    $saved = true;
}
$thisYear = (int)date('Y');
$year = $request->query->getInt('year', $thisYear - 1); // FPAR reports the prior calendar year
$year = max(2020, min($thisYear, $year));
$categories = $settings->categories();
$facilities = $settings->facilities();
$fpar = Fpar::load();
$report = TitleXReport::build($year, $source->visits($year, $categories, $facilities), $fpar);
OpenEmr::audit('other', 'Title X Report: report viewed');
$csrf = OpenEmr::csrfToken();
$chosenCategories = array_flip($categories);
$chosenFacilities = array_flip($facilities);
$toFix = $report->toFix();
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Title X Report'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 70rem">
    <h1 class="h4"><?php echo xlt('Title X Report'); ?></h1>
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
        <form method="post" action="download.php">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
            <input type="hidden" name="year" value="<?php echo attr((string)$year); ?>">
            <button type="submit" class="btn btn-primary mb-2"><?php echo xlt('Download the FPAR file (CSV)'); ?></button>
        </form>
        <p class="small text-muted"><?php echo xlt('Upload it as it is. Opening and saving it in a spreadsheet program changes dates and long codes, which OPA warns about.'); ?></p>
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
        <button type="submit" class="btn btn-secondary mt-2"><?php echo xlt('Save'); ?></button>
    </form>
</main>
</body>
</html>
