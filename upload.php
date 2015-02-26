#!/usr/bin/php
<?php

require 'vendor/autoload.php';

require_once 'configs.php';

$scopes = array('https://www.googleapis.com/auth/calendar');

// iCal データ取得
$client = new GuzzleHttp\Client();
$clientRes = $client->get($aipoIcalUrl, ['auth' =>  [$aipoUser, $aipoPasswd]]);
if ($clientRes->getStatusCode() != 200) {
    echo 'Authorization Required' . "\n";
    exit(1);
}
$icalContents = $clientRes->getBody();

$ical = new vcalendar();
$icalRes = $ical->parse($icalContents);

$aipoEvents = array();
if ($icalRes == true) {
    foreach ($ical->components as $key => $event) {
        $summary = $event->getProperty('summary');
        if (!$summary) {
            continue;
        }
        $dtstamp = $event->getProperty('dtstamp');
        $dtstart = $event->getProperty('dtstart');
        $dtend = $event->getProperty('dtend');
        $uid = $event->getProperty('uid');
        preg_match(('/^([0-9A-Za-z]+)-([0-9]+)\@(.+?)$/'), $uid, $matches);
        $aipoId = $matches[2];

        $start = new Google_Service_Calendar_EventDateTime();
        if (!empty($event->dtstart['params']) && $event->dtstart['params']['VALUE'] == 'DATE') {
            $start->setDate($dtstart['year'] . '-' . sprintf('%02d', $dtstart['month']) . '-' . sprintf('%02d', $dtstart['day']));
        } else {
            $start->setDateTime($dtstart['year'] . '-' . sprintf('%02d', $dtstart['month']) . '-' . sprintf('%02d', $dtstart['day']) . 'T' . $dtstart['hour'] . ':' . $dtstart['min'] . ':' . $dtstart['sec'] . '+09:00');
        }

        $end = new Google_Service_Calendar_EventDateTime();
        if (!empty($event->dtend['params']) && $event->dtend['params']['VALUE'] == 'DATE') {
            $end->setDate($dtend['year'] . '-' . sprintf('%02d', $dtend['month']) . '-' . sprintf('%02d', $dtend['day']));
        } else {
            $end->setDateTime($dtend['year'] . '-' . sprintf('%02d', $dtend['month']) . '-' . sprintf('%02d', $dtend['day']) . 'T' . $dtend['hour'] . ':' . $dtend['min'] . ':' . $dtend['sec'] . '+09:00');
        }

        $recurrence = array();

        $exdate = $event->getProperty('exdate');
        if (!empty($exdate)) {
            $exdateText = 'EXDATE;TZID=Asia/Tokyo:';
            $exdateLists = array();
            foreach ($exdate as $value) {
                $exdateLists[] = $value['year'] . sprintf('%02d', $value['month']) . sprintf('%02d', $value['day']) . 'T' . $value['hour'] . $value['min'] . $value['sec'];
            }
            asort($exdateLists);
            $exdateText .= implode($exdateLists, ',');
            $recurrence[] = $exdateText;
        }

        $rrule = $event->getProperty('rrule');
        if (!empty($rrule)) {
            $rruleText = 'RRULE:';
            $rruleLists = array();
            $rruleLists[] = 'FREQ=' . $rrule['FREQ'];
            $rruleLists[] = 'UNTIL=' . $rrule['UNTIL']['year'] . sprintf('%02d', $rrule['UNTIL']['month']) . sprintf('%02d', $rrule['UNTIL']['day']) . 'T' . $rrule['UNTIL']['hour'] . $rrule['UNTIL']['min'] . $rrule['UNTIL']['sec'] . $rrule['UNTIL']['tz'];
            switch ($rrule['FREQ']) {
                case 'DAILY':
                    break;
                case 'WEEKLY':
                    $rruleLists[] = 'BYDAY=' . implode($rrule['BYDAY'],',');
                    break;
                case 'MONTHLY':
                    $rruleLists[] = 'BYMONTH=' . implode($rrule['BYMONTH'],',');
                    $rruleLists[] = 'BYMONTHDAY=' . $rrule['BYMONTHDAY'];
                    break;
            }
            $rruleText .= implode($rruleLists, ';');
            $recurrence[] = $rruleText;
        }

        if (!empty($recurrence)) {
            $start['timeZone'] = 'Asia/Tokyo';
            $end['timeZone'] = 'Asia/Tokyo';
        } else {
            $recurrence = null;
        }

        $aipoEvent = compact('summary', 'dtstamp', 'dtstart', 'dtend', 'uid', 'aipoId', 'start', 'end', 'recurrence');
        $aipoEvents[$aipoId] = $aipoEvent;
    }
}

$p12KeyContents = file_get_contents($p12Key);
$client = new Google_Client();
$client->setClientId($clientId);
$credential = new Google_Auth_AssertionCredentials($authEmail, $scopes, $p12KeyContents);
$client->setAssertionCredentials($credential);

$service = new Google_Service_Calendar($client);

$calendarLists = $service->calendarList->listCalendarList();
foreach ($calendarLists['items'] as $key => $calendar) {
    $calenderId = $calendar->id;
    $calenderName = $calendar->getSummary();
    if ($calenderName != $targetCalendar) {
        continue;
    }

    //既存イベント取得  
    $events = $service->events->listEvents($calenderId);
    while(true) {
        foreach ($events->getItems() as $event) {
            $eventId = $event->getId();
            $icalUid = $event->getICalUID();
            preg_match(('/^([0-9A-Za-z]+)-([0-9]+)\@(.+?)$/'), $icalUid, $matches);
            $aipoId = $matches[2];
            $summary = $event->getSummary();
            $start = $event->getStart();
            $end = $event->getEnd();
            $recurrence = $event->getRecurrence();

            // aipo にデータがない場合は削除
            if (empty($aipoEvents[$aipoId])) {
                // 直近3ヶ月の情報だけ消す
                // ※ aipo の ical が直近3ヶ月前までのデータなので
                if (strtotime('- 3month') < strtotime($start->date ? $start->date : $start->dateTime) || !empty($recurrence)) {
                    // 削除処理
                    $service->events->delete($calenderId , $eventId);
                    echo '[Delete Event]' . ':' . $summary . ' (' . ($start->date ? $start->date : $start->dateTime) . ')' . "\n";
                }
                continue;
            }
            $aipoEvent = $aipoEvents[$aipoId];

            $recurrence_diff = false;
            if (gettype($aipoEvent['recurrence']) != gettype($recurrence)) {
                $recurrence_diff = true;
            } elseif (is_array($aipoEvent['recurrence']) && is_array($recurrence)) {
                if (array_diff($aipoEvent['recurrence'], $recurrence)) {
                    $recurrence_diff = true;
                }
            } else {
                if ($aipoEvent['recurrence'] != $recurrence) {
                    $recurrence_diff = true;
                }
            }

            // 登録データに差異があれば削除
            if ($aipoEvent['summary'] != $summary
                || $aipoEvent['start']->date != $start->date
                || $aipoEvent['start']->dateTime != $start->dateTime
                || $aipoEvent['start']->timeZone != $start->timeZone
                || $aipoEvent['end']->date != $end->date
                || $aipoEvent['end']->dateTime != $end->dateTime
                || $aipoEvent['end']->timeZone != $end->timeZone
                || $recurrence_diff == true
                ) {
                // 削除処理
                $service->events->delete($calenderId , $eventId);
                echo '[Delete Event]' . ':' . $summary . ' (' . ($start->date ? $start->date : $start->dateTime) . ')' . "\n";
                continue;
            }

            // 既に登録されているデータなので、更新リストから除外
            unset($aipoEvents[$aipoId]);
        }

        $pageToken = $events->getNextPageToken();
        if ($pageToken) {
            $optParams = array('pageToken' => $pageToken);
            $events = $service->events->listEvents($calenderId, $optParams);
        } else {
            break;
        }
    }

    // イベント登録
    if (!empty($aipoEvents)) {
        foreach ($aipoEvents as $aipoEvent) {
            $event = new Google_Service_Calendar_Event();
            $event->setSummary($aipoEvent['summary']);
            $event->setStart($aipoEvent['start']);
            $event->setEnd($aipoEvent['end']);
            $event->setRecurrence($aipoEvent['recurrence']);
            $event->setICalUID($aipoEvent['uid']);
            $service->events->insert($calenderId, $event);
            echo '[Insert Event]' . ':' . $aipoEvent['summary'] . ' (' . ($aipoEvent['start']->date ? $aipoEvent['start']->date : $aipoEvent['start']->dateTime) . ')' . "\n";
        }
    }
}

exit(0);
