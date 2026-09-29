<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task that retries delivery of paid-but-undelivered donations.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\task;

use enrol_donation\local\delivery;
use enrol_donation\local\intent;

/**
 * Retries delivery every 15 minutes for any payment more than 10 minutes old that is still
 * undelivered, with no upper age limit. A failure on one intent never stops the others. Overdue
 * (>24h) or unidentified-payer cases trigger an admin alert, throttled to once per 24h.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class redeliver_paid_donations extends \core\task\scheduled_task {
    /**
     * Returns the task's name shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskredeliver', 'enrol_donation');
    }

    /**
     * Retries delivery of paid-but-undelivered donations and alerts admins of overdue cases.
     *
     * @return void
     */
    public function execute() {
        $rows = delivery::get_paid_undelivered(time() - (10 * MINSECS));

        $overdue = [];
        $unidentified = [];

        foreach ($rows as $row) {
            $isoverdue = $row->paidtime < (time() - DAYSECS);

            try {
                $record = intent::get((int) $row->intentid);
                $delivered = delivery::deliver($record, (int) $row->payerid);
                if (!$delivered) {
                    $unidentified[] = $row;
                }
            } catch (\Throwable $e) {
                // Deliberately silent: a failure on one intent must never spam cron output on every
                // 15-minute retry, and visibility for admins comes from send_alerts() below (once the
                // failure is overdue), not from the task log.
                if ($isoverdue) {
                    $overdue[] = $row;
                }
            }
        }

        $lastalert = (int) get_config('enrol_donation', 'lastalert');
        if (($overdue || $unidentified) && (time() - $lastalert > DAYSECS)) {
            delivery::send_alerts($overdue, $unidentified);
            set_config('lastalert', time(), 'enrol_donation');
        }
    }
}
