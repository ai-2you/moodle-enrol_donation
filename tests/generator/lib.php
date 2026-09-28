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
 * Donation enrolment plugin test data generator.
 *
 * @package    enrol_donation
 * @category   test
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_donation_generator extends component_generator_base {

    /** @var int Counter used to keep generated instance names unique. */
    protected $instancecounter = 0;

    /**
     * Creates a bare 'enrol' table row for enrol_donation, without depending on
     * \enrol_donation\classes\plugin (not implemented yet at this stage of the pipeline).
     *
     * @param array $record overrides for the enrol instance fields (courseid, cost, currency,
     *   customchar1 = suggested, customchar2 = maximum, customchar3 = support contact, customint1 =
     *   payment account id, roleid, status, enrolperiod, enrolstartdate, enrolenddate).
     * @return stdClass the created enrol instance record.
     */
    public function create_instance(array $record = []): stdClass {
        global $DB;

        if (empty($record['courseid'])) {
            $course = $this->datagenerator->create_course();
            $record['courseid'] = $course->id;
        }

        $this->instancecounter++;

        $studentrole = $DB->get_record('role', ['shortname' => 'student']);

        $instance = (object) array_merge([
            'enrol' => 'donation',
            'status' => ENROL_INSTANCE_ENABLED,
            'sortorder' => 0,
            'name' => 'Donation instance ' . $this->instancecounter,
            'roleid' => $studentrole ? (int) $studentrole->id : 0,
            'enrolperiod' => 0,
            'enrolstartdate' => 0,
            'enrolenddate' => 0,
            'currency' => 'EUR',
            'cost' => '5.00',
            'customchar1' => null,
            'customchar2' => '500.00',
            'customchar3' => null,
            'customint1' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $record);

        $instance->id = $DB->insert_record('enrol', $instance);

        return $instance;
    }

    /**
     * Creates a donation intent row directly against {enrol_donation_intent}. Requires the table
     * to exist (fase 1 install.xml); until then this fails with a "table does not exist" error,
     * which is the expected red state for this pipeline step.
     *
     * @param array $record overrides (instanceid, amount, currency, timecreated, timedelivered).
     * @return stdClass the created intent record.
     */
    public function create_intent(array $record = []): stdClass {
        global $DB;

        if (empty($record['instanceid'])) {
            $instance = $this->create_instance();
            $record['instanceid'] = $instance->id;
        }

        $intent = (object) array_merge([
            'amount' => 10.00,
            'currency' => 'EUR',
            'timecreated' => time(),
            'timedelivered' => 0,
        ], $record);

        $intent->id = $DB->insert_record('enrol_donation_intent', $intent);

        return $intent;
    }

    /**
     * Creates a {payments} row for the given intent, mirroring what core_payment would insert
     * after a real gateway callback (component = enrol_donation, paymentarea = donation,
     * itemid = intent id).
     *
     * @param stdClass $intent intent returned by create_intent().
     * @param int $userid the payer.
     * @param array $record overrides for the payment record (accountid, amount, currency,
     *   gateway, timecreated).
     * @return int the created payment id.
     */
    public function create_payment_for_intent(stdClass $intent, int $userid, array $record = []): int {
        /** @var core_payment_generator $paymentgenerator */
        $paymentgenerator = $this->datagenerator->get_plugin_generator('core_payment');

        if (empty($record['accountid'])) {
            $account = $paymentgenerator->create_payment_account(['gateways' => 'paypal']);
            $record['accountid'] = $account->get('id');
        }

        return $paymentgenerator->create_payment($record + [
            'component' => 'enrol_donation',
            'paymentarea' => 'donation',
            'itemid' => $intent->id,
            'userid' => $userid,
            'amount' => $intent->amount,
            'currency' => $intent->currency ?? 'EUR',
            'gateway' => 'paypal',
        ]);
    }
}
