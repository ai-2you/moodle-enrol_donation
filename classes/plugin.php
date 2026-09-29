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
 * Donation enrolment plugin.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Donation enrolment plugin implementation.
 *
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_donation_plugin extends enrol_plugin {
    /**
     * Returns the list of currencies that the payment subsystem supports.
     *
     * @return array[currencycode => currencyname]
     */
    public function get_possible_currencies(): array {
        $codes = \core_payment\helper::get_supported_currencies();

        $currencies = [];
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }

        uasort($currencies, function ($a, $b) {
            return strcmp($a, $b);
        });

        return $currencies;
    }

    /**
     * Returns optional enrolment information icons for the course listing.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        $found = false;
        foreach ($instances as $inst) {
            if ($inst->enrolstartdate != 0 && $inst->enrolstartdate > time()) {
                continue;
            }
            if ($inst->enrolenddate != 0 && $inst->enrolenddate < time()) {
                continue;
            }
            $found = true;
            break;
        }
        if ($found) {
            return [new pix_icon('icon', get_string('pluginname', 'enrol_donation'), 'enrol_donation')];
        }
        return [];
    }

    /**
     * Allows administrators and teachers to freely change the role assigned by this method.
     *
     * @return bool
     */
    public function roles_protected() {
        return false;
    }

    /**
     * Allows users with the unenrol capability to manually unenrol anyone enrolled through this method.
     *
     * @param stdClass $instance course enrol instance
     * @return bool
     */
    public function allow_unenrol(stdClass $instance) {
        return true;
    }

    /**
     * Allows users with the manage capability to edit enrolment period and status for this method.
     *
     * @param stdClass $instance course enrol instance
     * @return bool
     */
    public function allow_manage(stdClass $instance) {
        return true;
    }

    /**
     * Shows the self-enrolment link only while the donation enrolment instance is enabled.
     *
     * @param stdClass $instance course enrol instance
     * @return bool
     */
    public function show_enrolme_link(stdClass $instance) {
        return ($instance->status == ENROL_INSTANCE_ENABLED);
    }

    /**
     * Allows adding a donation instance only when a currency and the config capability are available.
     *
     * @param int $courseid
     * @return bool
     */
    public function can_add_instance($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (empty(\core_payment\helper::get_supported_currencies())) {
            return false;
        }

        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/donation:config', $context)) {
            return false;
        }

        return true;
    }

    /**
     * Uses Moodle's standard enrolment method editing UI for this plugin's instance form.
     *
     * @return bool
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * Normalises the amount fields before creating a new donation enrolment instance.
     *
     * @param object $course
     * @param array|null $fields
     * @return int id of new instance
     */
    public function add_instance($course, ?array $fields = null) {
        if ($fields) {
            $fields = $this->normalise_amount_fields($fields);
        }
        return parent::add_instance($course, $fields);
    }

    /**
     * Normalises the amount fields before updating an existing donation enrolment instance.
     *
     * @param stdClass $instance
     * @param stdClass $data
     * @return bool
     */
    public function update_instance($instance, $data) {
        $context = context_course::instance($instance->courseid);
        require_capability('enrol/donation:config', $context);

        if ($data) {
            $data = (object) $this->normalise_amount_fields((array) $data);
        }

        return parent::update_instance($instance, $data);
    }

    /**
     * Normalises the raw, possibly locale-formatted, amount fields of an instance into a
     * canonical dot-decimal string before they are written to the DB.
     *
     * @param array $fields
     * @return array
     */
    protected function normalise_amount_fields(array $fields): array {
        foreach (['cost', 'customchar1', 'customchar2'] as $field) {
            if (!array_key_exists($field, $fields) || $fields[$field] === '' || $fields[$field] === null) {
                continue;
            }
            $parsed = \enrol_donation\local\intent::parse_amount((string) $fields[$field]);
            if ($parsed !== null) {
                $fields[$field] = number_format($parsed, 2, '.', '');
            }
        }
        return $fields;
    }

    /**
     * Creates course enrol form, checks if it was submitted and renders the resulting page.
     *
     * @param stdClass $instance
     * @return string html text
     */
    public function enrol_page_hook(stdClass $instance) {
        global $USER, $OUTPUT, $DB;

        if (!isloggedin() || isguestuser()) {
            return $this->show_login_info();
        }

        if ($DB->record_exists('user_enrolments', ['userid' => $USER->id, 'enrolid' => $instance->id])) {
            return '';
        }

        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
            return '';
        }
        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
            return '';
        }

        $course = get_course($instance->courseid);
        $context = context_course::instance($course->id);

        // Self-healing: a previous payment for this user exists but was never delivered.
        $pending = \enrol_donation\local\delivery::get_undelivered_for_user($instance->id, $USER->id);
        if ($pending) {
            return $this->render_pending_payment(reset($pending), $instance);
        }

        $form = new \enrol_donation\form\donation_amount_form(null, ['instance' => $instance]);

        if ((int) optional_param('instance', 0, PARAM_INT) === (int) $instance->id && ($data = $form->get_data())) {
            $amount = \enrol_donation\local\intent::parse_amount((string) $data->amount);
            $errorcode = $amount !== null ? \enrol_donation\local\intent::validate_amount($amount, $instance) : 'invalidamount';

            if ($amount !== null && $errorcode === null) {
                try {
                    $donationintent = \enrol_donation\local\intent::create_or_reuse($instance, $amount);
                    return $this->render_payment_summary($instance, $course, $context, $donationintent);
                } catch (moodle_exception $e) {
                    // Per-session soft limit reached: fall through and show the form again below.
                    unset($e);
                }
            }
        }

        return $OUTPUT->box($form->render());
    }

    /**
     * Renders the guest/logged-out message instead of the donation form.
     *
     * @return string
     */
    protected function show_login_info(): string {
        global $OUTPUT;

        $loginurl = new moodle_url('/login/index.php');

        return $OUTPUT->box(
            html_writer::tag('p', get_string('mustlogin', 'enrol_donation')) .
            html_writer::link($loginurl, get_string('loginsite'))
        );
    }

    /**
     * Attempts to self-heal a paid-but-undelivered donation, then renders the outcome.
     *
     * @param stdClass $donationintent
     * @param stdClass $instance
     * @return string
     */
    protected function render_pending_payment(stdClass $donationintent, stdClass $instance): string {
        global $OUTPUT, $CFG, $USER;

        try {
            $delivered = \enrol_donation\local\delivery::deliver($donationintent, $USER->id);
        } catch (Throwable $e) {
            $delivered = false;
        }

        if ($delivered) {
            $courseurl = new moodle_url('/course/view.php', ['id' => $instance->courseid]);
            return $OUTPUT->box(
                html_writer::tag('p', get_string('alreadyenrolled', 'enrol_donation')) .
                html_writer::link($courseurl, get_string('gotocourse', 'enrol_donation'))
            );
        }

        $contact = !empty($instance->customchar3) ? $instance->customchar3 : $CFG->supportemail;

        return $OUTPUT->box(html_writer::tag('p', get_string('deliverypending', 'enrol_donation', s($contact))));
    }

    /**
     * Renders the payment summary and "pay now" button for a freshly created/reused intent.
     *
     * @param stdClass $instance
     * @param stdClass $course
     * @param context $context
     * @param stdClass $donationintent
     * @return string
     */
    protected function render_payment_summary(
        stdClass $instance,
        stdClass $course,
        context $context,
        stdClass $donationintent
    ): string {
        global $OUTPUT;

        $changeurl = new moodle_url('/enrol/index.php', ['id' => $course->id]);

        $data = [
            'amount' => \core_payment\helper::get_cost_as_string((float) $donationintent->amount, $donationintent->currency),
            'description' => get_string(
                'purchasedescription',
                'enrol_donation',
                format_string($course->fullname, true, ['context' => $context])
            ),
            'component' => 'enrol_donation',
            'paymentarea' => 'donation',
            'itemid' => $donationintent->id,
            'successurl' => \enrol_donation\payment\service_provider::get_success_url('donation', $donationintent->id)->out(false),
            'changeamounturl' => $changeurl->out(false),
        ];

        return $OUTPUT->box($OUTPUT->render_from_template('enrol_donation/payment_region', $data));
    }

    /**
     * Restore instance and map settings, clamping roleid to what the restoring user can assign.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;

        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = [
                'courseid' => $data->courseid,
                'enrol' => $this->get_name(),
                'roleid' => $data->roleid,
                'cost' => $data->cost,
                'currency' => $data->currency,
            ];
        }

        if ($merge && $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            $context = context_course::instance($data->courseid);
            $assignable = get_assignable_roles($context);

            if (!array_key_exists($data->roleid, $assignable)) {
                $fallback = (int) $this->get_config('roleid');
                if (!$fallback || !array_key_exists($fallback, $assignable)) {
                    $fallback = (int) $DB->get_field('role', 'id', ['archetype' => 'student']);
                }
                if (!$fallback || !array_key_exists($fallback, $assignable)) {
                    mtrace('enrol_donation: cannot restore instance, no assignable role for the restoring user');
                    return;
                }
                $data->roleid = $fallback;
            }

            $instanceid = $this->add_instance($course, (array) $data);
        }

        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Re-enrols a restored user with the same start/end dates and status they had at backup time.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $userid
     * @param int $oldinstancestatus
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Deletes the enrol instance, its intents (paid or not: this is the only path allowed to
     * remove an intent that has a {payments} row) and unenrols everyone.
     *
     * Re-fetches the full 'enrol' row before delegating to the base class: callers (including our
     * own generator/forms) may only hold a partial instance object, and
     * core\event\enrol_instance_deleted::create_from_record() emits a debugging() notice if its
     * snapshot is missing columns.
     *
     * @param stdClass $instance
     */
    public function delete_instance($instance) {
        global $DB;

        $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
        \enrol_donation\local\intent::delete_for_instance($instance->id);
        parent::delete_instance($instance);
    }

    /**
     * Returns the enabled/disabled options offered for this method's status field.
     *
     * @return array
     */
    protected function get_status_options() {
        return [
            ENROL_INSTANCE_ENABLED => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no'),
        ];
    }

    /**
     * Roles that the current editing/restoring user may pick: the site's default enrol roles for
     * this context, intersected with what that user is actually allowed to assign.
     *
     * @param stdClass $instance
     * @param context $context
     * @return array
     */
    protected function get_roleid_options($instance, $context) {
        if (!empty($instance->id)) {
            $defaultroles = get_default_enrol_roles($context, $instance->roleid);
        } else {
            $defaultroles = get_default_enrol_roles($context, $this->get_config('roleid'));
        }

        $assignable = get_assignable_roles($context);

        return array_intersect_key($defaultroles, $assignable);
    }

    /**
     * Allows deleting a donation instance only for users with the config capability.
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/donation:config', $context);
    }

    /**
     * Allows hiding/showing a donation instance only for users with the config capability.
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/donation:config', $context);
    }

    /**
     * Builds the donation instance editing form fields.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->setType('name', PARAM_TEXT);

        $options = $this->get_status_options();
        $mform->addElement('select', 'status', get_string('status', 'enrol_donation'), $options);
        $mform->setDefault('status', $this->get_config('status', ENROL_INSTANCE_DISABLED));

        $accounts = \core_payment\helper::get_payment_accounts_menu($context);
        if ($accounts) {
            $accounts = ((count($accounts) > 1) ? ['' => ''] : []) + $accounts;
            $mform->addElement('select', 'customint1', get_string('paymentaccount', 'payment'), $accounts);
        } else {
            $mform->addElement(
                'static',
                'customint1_text',
                get_string('paymentaccount', 'payment'),
                html_writer::span(get_string('noaccountsavilable', 'payment'), 'alert alert-danger')
            );
            $mform->addElement('hidden', 'customint1');
            $mform->setType('customint1', PARAM_INT);
        }
        $mform->addHelpButton('customint1', 'paymentaccount', 'enrol_donation');

        $mform->addElement('text', 'cost', get_string('minimumdonation', 'enrol_donation'), ['size' => 6]);
        $mform->setType('cost', PARAM_RAW);
        $mform->addHelpButton('cost', 'minimumdonation', 'enrol_donation');

        $mform->addElement('text', 'customchar1', get_string('suggesteddonation', 'enrol_donation'), ['size' => 6]);
        $mform->setType('customchar1', PARAM_RAW);
        $mform->addHelpButton('customchar1', 'suggesteddonation', 'enrol_donation');

        $mform->addElement('text', 'customchar2', get_string('maximumdonation', 'enrol_donation'), ['size' => 6]);
        $mform->setType('customchar2', PARAM_RAW);
        $mform->addHelpButton('customchar2', 'maximumdonation', 'enrol_donation');

        $mform->addElement('text', 'customchar3', get_string('supportcontact', 'enrol_donation'));
        $mform->setType('customchar3', PARAM_NOTAGS);
        $mform->addHelpButton('customchar3', 'supportcontact', 'enrol_donation');

        $supportedcurrencies = $this->get_possible_currencies();
        $mform->addElement('select', 'currency', get_string('currency', 'enrol_donation'), $supportedcurrencies);

        $roles = $this->get_roleid_options($instance, $context);
        $mform->addElement('select', 'roleid', get_string('assignrole', 'enrol_donation'), $roles);
        $mform->setDefault('roleid', $this->get_config('roleid'));

        $options = ['optional' => true, 'defaultunit' => 86400];
        $mform->addElement('duration', 'enrolperiod', get_string('enrolperiod', 'enrol_donation'), $options);
        $mform->addHelpButton('enrolperiod', 'enrolperiod', 'enrol_donation');

        $options = ['optional' => true];
        $mform->addElement('date_time_selector', 'enrolstartdate', get_string('enrolstartdate', 'enrol_donation'), $options);
        $mform->addHelpButton('enrolstartdate', 'enrolstartdate', 'enrol_donation');

        $mform->addElement('date_time_selector', 'enrolenddate', get_string('enrolenddate', 'enrol_donation'), $options);
        $mform->addHelpButton('enrolenddate', 'enrolenddate', 'enrol_donation');

        if (enrol_accessing_via_instance($instance)) {
            $warningtext = get_string('instanceeditselfwarningtext', 'core_enrol');
            $mform->addElement('static', 'selfwarn', get_string('instanceeditselfwarning', 'core_enrol'), $warningtext);
        }
    }

    /**
     * Validates the donation instance editing form, including the amount and payment account fields.
     *
     * @param array $data
     * @param array $files
     * @param object $instance
     * @param context $context
     * @return array
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = [];

        if (!empty($data['enrolenddate']) && !empty($data['enrolstartdate']) && $data['enrolenddate'] < $data['enrolstartdate']) {
            $errors['enrolenddate'] = get_string('enrolenddaterror', 'enrol_donation');
        }

        $minimum = \enrol_donation\local\intent::parse_amount((string) $data['cost']);
        if ($minimum === null || $minimum <= 0) {
            $errors['cost'] = get_string('costerror', 'enrol_donation');
        }

        $maximum = null;
        if ($data['customchar2'] === '' || $data['customchar2'] === null) {
            $errors['customchar2'] = get_string('maximumrequired', 'enrol_donation');
        } else {
            $maximum = \enrol_donation\local\intent::parse_amount((string) $data['customchar2']);
            if ($maximum === null) {
                $errors['customchar2'] = get_string('maximumerror', 'enrol_donation');
            } else if ($minimum !== null && $maximum < $minimum) {
                $errors['customchar2'] = get_string('maximumbelowminimum', 'enrol_donation');
            } else if ($maximum > \enrol_donation\local\intent::HARD_MAX) {
                $errors['customchar2'] = get_string('maximumtoohigh', 'enrol_donation');
            }
        }

        if (!empty($data['customchar1'])) {
            $suggested = \enrol_donation\local\intent::parse_amount((string) $data['customchar1']);
            if (
                $suggested === null
                    || ($minimum !== null && $suggested < $minimum)
                    || ($maximum !== null && $suggested > $maximum)
            ) {
                $errors['customchar1'] = get_string('suggestedoutsiderange', 'enrol_donation');
            }
        }

        $validstatus = array_keys($this->get_status_options());
        $validcurrency = array_keys($this->get_possible_currencies());
        $validroles = array_keys($this->get_roleid_options($instance, $context));
        $tovalidate = [
            'name' => PARAM_TEXT,
            'status' => $validstatus,
            'currency' => $validcurrency,
            'roleid' => $validroles,
            'enrolperiod' => PARAM_INT,
            'enrolstartdate' => PARAM_INT,
            'enrolenddate' => PARAM_INT,
        ];

        $errors = array_merge($errors, $this->validate_param_types($data, $tovalidate));

        if (
            (int) $data['status'] === ENROL_INSTANCE_ENABLED
                && (empty($data['customint1'])
                    || !array_key_exists($data['customint1'], \core_payment\helper::get_payment_accounts_menu($context)))
        ) {
            $errors['customint1'] = get_string('nopaymentaccount', 'enrol_donation');
        }

        return $errors;
    }

    /**
     * Expires overdue donation enrolments, as scheduled by the core enrol cron.
     *
     * @param progress_trace $trace
     * @return int
     */
    public function sync(progress_trace $trace) {
        $this->process_expirations($trace);
        return 0;
    }
}
