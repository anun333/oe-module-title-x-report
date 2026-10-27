<?php

/**
 * What Title X Report adds to OpenEMR: a menu entry for users with the access it needs, and a "Confidential visits"
 * tab in the Staff Worklist for billing staff.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\TitleXReport;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Menu\MenuEvent;
use OpenHealthcare\Common\Contract\Worklist\Worklist;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use OpenHealthcare\TitleXReport\Worklist\ConfidentialVisitsSource;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-title-x-report';
    /** The access a user needs for the menu entry and the page: section and value, as AclMain checks them. */
    public const ACL = ['patients', 'demo'];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
        $this->eventDispatcher->addListener('staff_worklist.collect_sources', $this->addWorklistTab(...));
    }

    /**
     * "Confidential visits" in the Staff Worklist (0.8.0 or later), for billing staff: the visits in the site's
     * confidential categories that a claim or a statement could expose. Built on openhealthcare-common's contract;
     * where a module copied in by hand loaded an older copy of the library first, the contract isn't there and the tab
     * is left out rather than stop the page.
     */
    public function addWorklistTab(object $event): void
    {
        if (!interface_exists(WorkSource::class) || !AclMain::aclCheckCore('acct', 'bill')) {
            return;
        }
        Worklist::contribute($event, new ConfidentialVisitsSource(Worklist::now($event)), xl('Confidential visits'), xl('Biller'));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'titlex';
        $item->menu_id = 'titlex0';
        $item->label = xlt('Title X Report');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = self::ACL;
        $item->global_req = [];
        // After Messages, or at the end.
        $menu = $event->getMenu();
        $position = count($menu);
        foreach ($menu as $i => $entry) {
            if (($entry->menu_id ?? '') === 'msg0') {
                $position = (int)$i + 1;
                break;
            }
        }
        array_splice($menu, $position, 0, [$item]);
        $event->setMenu($menu);
        return $event;
    }
}
