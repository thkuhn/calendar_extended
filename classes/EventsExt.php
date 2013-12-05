<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2012 Leo Feyer
 *
 * @package   Contao
 * @author    Kester Mielke
 * @license   LGPL
 * @copyright Kester Mielke 2010-2013
 */


/**
 * Namespace
 */
namespace Contao;

/**
 * Class EventExt
 *
 * @copyright  Kester Mielke 2010-2013
 * @author     Kester Mielke
 * @package    Devtools
 */
class EventsExt extends \Events
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = '';


	/**
	 * Generate the module
	 */
	protected function compile()
	{
        parent::compile;
    }


    /**
     * Get all events of a certain period
     * @param array
     * @param integer
     * @param integer
     * @return array
     */
    protected function getAllEvents($arrCalendars, $intStart, $intEnd)
    {
        return $this->getAllEventsExt($arrCalendars, $intStart, $intEnd, array(null, true));
    }


    /**
     * Get all events of a certain period
     * @param array
     * @param integer
     * @param integer
     * @return array
     */
    protected function getAllEventsExt($arrCalendars, $intStart, $intEnd, $arrParam=null)
    {
        # set default values...
        $arrHolidays=null;
        $showRecurrences=true;

        if (!is_array($arrCalendars))
        {
            return array();
        }

        $this->arrEvents = array();

        if ($arrParam !== null)
        {
            if (count($arrParam) > 1)
            {
                $arrHolidays = $arrParam[0];
                $showRecurrences = $arrParam[1];
            }
            else
            {
                $arrHolidays = $arrParam[0];
            }
        }

        // Used to collect exception list data for events
        $arrEventSkipInfo = array();

        foreach ($arrCalendars as $id)
        {
            $strUrl = $this->strUrl;
            $objCalendar = \CalendarModel::findByPk($id);

            // Get the current "jumpTo" page
            if ($objCalendar !== null && $objCalendar->jumpTo && ($objTarget = $objCalendar->getRelated('jumpTo')) !== null)
            {
                $strUrl = $this->generateFrontendUrl($objTarget->row(), ($GLOBALS['TL_CONFIG']['useAutoItem'] ?  '/%s' : '/events/%s'));
            }

            // Get the events of the current period
            $objEvents = \CalendarEventsModel::findCurrentByPid($id, $intStart, $intEnd);

            if ($objEvents === null)
            {
                continue;
            }

            while ($objEvents->next())
            {
                $eventRecurrences = (int)$objEvents->recurrences+1;

                $objEvents->pos_idx = 1;
                $objEvents->pos_cnt = 1;

                if ($objEvents->recurring || $objEvents->recurringExt)
                {
                    if ($objEvents->recurrences == 0)
                    {
                        $objEvents->pos_cnt = 0;
                    }
                    else
                    {
                        $objEvents->pos_cnt = (int)$eventRecurrences;
                    }
                }

                // Count irregular recurrences
                $arrayFixedDates = deserialize($objEvents->repeatFixedDates) ? deserialize($objEvents->repeatFixedDates) : null;
                if (!is_null($arrayFixedDates))
                {
                    foreach ($arrayFixedDates as $fixedDate)
                    {
                        if ($fixedDate['new_repeat'])
                        {
                            $objEvents->pos_cnt++;
                        }
                    }
                }

                // Check if we have to store the event if it's on weekend
                $weekday = (int)date('w', $objEvents->startTime);
                $store = true;
                if ($objEvents->hideOnWeekend)
                {
                    if ($weekday == 0 || $weekday == 6)
                    {
                        $store = false;
                    }
                }

                // check the repeat values
                if ($objEvents->recurring)
                {
                    $arrRepeat = deserialize($objEvents->repeatEach)?deserialize($objEvents->repeatEach):null;
                }
                if ($objEvents->recurringExt)
                {
                    $arrRepeat = deserialize($objEvents->repeatEachExt)?deserialize($objEvents->repeatEachExt):null;
                }

                // we need a counter for the recurrences if noSpan is set
                $cntRecurrences = 0;
                $dateBegin = date('Ymd', $intStart);
                $dateEnd = date('Ymd', $intEnd);
                $dateNextStart = date('Ymd', $objEvents->startTime);
                $dateNextEnd = date('Ymd', $objEvents->endTime);

                // store the entry if everything is fine...
                if ($store === true)
                {
                    $eventUrl = $strUrl."?day=".Date("Ymd", $objEvents->startTime)."&amp;times=".$objEvents->startTime.",".$objEvents->endTime;
                    $this->addEvent($objEvents, $objEvents->startTime, $objEvents->endTime, $eventUrl, $intStart, $intEnd, $id);

                    // increase $cntRecurrences if event is in scope
                    if ($dateNextStart >= $dateBegin && $dateNextEnd <= $dateEnd)
                    {
                        $cntRecurrences++;
                    }
                }

                // keep the original values
                $orgDateStart = new \Date($objEvents->startTime);
                $orgDateEnd = new \Date($objEvents->endTime);

                /*
                 * next we handle the irregular recurrences
                 *
                 * this is a complete different case
                 */
                if (!is_null($arrayFixedDates))
                {
                    foreach ($arrayFixedDates as $fixedDate)
                    {
                        if ($fixedDate['new_repeat'])
                        {
                            // check if we have to stop because of cal_noSpan
                            if ($this->cal_noSpan && $cntRecurrences > 0)
                            {
                                break;
                            }

                            // new start time
                            $strNewDate = $fixedDate['new_repeat'];
                            $strNewTime = (strlen($fixedDate['new_start']) ? $fixedDate['new_start'] : $orgDateStart->time);
                            $newDateStart = new \Date(trim($strNewDate.' '.$strNewTime), \Date::getNumericDatimFormat());

                            $objEvents->startTime = $newDateStart->timestamp;
                            $dateNextStart = date('Ymd', $objEvents->startTime);

                            // new end time
                            $strNewTime = (strlen($fixedDate['new_end']) ? $fixedDate['new_end'] : $orgDateEnd->time);
                            $newDateEnd = new \Date(trim($strNewDate.' '.$strNewTime), \Date::getNumericDatimFormat());

                            $objEvents->endTime = $newDateEnd->timestamp;
                            $dateNextEnd = date('Ymd', $objEvents->endTime);

                            // set a reason if given...
                            $objEvents->moveReason = $fixedDate['reason'] ? $fixedDate['reason'] : null;

                            // position of the event
                            $objEvents->pos_idx++;

                            // add the irregular event to the array
                            $eventUrl = $strUrl."?day=".date("Ymd", $objEvents->startTime)."&amp;times=".$objEvents->startTime.",".$objEvents->endTime;
                            $this->addEvent($objEvents, $objEvents->startTime, $objEvents->endTime, $eventUrl, $intStart, $intEnd, $id);

                            // Restore the original values
                            $objEvents->startTime = $orgDateStart->timestamp;
                            $objEvents->endTime = $orgDateEnd->timestamp;

                            // increase $cntRecurrences if event is in scope
                            if ($dateNextStart >= $dateBegin && $dateNextEnd <= $dateEnd)
                            {
                                $cntRecurrences++;
                            }
                        }
                    }
                }

                /*
                 * Recurring events and Ext. Recurring events
                 *
                 * Here we manage the recurrences. We take the repeat option and set the new values
                 * if showRecurrences is false we do not need to go thru all recurring events...
                 */
                if ((($objEvents->recurring && $objEvents->repeatEach) || ($objEvents->recurringExt && $objEvents->repeatEachExt)) && $showRecurrences)
                {
                    if (is_null($arrRepeat))
                    {
                        continue;
                    }

                    // list of months we need
                    $arrMonth = array(1=>'january', 2=>'february', 3=>'march', 4=>'april', 5=>'may', 6=>'jun',
                        7=>'july', 8=>'august', 9=>'september', 10=>'october', 11=>'november', 12=>'december',
                    );

                    $count = 0;

                    // start and end time of the event
                    $eventStartTime = \Date::parse($GLOBALS['TL_CONFIG']['timeFormat'], $objEvents->startTime);
                    $eventEndTime = \Date::parse($GLOBALS['TL_CONFIG']['timeFormat'], $objEvents->endTime);

                    // now we have to take care about the exception dates to skip
                    if ($objEvents->useExceptions)
                    {
                        $arrEventSkipInfo[$objEvents->id] = deserialize($objEvents->exceptionList);
                    }

                    // time of the next event
                    $nextTime = $objEvents->endTime;
                    while ($nextTime < $intEnd)
                    {
                        $objEvents->pos_idx++;
                        if ($objEvents->recurrences == 0)
                        {
                            $objEvents->pos_cnt = 0;
                        }
                        else
                        {
                            $objEvents->pos_cnt = (int)$eventRecurrences;
                        }

                        if ($objEvents->recurrences > 0 && $count++ >= $objEvents->recurrences)
                        {
                            break;
                        }

                        $arg = $arrRepeat['value'];
                        $unit = $arrRepeat['unit'];

                        if ($objEvents->recurring)
                        {
                            // this is the contao default
                            $strtotime = '+ ' . $arg . ' ' . $unit;
                            $objEvents->startTime = strtotime($strtotime, $objEvents->startTime);
                            $objEvents->endTime = strtotime($strtotime, $objEvents->endTime);
                        }
                        else
                        {
                            // extended version.
                            $intyear	= date('Y', $objEvents->startTime);
                            $intmonth	= date('n', $objEvents->startTime) + 1;

                            $year = ($intmonth == 13) ? ($intyear + 1) : $intyear;
                            $month = ($intmonth == 13) ? 1 : $intmonth;

                            $strtotime = $arg . ' ' . $unit . ' of ' . $arrMonth[$month] . ' ' . $year;
                            $objEvents->startTime = strtotime($strtotime . ' ' . $eventStartTime, $objEvents->startTime);
                            $objEvents->endTime = strtotime($strtotime . ' ' . $eventEndTime, $objEvents->endTime);
                        }
                        $nextTime = $objEvents->endTime;

                        // check if there is any exception
                        if (is_array($arrEventSkipInfo[$objEvents->id]))
                        {
                            // reset cssClass
                            $objEvents->cssClass = str_replace("exception", "", $objEvents->cssClass);
                            unset($objEvents->moveReason);

                            // date to search for
                            $searchDate = mktime(0, 0, 0, date('m', $objEvents->startTime), date("d", $objEvents->startTime), date("Y", $objEvents->startTime));

                            // store old date values for later reset
                            $oldDate = array();

                            if (is_array($arrEventSkipInfo[$objEvents->id][$searchDate]))
                            {
                                $r = $searchDate;
                                $action = $arrEventSkipInfo[$objEvents->id][$r]['action'];
                                if ($action == "hide")
                                {
                                    //continue the while since we don't want to show the event
                                    continue;
                                }
                                else if ($action == "mark")
                                {
                                    //just add the css class to the event
                                    $objEvents->cssClass .= "exception";
                                }
                                else if ($action == "move")
                                {
                                    //just add the css class to the event
                                    $objEvents->cssClass .= "moved";

                                    // keep old date. we have to reset it later for the next recurrence
                                    $oldDate['startTime'] = $objEvents->startTime;
                                    $oldDate['endTime'] = $objEvents->endTime;

                                    // also keep the old values in the row
                                    $objEvents->oldDate = \Date::parse($GLOBALS['TL_CONFIG']['dateFormat'], $objEvents->startTime);

                                    // value to add to the old date
                                    $newDate = $arrEventSkipInfo[$objEvents->id][$r]['new_exception'];

                                    // store the reason for the move
                                    $objEvents->moveReason = $arrEventSkipInfo[$objEvents->id][$r]['reason'];

                                    // check if we have to change the time of the event
                                    if ($arrEventSkipInfo[$objEvents->id][$r]['new_start'])
                                    {
                                        $objEvents->oldStartTime = \Date::parse($GLOBALS['TL_CONFIG']['timeFormat'], $objEvents->startTime);
                                        $objEvents->oldEndTime = \Date::parse($GLOBALS['TL_CONFIG']['timeFormat'], $objEvents->endTime);

                                        // get the date of the event and add the new time to the new date
                                        $newStart = \Date::parse($GLOBALS['TL_CONFIG']['dateFormat'], $objEvents->startTime)
                                            . ' ' . $arrEventSkipInfo[$objEvents->id][$r]['new_start'];
                                        $newEnd = \Date::parse($GLOBALS['TL_CONFIG']['dateFormat'], $objEvents->endTime)
                                            . ' ' . $arrEventSkipInfo[$objEvents->id][$r]['new_end'];

                                        //set the new values
                                        $objEvents->startTime = strtotime($newDate, strtotime($newStart));
                                        $objEvents->endTime = strtotime($newDate, strtotime($newEnd));
                                    }
                                    else
                                    {
                                        $objEvents->startTime = strtotime($newDate, $objEvents->startTime);
                                        $objEvents->endTime = strtotime($newDate, $objEvents->endTime);
                                    }
                                }
                            }
                        }

                        // Skip events outside the scope
                        if ($objEvents->endTime < $intStart || $objEvents->startTime > $intEnd)
                        {
                            // in case of a move we have to reset the original date
                            if ($oldDate)
                            {
                                $objEvents->startTime = $oldDate['startTime'];
                                $objEvents->endTime = $oldDate['endTime'];
                            }
                            // reset this values...
                            $objEvents->moveReason = NULL;
                            $objEvents->oldDate = NULL;
                            $objEvents->oldStartTime = NULL;
                            $objEvents->oldEndTime = NULL;
                            continue;
                        }

                        // used for cal_noSpan
                        $dateNextStart = date('Ymd', $objEvents->startTime);
                        $dateNextEnd = date('Ymd', $objEvents->endTime);

                        // stop if we have on event and cal_noSpan is true
                        if ($this->cal_noSpan && $cntRecurrences > 0)
                        {
                            break;
                        }

                        $objEvents->isRecurrence = true;

                        $weekday = date('w', $objEvents->startTime);
                        $store = true;
                        if ($objEvents->hideOnWeekend)
                        {
                            if ($weekday == 0 || $weekday == 6)
                            {
                                $store = false;
                            }
                        }
                        if ($store === true)
                        {
                            $eventUrl = $strUrl."?day=".Date("Ymd", $objEvents->startTime)."&amp;times=".$objEvents->startTime.",".$objEvents->endTime;
                            $this->addEvent($objEvents, $objEvents->startTime, $objEvents->endTime, $eventUrl, $intStart, $intEnd, $id);
                        }

                        // reset this values...
                        $objEvents->moveReason = NULL;
                        $objEvents->oldDate = NULL;
                        $objEvents->oldStartTime = NULL;
                        $objEvents->oldEndTime = NULL;

                        // in case of a move we have to reset the original date
                        if ($oldDate)
                        {
                            $objEvents->startTime = $oldDate['startTime'];
                            $objEvents->endTime = $oldDate['endTime'];
                        }

                        // increase $cntRecurrences if event is in scope
                        if ($dateNextStart >= $dateBegin && $dateNextEnd <= $dateEnd)
                        {
                            $cntRecurrences++;
                        }
                    }
                } // end if recurring...
            }
        }

        if ($arrHolidays != null)
        {
            // run thru all holiday calendars
            foreach ($arrHolidays as $id)
            {
                $strUrl = $this->strUrl;

                $objAE = $this->Database->prepare("SELECT allowEvents FROM tl_calendar WHERE id = ?")
                    ->limit(1)->execute($id);
                $allowEvents = ($objAE->allowEvents == 1) ? true : false;

                $strUrl = $this->strUrl;
                $objCalendar = \CalendarModel::findByPk($id);

                // Get the current "jumpTo" page
                if ($objCalendar !== null && $objCalendar->jumpTo && ($objTarget = $objCalendar->getRelated('jumpTo')) !== null)
                {
                    $strUrl = $this->generateFrontendUrl($objTarget->row(), ($GLOBALS['TL_CONFIG']['useAutoItem'] ?  '/%s' : '/events/%s'));
                }

                // Get the events of the current period
                $objEvents = \CalendarEventsModel::findCurrentByPid($id, $intStart, $intEnd);

                if ($objEvents === null)
                {
                    continue;
                }

                while ($objEvents->next())
                {
                    // at last we add the free multi-day / holiday or what ever kind of event
                    $this->addEvent($objEvents, $objEvents->startTime, $objEvents->endTime, $strUrl, $intStart, $intEnd, $id);

                    /**
                     * Multi-day event
                     * first we have to find all free days
                     */
                    $span = Calendar::calculateSpan($objEvents->startTime, $objEvents->endTime);

                    // unset the first day of the multi-day event
                    $intDate = $objEvents->startTime;
                    $key = date('Ymd', $intDate);
                    // check all events if the calendar allows events on free days
                    if ($this->arrEvents[$key])
                    {
                        foreach ($this->arrEvents[$key] as $k1 => $events)
                        {
                            foreach ($events as $k2 => $event)
                            {
                                // do not remove events from any holiday calendar
                                $isHolidayEvent = array_search($event['pid'], $arrHolidays);

                                // unset the event if showOnFreeDay is not set
                                if ($allowEvents === false)
                                {
                                    if ($isHolidayEvent === false)
                                    {
                                        unset($this->arrEvents[$key][$k1][$k2]);
                                    }
                                }
                                else
                                {
                                    if ($isHolidayEvent === false && !$event['showOnFreeDay'] == 1)
                                    {
                                        unset($this->arrEvents[$key][$k1][$k2]);
                                    }
                                }
                            }
                        }
                    }

                    // unset all the other days of the multi-day event
                    for ($i=1; $i<=$span && $intDate<=$intEnd; $i++)
                    {
                        $intDate = strtotime('+ 1 day', $intDate);
                        $key = date('Ymd', $intDate);
                        // check all events if the calendar allows events on free days
                        if ($this->arrEvents[$key])
                        {
                            foreach ($this->arrEvents[$key] as $k1 => $events)
                            {
                                foreach ($events as $k2 => $event)
                                {
                                    // do not remove events from any holiday calendar
                                    $isHolidayEvent = array_search($event['pid'], $arrHolidays);

                                    // unset the event if showOnFreeDay is not set
                                    if ($allowEvents === false)
                                    {
                                        if ($isHolidayEvent === false)
                                        {
                                            unset($this->arrEvents[$key][$k1][$k2]);
                                        }
                                    }
                                    else
                                    {
                                        if ($isHolidayEvent === false && !$event['showOnFreeDay'] == 1)
                                        {
                                            unset($this->arrEvents[$key][$k1][$k2]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Sort the array
        foreach (array_keys($this->arrEvents) as $key)
        {
            ksort($this->arrEvents[$key]);
        }

        // HOOK: modify the result set
        if (isset($GLOBALS['TL_HOOKS']['getAllEvents']) && is_array($GLOBALS['TL_HOOKS']['getAllEvents']))
        {
            foreach ($GLOBALS['TL_HOOKS']['getAllEvents'] as $callback)
            {
                $this->import($callback[0]);
                $this->arrEvents = $this->$callback[0]->$callback[1]($this->arrEvents, $arrCalendars, $intStart, $intEnd, $this);
            }
        }

        return $this->arrEvents;
    }

}
