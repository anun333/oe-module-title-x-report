<?php

/**
 * Title X Report: the year's FPAR 2.0 file, OPA's combined layout, UTF-8. It holds patient identifiers and birth
 * dates, so each download is checked, posted with the page's token, and audited.
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
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\TitleXReport\Http\Request;
use OpenHealthcare\TitleXReport\Reference\Fpar;
use OpenHealthcare\TitleXReport\Report\CsvFile;
use OpenHealthcare\TitleXReport\Report\TitleXReport;
use OpenHealthcare\TitleXReport\Repository\EncounterSource;
use OpenHealthcare\TitleXReport\Repository\Settings;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('patients', 'demo')) {
    OpenEmr::deny('Title X Report: download refused', xl('Title X Report'), 'Title X Report');
}
$request = Request::get();
if (!$request->isMethod('POST')) {
    OpenEmr::deny('Title X Report: download not posted', xl('Title X Report'), 'Title X Report');
}
OpenEmr::requireCsrf($request->request->getString('csrf_token_form'));
$thisYear = (int)date('Y');
$year = max(2020, min($thisYear, $request->request->getInt('year', $thisYear - 1)));
$settings = new Settings();
$fpar = Fpar::load();
$report = TitleXReport::build($year, (new EncounterSource())->visits($year, $settings->categories(), $settings->facilities()), $fpar);
OpenEmr::audit('other', sprintf('Title X Report: FPAR file for %d downloaded, %d rows', $year, count($report->written)));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="fpar-' . $year . '.csv"');
echo CsvFile::write($fpar->columns(), $report->written);
