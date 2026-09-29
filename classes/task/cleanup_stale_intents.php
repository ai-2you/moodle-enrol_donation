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
 * Scheduled task that deletes stale, never-paid donation intents.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\task;

use enrol_donation\local\intent;

/**
 * Daily cleanup of never-paid intents. The site's configured retention can only raise the floor,
 * never lower it below 30 days (SEPA chargebacks can land up to ~20 days after payment).
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_stale_intents extends \core\task\scheduled_task {
    /** @var int Minimum retention in days, regardless of site configuration. */
    const MINIMUM_RETENTION_DAYS = 30;

    /**
     * Returns the task's name shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'enrol_donation');
    }

    /**
     * Deletes never-paid donation intents older than the effective retention period.
     *
     * @return void
     */
    public function execute() {
        $configured = (int) get_config('enrol_donation', 'intentretention');
        $retentiondays = max($configured, self::MINIMUM_RETENTION_DAYS);

        intent::delete_stale(time() - ($retentiondays * DAYSECS));
    }
}
