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
 * Site-level settings for the donation enrolment plugin.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configselect(
        'enrol_donation/status',
        get_string('status', 'enrol_donation'),
        '',
        ENROL_INSTANCE_DISABLED,
        [
            ENROL_INSTANCE_ENABLED => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no'),
        ]
    ));

    $settings->add(new admin_setting_configduration(
        'enrol_donation/enrolperiod',
        get_string('enrolperiod', 'enrol_donation'),
        get_string('enrolperiod_help', 'enrol_donation'),
        0
    ));

    if (!during_initial_install()) {
        $settings->add(new admin_setting_configselect(
            'enrol_donation/roleid',
            get_string('assignrole', 'enrol_donation'),
            '',
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            get_default_enrol_roles(context_system::instance())
        ));
    }

    $settings->add(new admin_setting_configtext(
        'enrol_donation/intentretention',
        get_string('intentretention', 'enrol_donation'),
        get_string('intentretention_desc', 'enrol_donation'),
        \enrol_donation\task\cleanup_stale_intents::MINIMUM_RETENTION_DAYS,
        PARAM_INT
    ));
}
