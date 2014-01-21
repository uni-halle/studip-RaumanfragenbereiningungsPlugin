<?php

/*
 * RaumanfragenBereinigungPlugin
 *
 * Copyright (C) 2010 - André Noack <noack@data-quest.de>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 */

require_once "lib/resources/resourcesFunc.inc.php";

class RaumanfragenBereinigungPlugin extends AbstractStudIPAdministrationPlugin {


    function __construct() {

        parent::__construct();
        if (getGlobalPerms($GLOBALS['user']->id) === 'admin') {
            $navigation = new PluginNavigation();
            $navigation->setDisplayname($this->getPluginname());
            $this->setNavigation($navigation);
            $this->setTopnavigation(clone $navigation);
        }
    }


    function getPluginname() {
        return _("Raumanfragen-Bereinigung");
    }


    function actionShow() {
        if (getGlobalPerms($GLOBALS['user']->id) !== 'admin') {
            throw new AccessDeniedException();
        }

        $semester_id = Request::option('rb_semester_id');
        $have_times = Request::int('rb_have_times');
        if (count(Request::optionArray('rb_kill_requests'))) {
            $deleted = 0;
            foreach(Request::optionArray('rb_kill_requests') as $r) {
                $rr = new RoomRequest($r);
                $deleted += $rr->delete();
            }
            echo MessageBox::success(sprintf(_("Es wurden %s Anfragen gelöscht."), $deleted));
        }
        if ($semester_id) {
            $requests = $this->getRequests($semester_id, $have_times);
        }
        echo '<div style="padding:1em">';
        echo '<h1>' . _("Offene Raumanfragen") . '</h1>';
        echo '<form action="" method="post" name="rb_form">';
        echo '<div>'._("Semester:") . '&nbsp;';
        echo SemesterData::GetSemesterSelector(array('name' => 'rb_semester_id'), $semester_id, 'semester_id', false);
        echo '&nbsp;&nbsp;<input type="radio" '.(!$have_times ? 'checked' : '').' id="rb_have_times_0" name="rb_have_times" value="0">&nbsp;<label for="rb_have_times_0">' . _("Anfragen ohne zukünftige Termine") . '</label>';
        echo '&nbsp;&nbsp;<input type="radio" '.($have_times ? 'checked' : '').' id="rb_have_times_1" name="rb_have_times" value="1">&nbsp;<label for="rb_have_times_1">' . _("Anfragen mit zukünftigen Terminen") . '</label>';
        echo '&nbsp;&nbsp;' . Studip\Button::create(_('Anzeigen'));
        echo '</div><br>';
        if ($requests) {

            echo '<div>' . _("Ausgewählte Anfragen löschen"). '&nbsp;'.Studip\Button::create(_("Löschen"),'rb_kill') . '</div>';
            echo '<table class="default zebra-hover">';
            echo '<tr>';
            echo '<th><input type="checkbox" title="'._("alle auswählen").'" onChange="jQuery(\'input[name^=rb_kill_requests]\').attr(\'checked\',this.checked);"></th>';
            echo '<th>' . _("Veranstaltung") . '</th>';
            echo '<th>' . _("Startsemester") . '</th>';
            echo '<th>' . _("Endsemester") . '</th>';
            echo '<th>' . _("betroffene Termine") . '</th>';
            echo '</tr>';
            foreach ($requests as $i => $r) {
                echo '<tr>';
                echo '<td width="1"><input type="checkbox" name="rb_kill_requests[]" value="'.$i.'"></td>';
                echo '<td><a href="'.UrlHelper::getLink('details.php', array('sem_id' => $r['seminar_id'])).'">' . htmlready($r['name']) . '</a></td>';
                echo '<td>' . htmlready($r['startsem']) . '</td>';
                echo '<td>' . htmlready($r['endsem']) . '</td>';
                echo '<td>' . htmlready((int)$r['have_times']) ;
                echo '&nbsp;|&nbsp;';
                echo $r['rr_termin_id'] ? _("Einzelterminanfrage") : ($r['rr_metadate_id'] ? _("Metaterminanfrage") : _("Veranstaltungsanfrage"));
                echo '&nbsp;';
                if ($r['have_times']) {
                    echo '<a href="'.UrlHelper::getLink('resources.php', array('view' => 'edit_request', 'single_request' => $i)).'">'._("[auflösen]").'</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</form></div>';
    }

    function getRequests($semester_id, $have_times) {

        $requests = array();

        $semester = SemesterData::GetInstance()->getSemesterData($semester_id);
        $criteria = "AND s.start_time <=".(int)$semester["beginn"]." AND (".(int)$semester["beginn"]." <= (s.start_time + s.duration_time) OR s.duration_time = -1) ";
        $query = sprintf("SELECT rr.seminar_id, s.Name as name, request_id,
                           closed, tt.termin_id as tt_termin_id,
                            rr.termin_id as rr_termin_id,
                            rr.metadate_id as rr_metadate_id,
                            mt.metadate_id as mt_metadate_id,
                            COUNT(IF(t.date_typ IN ".getPresenceTypeClause(). ",t.termin_id,NULL)) as anzahl_termine,
                            COUNT(mt.termin_id) as anzahl_termine_meta,
                            s.start_time,sd1.name AS startsem,IF(s.duration_time=-1, '"._("unbegrenzt")."', sd2.name) AS endsem,
                            rr.resource_id
                            FROM resources_requests rr
                            INNER JOIN seminare s USING(seminar_id)
                            LEFT JOIN semester_data sd1 ON ( start_time BETWEEN sd1.beginn AND sd1.ende)
                            LEFT JOIN semester_data sd2 ON ((start_time + duration_time) BETWEEN sd2.beginn AND sd2.ende)
                            LEFT JOIN termine tt ON (tt.termin_id = rr.termin_id AND tt.date > UNIX_TIMESTAMP())
                            LEFT JOIN termine mt ON (mt.metadate_id = rr.metadate_id AND mt.date > UNIX_TIMESTAMP())
                            LEFT JOIN termine t ON(s.Seminar_id = t.range_id AND t.metadate_id <> rr.metadate_id AND t.termin_id <>  rr.termin_id AND t.date > UNIX_TIMESTAMP())

                            WHERE closed=0 $criteria GROUP BY request_id ORDER BY rr.chdate");
        $rs = DBManager::get()->query($query);
        while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
            $requests[$row["request_id"]]["seminar_id"] = $row["seminar_id"];
            $requests[$row["request_id"]]["startsem"] = $row["startsem"];
            $requests[$row["request_id"]]["endsem"] = $row["endsem"];
            $requests[$row["request_id"]]["name"] = $row["name"];
            $requests[$row["request_id"]]["closed"] = $row["closed"];
            $requests[$row["request_id"]]["have_times"] = $row["tt_termin_id"] && $row["tt_termin_id"] === $row['rr_termin_id'] ?  1 : ($row['mt_metadate_id'] && $row['mt_metadate_id'] === $row['rr_metadate_id'] ? $row['anzahl_termine_meta'] : $row["anzahl_termine"]);
            $requests[$row["request_id"]]["rr_termin_id"] = $row['rr_termin_id'];
            $requests[$row["request_id"]]["rr_metadate_id"] = $row['rr_metadate_id'];
            $requests[$row["request_id"]]["resource_id"] = $row['resource_id'];
            if(!((bool)$requests[$row["request_id"]]["have_times"] == $have_times)) {
                unset($requests[$row["request_id"]]);
            }
        }

        return $requests;

    }
}
