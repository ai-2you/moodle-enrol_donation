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
 * Donation amount form: asks the student how much they want to donate.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation\form;

use enrol_donation\local\intent;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Donation amount form.
 *
 * Uses a per-instance form identifier (rather than the class name alone) so that a course with
 * several donation instances never confuses one instance's submission with another's.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class donation_amount_form extends \moodleform {
    /**
     * Builds the donation amount field and submit button.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        /** @var \stdClass $instance */
        $instance = $this->_customdata['instance'];

        // Enrol/index.php requires "id" (the course id) on every request, GET or POST -- this
        // preserves it with its real meaning instead of leaving the page without one.
        $mform->addElement('hidden', 'id', $instance->courseid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'instance', $instance->id);
        $mform->setType('instance', PARAM_INT);

        $default = intent::get_suggested($instance) ?? intent::get_minimum($instance);

        $range = (object) [
            'min' => \core_payment\helper::get_cost_as_string(intent::get_minimum($instance), $instance->currency),
            'max' => \core_payment\helper::get_cost_as_string(intent::get_maximum($instance), $instance->currency),
        ];
        $mform->addElement('static', 'amountrange', '', get_string('amountrange', 'enrol_donation', $range));

        $mform->addElement('text', 'amount', get_string('donationamount', 'enrol_donation'), [
            'inputmode' => 'decimal',
            'autocomplete' => 'off',
        ]);
        $mform->setType('amount', PARAM_RAW_TRIMMED);
        $mform->setDefault('amount', number_format($default, 2, '.', ''));
        $mform->addRule('amount', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('amount', 'donationamount', 'enrol_donation');

        $this->add_action_buttons(false, get_string('continue'));
    }

    /**
     * A per-instance identifier so several donation instances on the same course page never
     * cross-detect each other's submissions.
     *
     * @return string
     */
    protected function get_form_identifier() {
        $instance = $this->_customdata['instance'];
        return $instance->id . '_' . str_replace('\\', '_', get_class($this));
    }

    /**
     * Validates the submitted amount against the instance's minimum and maximum.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $instance = $this->_customdata['instance'];

        $amount = intent::parse_amount((string) ($data['amount'] ?? ''));
        if ($amount === null) {
            $errors['amount'] = get_string('invalidamount', 'enrol_donation');
            return $errors;
        }

        $errorcode = intent::validate_amount($amount, $instance);
        if ($errorcode !== null) {
            $errors['amount'] = get_string($errorcode, 'enrol_donation');
        }

        return $errors;
    }
}
